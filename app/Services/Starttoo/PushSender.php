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
            $eligible = $n && $n->created_at >= now()->subDay()->toDateTimeString() && app(UserPreferences::class)->get((int) $device->user_id)['values']['social'];
            if ($eligible) {
                $body = match ($n->kind) {
                    'like' => '회원님의 게시물에 새 좋아요가 있어요.',
                    'comment' => '회원님의 게시물에 새 댓글이 있어요.',
                    'reply' => '회원님의 댓글에 새 답글이 있어요.',
                    'follow' => '새로운 팔로워가 있어요.',
                    'comment_like' => '회원님의 댓글에 새 좋아요가 있어요.',
                    'new_post' => '팔로우한 회원이 새 게시물을 올렸어요.',
                };
                // 이력에는 본문 원문 대신 안내 문구만 남긴다.
                $db->table('starttoo_push_logs')->where('id', $log)->update(['kind' => $n->kind, 'title_snapshot' => '스타투', 'body_snapshot' => $body]);
                $title = '스타투';
                $image = null;
                if ($n->kind === 'new_post' && ($preview = $this->newPostPreview($n))) {
                    [$title, $body, $image] = $preview;
                }
                $notification = ['title' => $title, 'body' => $body];
                if ($image) $notification['image'] = $image;
                $aps = ['sound' => 'default'];
                // iOS는 Notification Service Extension이 fcm_options.image를 내려받아 붙인다.
                if ($image) $aps['mutable-content'] = 1;
                $fcm = app('starttoo.fcm');
                $result = $fcm->send(Crypt::decryptString($device->token_ciphertext), [
                    'notification' => $notification,
                    'data' => ['notification_id' => (string) $n->id, 'recipient_id' => (string) $device->user_id],
                    'android' => ['priority' => 'high', 'ttl' => '3600s', 'notification' => ['channel_id' => 'starttoo_social', 'tag' => 'starttoo:'.$n->id, 'icon' => 'ic_stat_starttoo']],
                    'apns' => ['headers' => ['apns-push-type' => 'alert', 'apns-priority' => '10', 'apns-collapse-id' => 'starttoo:'.$n->id],
                        'payload' => ['aps' => $aps], ...($image ? ['fcm_options' => ['image' => $image]] : [])],
                ]);
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
     * 팔로우한 회원의 새 게시물 알림: 제목에 작성자, 본문에 내용 앞부분, 대표 사진(첫 장).
     * 열람 권한은 delivery()에서 이미 확인했다. 내용이 없으면 기본 안내 문구를 쓴다.
     */
    private function newPostPreview(object $n): ?array
    {
        $db = $this->db();
        if ($n->target_type === 'artwork') {
            $row = $db->table('starttoo_artworks')->where('id', $n->target_id)->first(['title', 'description']);
            $text = $row ? trim(($row->description ?? '') !== '' ? $row->description : ($row->title ?? '')) : '';
            $path = $db->table('starttoo_artwork_images')->where('artwork_id', $n->target_id)->orderBy('position')->value('path');
        } elseif ($n->target_type === 'post') {
            $text = trim((string) $db->table('starttoo_posts')->where('id', $n->target_id)->value('content'));
            $path = $db->table('starttoo_post_images')->where('post_id', $n->target_id)->orderBy('position')->value('path');
        } else {
            return null;
        }
        $actor = $db->table('starttoo_users')->where('id', $n->actor_id)->first(['nickname', 'display_name']);
        $name = $actor ? ($actor->nickname ?: $actor->display_name) : null;
        $text = preg_replace('/\s+/u', ' ', $text) ?? '';
        $body = $text === '' ? '새 게시물을 올렸어요.' : Str::limit($text, 120, '…');
        $title = $name ? Str::limit($name, 30, '…').'님의 새 게시물' : '스타투';
        $image = null;
        try {
            $image = $path ? app(MediaUrls::class)->url($path, 720) : null;
        } catch (\Throwable) {
            $image = null; // 사진 주소를 만들지 못해도 글 알림은 보낸다.
        }
        // FCM 알림 사진은 https만 허용한다. CDN 주소가 http로 설정돼 있으면 https로 바꿔 보낸다.
        if (is_string($image) && str_starts_with($image, 'http://')) $image = 'https://'.substr($image, 7);
        return [$title, $body, is_string($image) && str_starts_with($image, 'https://') ? $image : null];
    }
}
