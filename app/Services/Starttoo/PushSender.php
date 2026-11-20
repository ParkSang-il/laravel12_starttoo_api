<?php

namespace App\Services\Starttoo;

use App\Jobs\SendStarttooPush;
use App\Services\ProjectDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Str;

/** 기존 database/chat-push 큐가 STARTTOO 전용 DB 문맥에서 호출한다. */
class PushSender
{
    private function db() { return app(ProjectDatabase::class)->database(); }

    public function expand(?int $outboxId = null): void
    {
        if (!config('starttoo.push_enabled')) return;
        $db = $this->db();
        $query = $db->table('starttoo_push_outbox');
        if ($outboxId !== null) $query->where('id', $outboxId);
        else $query->whereNull('expanded_at');
        foreach ($query->orderBy('id')->limit(100)->get() as $box) {
            $db->transaction(function () use ($db, $box) {
                $locked = $db->table('starttoo_push_outbox')->where('id', $box->id)->lockForUpdate()->first();
                if (!$locked || $locked->expanded_at !== null) return;
                $n = $db->table('starttoo_notifications')->where('id', $box->notification_id)->first();
                if ($n && $n->created_at >= now()->subDay()->toDateTimeString()) {
                    $devices = $db->table('starttoo_push_devices as d')->join('starttoo_auth_sessions as s', 's.id', '=', 'd.session_id')
                        ->where('d.user_id', $n->user_id)->where('s.created_at', '<=', $n->created_at)->get(['d.*']);
                    foreach ($devices as $device) $db->table('starttoo_push_deliveries')->insertOrIgnore([
                        'outbox_id' => $box->id, 'device_id' => $device->id, 'session_id' => $device->session_id,
                        'token_hash' => $device->token_hash, 'available_at' => now(),
                    ]);
                }
                $db->table('starttoo_push_outbox')->where('id', $box->id)->update(['expanded_at' => now()]);
            }, 3);
            // 한 작업에서 한 기기에만 전송해 기존 worker의 30초 제한을 지킨다.
            foreach ($db->table('starttoo_push_deliveries')->where('outbox_id', $box->id)->where('state', 'pending')->pluck('id') as $id) {
                SendStarttooPush::dispatch((int) $id);
            }
        }
    }

    /** 큐 입력 실패나 강제 종료로 남은 발송함을 기존 큐에 다시 넣는다. */
    public function recover(): void
    {
        $db = $this->db();
        foreach ($db->table('starttoo_notification_fanouts')->where('complete', false)->orderBy('id')->limit(100)->pluck('id') as $id) {
            \App\Jobs\FanoutStarttooPost::dispatch((int) $id);
        }
        if (!config('starttoo.push_enabled')) return;
        $ids = $db->table('starttoo_push_deliveries')->where('available_at', '<=', now())->where(function ($q) {
            $q->where('state', 'pending')->orWhere(fn ($lease) => $lease->where('state', 'sending')->where('leased_until', '<', now()));
        })->orderBy('id')->limit(100)->pluck('id');
        $this->expand();
        foreach ($ids as $id) SendStarttooPush::dispatch((int) $id);
        $db->table('starttoo_push_outbox')->where('created_at', '<', now()->subDays(7))->delete();
    }

