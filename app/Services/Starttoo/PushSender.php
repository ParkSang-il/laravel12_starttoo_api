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
                // 상담 메시지는 받는 사람(보낸 사람이 아닌 참여자)에게 보낸다.
                $n = $locked->message_id
                    ? $db->table('starttoo_messages as m')->join('starttoo_consultations as c', 'c.id', '=', 'm.consultation_id')
                        ->where('m.id', $locked->message_id)
                        ->selectRaw('m.created_at, CASE WHEN m.sender_id = c.customer_id THEN c.artist_id ELSE c.customer_id END as user_id')->first()
                    : $db->table('starttoo_notifications')->where('id', $box->notification_id)->first();
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
        $boxForLog = $db->table('starttoo_push_outbox')->where('id', $job->outbox_id)->first();
        if ($boxForLog && $boxForLog->message_id) return $this->deliverChat($job, $boxForLog, $key);
        $audit = app(PushAudit::class);
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
                && ($notice || app(UserPreferences::class)->pushAllowed((int) $device->user_id, $n->kind));
            if ($eligible) {
                $body = match ($n->kind) {
                    'like' => '회원님의 게시물에 새 좋아요가 있어요.',
                    'comment' => '회원님의 게시물에 새 댓글이 있어요.',
                    'reply' => '회원님의 댓글에 새 답글이 있어요.',
                    'follow' => '새로운 팔로워가 있어요.',
                    'comment_like' => '회원님의 댓글에 새 좋아요가 있어요.',
                    'new_post' => '팔로우한 회원이 새 게시물을 올렸어요.',
                    'artist_review' => '아티스트 신청 심사 소식이 있어요.',
                    'shop_news' => '팔로우한 아티스트의 상점 소식이 있어요.',
                    'fit_result' => 'AI 작업 결과가 준비됐어요.',
                    'review_new' => '상점에 새 리뷰가 등록됐어요.',
                    'review_reply' => '회원님의 리뷰에 작가 답변이 달렸어요.',
                    'comm_comment' => '회원님의 커뮤니티 글에 새 댓글이 있어요.',
                    'comm_like' => '회원님의 커뮤니티 글에 새 좋아요가 있어요.',
                    'comm_reply' => '회원님의 댓글에 새 답글이 있어요.',
                    'booking_remind' => '다가오는 시술 예약이 있어요.',
                    'booking_event' => '예약 소식이 있어요.',
                    'report_result' => '신고 처리 결과가 나왔어요.',
                    'sanction' => '운영 정책 안내가 있어요.',
                    default => '새 알림이 있어요.',
                };
                // 이력에는 본문 원문 대신 안내 문구만 남긴다.
                $db->table('starttoo_push_logs')->where('id', $log)->update(['kind' => $n->kind, 'title_snapshot' => '스타투', 'body_snapshot' => $body]);
                $title = '스타투';
                $images = [];
                if ($n->kind === 'new_post' && ($preview = $this->newPostPreview($n))) {
                    [$title, $body, $images] = $preview;
                } elseif ($notice) {
                    [$title, $body] = $this->noticeText($n);
                } elseif ($n->kind === 'shop_news') {
                    [$title, $body] = $this->shopNewsText($n);
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
     * 상담 메시지 푸시. 발송 직전에 다시 확인한다: 받는 사람의 세션·기기, 채팅 알림 설정,
     * 이미 읽은 메시지(앱에서 열어 본 경우), 차단 관계. 같은 방 알림은 tag로 하나만 남긴다.
     */
    private function deliverChat(object $job, object $box, string $key): ?int
    {
        $db = $this->db();
        $id = (int) $job->id;
        $device = $db->table('starttoo_push_devices')->where('id', $job->device_id)->first();
        $audit = app(PushAudit::class);
        $log = $audit->begin('chat', (string) $box->message_id, $id, $device ? (int) $device->user_id : null, (int) $job->device_id,
            $device->platform ?? null, (int) $job->attempts + 1);
        try {
            $device = $db->table('starttoo_push_devices as d')->join('starttoo_auth_sessions as s', 's.id', '=', 'd.session_id')
                ->join('starttoo_users as u', 'u.id', '=', 'd.user_id')
                ->where('d.id', $job->device_id)->where('d.session_id', $job->session_id)->where('d.token_hash', $job->token_hash)
                ->whereColumn('s.user_id', 'd.user_id')->whereNull('s.revoked_at')->where('s.idle_expires_at', '>', now())->where('s.absolute_expires_at', '>', now())
                ->where('u.status', 'active')->where('u.moderation_hidden', false)->first(['d.*']);
            $m = $db->table('starttoo_messages')->where('id', $box->message_id)->first();
            $room = $m ? $db->table('starttoo_consultations')->where('id', $m->consultation_id)->first() : null;
            $result = ['state' => 'skipped', 'code' => 'RECIPIENT_OR_MESSAGE_UNAVAILABLE'];
            $eligible = false;
            if ($device && $m && $room && $m->created_at >= now()->subDay()->toDateTimeString()) {
                $recipient = (int) $device->user_id;
                $isCustomer = (int) $room->customer_id === $recipient;
                $participant = $isCustomer || (int) $room->artist_id === $recipient;
                $read = (int) ($isCustomer ? $room->customer_last_read_id : $room->artist_last_read_id) >= (int) $m->id;
                $eligible = $participant && $recipient !== (int) $m->sender_id && !$read
                    && !app(Blocks::class)->between($recipient, (int) $m->sender_id)
                    && app(UserPreferences::class)->get($recipient)['values']['chat'];
                if ($participant && $read) $result['code'] = 'ALREADY_READ';
            }
            if ($eligible) {
                $sender = $db->table('starttoo_users')->where('id', $m->sender_id)->first(['nickname', 'display_name']);
                $title = $sender ? Str::limit($sender->nickname ?: ($sender->display_name ?: '스타투'), 30, '…') : '스타투';
                // 같은 방 알림은 하나로 묶이므로(오프라인이었다면 마지막 것만 도착) 안 읽은 수를 제목에 붙인다.
                $myRead = (int) ((int) $room->customer_id === (int) $device->user_id ? $room->customer_last_read_id : $room->artist_last_read_id);
                $pending = $db->table('starttoo_messages')->where('consultation_id', $room->id)->where('id', '>', $myRead)
                    ->where('id', '<=', $m->id)->where(fn ($w) => $w->whereNull('sender_id')->orWhere('sender_id', '<>', $device->user_id))
                    ->limit(100)->count();
                if ($pending > 1) $title .= ' · 새 메시지 '.($pending > 99 ? '99+' : $pending).'개';
                $body = match ($m->kind) {
                    'text' => Str::limit($this->multiline((string) $m->body), 120, '…'),
                    'image' => '사진을 보냈어요.',
                    'artwork' => '작품을 보냈어요.',
                    'request' => '상담 요청서를 보냈어요.',
                    'quote' => '견적을 보냈어요.',
                    default => '새 메시지가 있어요.',
                };
                // 이력에는 메시지 원문을 남기지 않는다.
                $db->table('starttoo_push_logs')->where('id', $log)->update(['kind' => 'chat', 'title_snapshot' => '스타투', 'body_snapshot' => '새 메시지']);
                $tag = 'starttoo:chat:'.$room->id;
                $message = [
                    'notification' => ['title' => $title, 'body' => $body],
                    'data' => ['type' => 'chat', 'consultation_id' => (string) $room->id, 'message_id' => (string) $m->id, 'recipient_id' => (string) $device->user_id],
                    'android' => ['priority' => 'high', 'ttl' => '3600s', 'notification' => ['channel_id' => 'starttoo_chat', 'tag' => $tag, 'icon' => 'ic_stat_starttoo']],
                    'apns' => ['headers' => ['apns-push-type' => 'alert', 'apns-priority' => '10', 'apns-collapse-id' => $tag],
                        'payload' => ['aps' => ['sound' => 'default', 'thread-id' => $tag]]],
                ];
                $result = app('starttoo.fcm')->send(Crypt::decryptString($device->token_ciphertext), $message);
            }
            if ($result['state'] === 'invalid' && $device) {
                $db->table('starttoo_push_devices')->where('id', $device->id)->where('token_hash', $job->token_hash)->delete();
            }
            $audit->finish($log, $result);
            $retry = $eligible && $result['state'] === 'retry' && $job->attempts < 7;
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
    /** 상점 소식: 제목에 상점 이름, 본문에 소식 내용. */
    private function shopNewsText(object $n): array
    {
        $db = $this->db();
        $shop = $db->table('starttoo_shops')->where('user_id', $n->actor_id)->first(['studio_name']);
        $nickname = $db->table('starttoo_users')->where('id', $n->actor_id)->value('nickname');
        $news = $db->table('starttoo_shop_news')->where('id', $n->target_id)->first(['kind', 'title', 'body']);
        $name = Str::limit(implode(' · ', array_filter([$nickname ?: '아티스트', $shop->studio_name ?? null])), 30, '…');
        $label = ($news->kind ?? null) === 'holiday' ? '휴무 안내' : '새 소식';
        $text = $news ? $this->multiline(trim(($news->title ? $news->title."\n" : '').$news->body)) : '';
        return ['📢 '.$name.' · '.$label, $text === '' ? '상점에 새 소식이 올라왔어요.' : Str::limit($text, 120, '…')];
    }

    /** 아티스트 심사 단계별 안내 문구. */
    private function noticeText(object $n): array
    {
        // 운영 제재 안내(누가 신고했는지는 담지 않는다)
        if ($n->kind === 'sanction') {
            return match ($n->variant) {
                'warning' => ['⚠️ 운영 정책 위반 경고', '신고된 게시물이 운영 정책에 맞지 않아 숨김 처리되고 경고가 기록됐어요.'],
                'suspend' => ['이용이 일시 정지됐어요', '운영 정책 위반으로 일정 기간 이용이 정지됐어요.'],
                default => ['이용이 정지됐어요', '운영 정책 위반으로 이용이 정지됐어요. 고객지원에 문의해주세요.'],
            };
        }
        // 신고 처리 결과(상대 정보 · 처리 내용은 담지 않는다)
        if ($n->kind === 'report_result') {
            return $n->variant === 'resolved'
                ? ['🛡️ 신고가 처리됐어요', '운영팀이 확인하고 조치했어요. 신고 내역에서 확인할 수 있어요.']
                : ['신고 검토가 끝났어요', '운영 정책 위반이 확인되지 않아 조치 없이 종료됐어요.'];
        }
        // AI 결과(variant = 종류:상태)
        if ($n->kind === 'fit_result') {
            [$type, $status] = array_pad(explode(':', (string) $n->variant), 2, '');
            $name = ['extract' => '도안 추출', 'fill' => '타투 필러', 'coverup' => '커버업'][$type] ?? '가상 피팅';
            return $status === 'ready'
                ? ["✨ {$name} 결과가 나왔어요", '눌러서 결과를 확인해보세요.']
                : ["{$name}을 만들지 못했어요", '사용 횟수는 차감되지 않았어요. 다시 시도해주세요.'];
        }
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
        // 예약 리마인더: 「내일 오후 2:00 · ○○님과 시술 예약」
        if ($n->kind === 'booking_remind') {
            $at = $db->table('starttoo_bookings')->where('id', $n->target_id)->value('starts_at');
            if (!$at) return ['⏰ 다가오는 예약', "{$name}님과 시술 예약이 있어요."];
            $at = \Illuminate\Support\Carbon::parse($at);
            $day = $at->isToday() ? '오늘' : ($at->isTomorrow() ? '내일' : $at->format('n월 j일'));
            $time = ($at->hour < 12 ? '오전 ' : '오후 ').(($at->hour % 12) ?: 12).$at->format(':i');
            return ['⏰ 다가오는 예약', "{$day} {$time} · {$name}님과 시술 예약이 있어요."];
        }
        // Phase 5: 리뷰 · 커뮤니티 댓글
        if ($n->kind === 'review_new') {
            $review = $db->table('starttoo_reviews')->where('id', $n->target_id)->first(['rating', 'content']);
            $text = $review ? Str::limit($this->multiline((string) $review->content), 80, '…') : '';
            $stars = $review ? str_repeat('★', (int) $review->rating) : '';
            return ['⭐ 새 리뷰', $text !== '' ? trim("{$stars} {$name}: {$text}") : "{$name}님이 리뷰를 남겼어요."];
        }
        if ($n->kind === 'review_reply') {
            $reply = $this->multiline((string) $db->table('starttoo_reviews')->where('id', $n->target_id)->value('reply'));
            return ['💬 작가 답변', $reply !== '' ? "{$name}: ".Str::limit($reply, 80, '…') : "{$name}님이 회원님의 리뷰에 답변을 남겼어요."];
        }
        if ($n->kind === 'comm_comment' || $n->kind === 'comm_reply') {
            $text = $n->comment_id ? $this->multiline((string) $db->table('starttoo_community_comments')->where('id', $n->comment_id)->value('content')) : '';
            $reply = $n->kind === 'comm_reply';
            return [$reply ? '↩️ 새 답글' : '💬 새 댓글', $text !== '' ? "{$name}: ".Str::limit($text, 80, '…')
                : ($reply ? "{$name}님이 회원님의 댓글에 답글을 남겼어요." : "{$name}님이 회원님의 글에 댓글을 남겼어요.")];
        }
        if ($n->kind === 'comm_like') {
            $title = Str::limit($this->multiline((string) $db->table('starttoo_community_posts')->where('id', $n->target_id)->value('title')), 40, '…');
            return ['❤️ 새 좋아요', $title !== '' ? "{$name}님이 「{$title}」 글을 좋아해요." : "{$name}님이 회원님의 글을 좋아해요."];
        }
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