    /** 반환값이 있으면 해당 초 이후 같은 기기 작업을 다시 큐에 넣는다. */
    public function deliver(int $id): ?int
    {
        if (!config('starttoo.push_enabled')) return null;
        $db = $this->db();
        $key = (string) Str::uuid();
        $job = $db->transaction(function () use ($db, $id, $key) {
            $job = $db->table('starttoo_push_deliveries')->where('id', $id)->lockForUpdate()->first();
            if (!$job || $job->available_at > now()->toDateTimeString()
                || ($job->state !== 'pending' && !($job->state === 'sending' && $job->leased_until < now()->toDateTimeString()))) return null;
            if ($job->attempts >= 8) {
                $db->table('starttoo_push_deliveries')->where('id', $id)->update(['state' => 'failed', 'lease_key' => null, 'leased_until' => null]);
                return null;
            }
            $db->table('starttoo_push_deliveries')->where('id', $id)->update(['state' => 'sending', 'attempts' => $job->attempts + 1, 'lease_key' => $key, 'leased_until' => now()->addMinutes(2)]);
            return $job;
        }, 3);
        if (!$job) {
            $current = $db->table('starttoo_push_deliveries')->where('id', $id)->first();
            if (!$current || !in_array($current->state, ['pending', 'sending'], true)) return null;
            $at = $current->state === 'sending' ? $current->leased_until : $current->available_at;
            return max(5, (int) now()->diffInSeconds(Carbon::parse($at), false) + 1);
        }
        $audit = app(PushAudit::class);
        $boxForLog = $db->table('starttoo_push_outbox')->where('id', $job->outbox_id)->first();
        $deviceForLog = $db->table('starttoo_push_devices')->where('id', $job->device_id)->first();
        $log = $audit->begin('notification', (string) ($boxForLog->notification_id ?? $job->outbox_id), (int) $id,
            isset($deviceForLog->user_id) ? (int) $deviceForLog->user_id : null, (int) $job->device_id,
            $deviceForLog->platform ?? null, (int) $job->attempts + 1);
        try {
            $device = $db->table('starttoo_push_devices as d')->join('starttoo_auth_sessions as s', 's.id', '=', 'd.session_id')
                ->join('starttoo_users as u', 'u.id', '=', 'd.user_id')
                ->where('d.id', $job->device_id)->where('d.session_id', $job->session_id)->where('d.token_hash', $job->token_hash)
                ->whereColumn('s.user_id', 'd.user_id')->whereNull('s.revoked_at')->where('s.idle_expires_at', '>', now())->where('s.absolute_expires_at', '>', now())
                ->where('u.status', 'active')->where('u.moderation_hidden', false)->first(['d.*']);
            $box = $db->table('starttoo_push_outbox')->where('id', $job->outbox_id)->first();
            $n = $device && $box ? app(Notifications::class)->delivery((int) $device->user_id, (int) $box->notification_id) : null;
            $result = ['state' => 'skipped', 'code' => 'RECIPIENT_OR_NOTIFICATION_UNAVAILABLE'];
            // 안내 알림(심사 결과)은 소셜 알림 설정과 관계없이 보낸다.
            $notice = $n && in_array($n->kind, Notifications::NOTICE_KINDS, true);
            $eligible = $n && $n->created_at >= now()->subDay()->toDateTimeString()
                && ($notice || app(UserPreferences::class)->get((int) $device->user_id)['values']['social']);
            if ($eligible) {
                $body = match ($n->kind) {
                    'like' => '회원님의 게시물에 새 좋아요가 있어요.',
                    'comment' => '회원님의 게시물에 새 댓글이 있어요.',
                    'reply' => '회원님의 댓글에 새 답글이 있어요.',
                    'follow' => '새로운 팔로워가 있어요.',
                    'comment_like' => '회원님의 댓글에 새 좋아요가 있어요.',
                    'new_post' => '팔로우한 회원이 새 게시물을 올렸어요.',
                    'artist_review' => '아티스트 신청 심사 소식이 있어요.',
                };
                // 이력에는 본문 원문 대신 안내 문구만 남긴다.
                $db->table('starttoo_push_logs')->where('id', $log)->update(['kind' => $n->kind, 'title_snapshot' => '스타투', 'body_snapshot' => $body]);
                $title = '스타투';
                $images = [];
                if ($n->kind === 'new_post' && ($preview = $this->newPostPreview($n))) {
                    [$title, $body, $images] = $preview;
                } elseif ($notice) {
                    [$title, $body] = $this->noticeText($n);
                } elseif ($decorated = $this->socialText($n)) {
                    [$title, $body] = $decorated;
                }
                $data = ['notification_id' => (string) $n->id, 'recipient_id' => (string) $device->user_id];
                $tag = 'starttoo:'.$n->id;
                $apnsHeaders = ['apns-push-type' => 'alert', 'apns-priority' => '10', 'apns-collapse-id' => $tag];
                if ($images && $device->platform === 'android') {
                    // 사진 알림은 앱이 직접 그린다: 접힌 상태 오른쪽 작은 썸네일, 펼치면 작은 사진 최대 3장.
                    $message = [
                        'data' => $data + ['style' => 'gallery', 'title' => $title, 'body' => $body,
                            'images' => json_encode($images, JSON_UNESCAPED_SLASHES), 'tag' => $tag],
                        'android' => ['priority' => 'high', 'ttl' => '3600s'],
                    ];
                } elseif ($images) {
                    // iOS: Service Extension이 images의 사진을 내려받아 붙이고(썸네일),
                    // Content Extension(category STARTTOO_GALLERY)이 펼친 화면에 최대 3장을 보여 준다.
                    $message = [
                        'data' => $data + ['images' => json_encode($images, JSON_UNESCAPED_SLASHES)],
                        'apns' => ['headers' => $apnsHeaders, 'payload' => ['aps' => [
                            'alert' => ['title' => $title, 'body' => $body], 'sound' => 'default',
                            'mutable-content' => 1, 'category' => 'STARTTOO_GALLERY',
                        ]]],
                    ];
                } else {
                    $message = [
                        'notification' => ['title' => $title, 'body' => $body],
                        'data' => $data,
                        'android' => ['priority' => 'high', 'ttl' => '3600s', 'notification' => ['channel_id' => 'starttoo_social', 'tag' => $tag, 'icon' => 'ic_stat_starttoo']],
                        'apns' => ['headers' => $apnsHeaders, 'payload' => ['aps' => ['sound' => 'default']]],
                    ];
                }
                $result = app('starttoo.fcm')->send(Crypt::decryptString($device->token_ciphertext), $message);
            }
            if ($result['state'] === 'invalid' && $device) {
                $db->table('starttoo_push_devices')->where('id', $device->id)->where('token_hash', $job->token_hash)->delete();
            }
            $audit->finish($log, $result);
            $retry = $eligible && in_array($result['state'], ['retry', 'skipped'], true) && $job->attempts < 7;
            $delay = max((int) ($result['delay'] ?? 60), min(3600, 60 * (2 ** min(6, $job->attempts))));
            $db->table('starttoo_push_deliveries')->where('id', $id)->where('lease_key', $key)->update([
                'state' => $retry ? 'pending' : ($result['state'] === 'sent' ? 'sent' : ($result['state'] === 'skipped' ? 'skipped' : 'failed')),
                'available_at' => now()->addSeconds($delay), 'lease_key' => null, 'leased_until' => null,
            ]);
            return $retry ? $delay : null;
        } catch (\Throwable $e) {
            $audit->finish($log, ['state' => 'exception', 'code' => 'WORKER_EXCEPTION']);
            $db->table('starttoo_push_deliveries')->where('id', $id)->where('lease_key', $key)->update([
                'state' => 'pending', 'available_at' => now()->addMinute(), 'lease_key' => null, 'leased_until' => null,
            ]);
            throw $e;
        }
    }

    /**
     * 팔로우한 회원의 새 게시물 알림: 제목에 작성자, 본문에 내용 앞부분, 사진 최대 3장.
     * 열람 권한은 delivery()에서 이미 확인했다. 내용이 없으면 기본 안내 문구를 쓴다.
     */
    private function newPostPreview(object $n): ?array
    {
        $db = $this->db();
        if ($n->target_type === 'artwork') {
            $row = $db->table('starttoo_artworks')->where('id', $n->target_id)->first(['title', 'description']);
            $text = $row ? trim(($row->description ?? '') !== '' ? $row->description : ($row->title ?? '')) : '';
            $paths = $db->table('starttoo_artwork_images')->where('artwork_id', $n->target_id)->orderBy('position')->limit(3)->pluck('path')->all();
        } elseif ($n->target_type === 'post') {
            $text = trim((string) $db->table('starttoo_posts')->where('id', $n->target_id)->value('content'));
            $paths = $db->table('starttoo_post_images')->where('post_id', $n->target_id)->orderBy('position')->limit(3)->pluck('path')->all();
        } else {
            return null;
        }
        $actor = $db->table('starttoo_users')->where('id', $n->actor_id)->first(['nickname', 'display_name']);
        $name = $actor ? ($actor->nickname ?: $actor->display_name) : null;
        $text = $this->multiline($text);
        $body = $text === '' ? '새 게시물을 올렸어요.' : Str::limit($text, 120, '…');
        $title = $name ? '✨ '.Str::limit($name, 30, '…').'님의 새 게시물' : '✨ 새 게시물';
        // 사진은 최대 3장. 주소를 만들지 못한 사진은 빼고 보낸다.
        $images = array_values(array_filter(array_map(fn ($path) => $this->pushImageUrl($path), $paths)));
        return [$title, $body, $images];
    }

    /**
     * 알림 사진 주소. FCM·APNs는 https만 받는다.
     * CDN이 https가 아니면(현재 gcdn은 http 전용) Object Storage의 https 서명 주소를 쓴다.
     * 서명 주소는 늦게 도착하는 알림도 열 수 있게 6시간 유지한다.
     */
    private function pushImageUrl(string $path): ?string
    {
        try {
            $cdn = app(MediaUrls::class)->url($path, 720);
            if (is_string($cdn) && str_starts_with($cdn, 'https://')) return $cdn;
            $signed = \Illuminate\Support\Facades\Storage::disk('starttoo')->temporaryUrl($path, now()->addHours(6));
            return is_string($signed) && str_starts_with($signed, 'https://') ? $signed : null;
        } catch (\Throwable) {
            return null; // 사진 주소를 만들지 못해도 글 알림은 보낸다.
        }
    }

    /**
     * 소셜 알림 문구. 제목에 이모지와 알림 종류, 본문에 보낸 회원과 내용을 넣는다.
     * 이력(starttoo_push_logs)에는 이름·댓글 원문 대신 기본 안내 문구만 남긴다.
     */
    /** 아티스트 심사 단계별 안내 문구. */
    private function noticeText(object $n): array
    {
        return match ($n->variant ?? null) {
            'submitted' => ['📮 아티스트 신청 접수', '신청서가 접수됐어요. 보통 영업일 3일 안에 결과를 알려드려요.'],
            'reviewing' => ['🔍 아티스트 심사 시작', '포트폴리오 심사를 시작했어요. 조금만 기다려주세요.'],
            'needs_changes' => ['📝 보완이 필요해요', '요청 사항을 확인하고 신청서를 보완해 다시 제출해주세요.'],
            'approved' => ['🎉 아티스트로 승인됐어요', '이제 상점을 열고 작품 · 도안을 올릴 수 있어요.'],
            'rejected' => ['아티스트 신청 결과', '이번 신청은 승인되지 않았어요. 사유를 확인하고 고쳐 다시 제출할 수 있어요.'],
            default => ['스타투', '아티스트 신청 심사 소식이 있어요.'],
        };
    }

    private function socialText(object $n): ?array
    {
        $db = $this->db();
        $actor = $db->table('starttoo_users')->where('id', $n->actor_id)->first(['nickname', 'display_name']);
        $name = $actor ? Str::limit((string) ($actor->nickname ?: $actor->display_name), 30, '…') : '';
        if ($name === '') return null;
        $target = match ($n->target_type) {
            'post' => '게시물',
            'artwork' => $db->table('starttoo_artworks')->where('id', $n->target_id)->value('kind') === 'design' ? '도안' : '작품',
            default => '게시물',
        };
        $comment = function () use ($db, $n): string {
            if (!$n->comment_id) return '';
            $table = $n->target_type === 'post' ? 'starttoo_post_comments' : 'starttoo_artwork_comments';
            $row = $db->table($table)->where('id', $n->comment_id)->whereNull('deleted_at')->first(['content']);
            $text = $this->multiline((string) ($row->content ?? ''));
            return $text === '' ? '' : Str::limit($text, 80, '…');
        };
        return match ($n->kind) {
            'like' => ['❤️ 새 좋아요', "{$name}님이 회원님의 {$target}을 좋아해요."],
            'comment_like' => ['❤️ 댓글 좋아요', "{$name}님이 회원님의 댓글을 좋아해요."],
            'comment' => ['💬 새 댓글', ($text = $comment()) !== '' ? "{$name}: {$text}" : "{$name}님이 회원님의 {$target}에 댓글을 남겼어요."],
            'reply' => ['↩️ 새 답글', ($text = $comment()) !== '' ? "{$name}: {$text}" : "{$name}님이 회원님의 댓글에 답글을 남겼어요."],
            'follow' => ['🙌 새 팔로워', "{$name}님이 회원님을 팔로우하기 시작했어요."],
            default => null,
        };
    }

    /**
     * 알림 본문: 줄바꿈은 살리고, 줄 안의 연속 공백과 2줄 이상 빈 줄은 하나로 줄인다.
     */
    private function multiline(string $text): string
    {
        $text = str_replace(["\r\n", "\r"], "\n", $text);
        $lines = array_map(fn ($line) => trim(preg_replace('/[^\S\n]+/u', ' ', $line) ?? ''), explode("\n", $text));
        $text = preg_replace("/\n{3,}/", "\n\n", implode("\n", $lines)) ?? '';
        return trim($text);
    }
}
