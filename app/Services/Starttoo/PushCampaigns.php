<?php

namespace App\Services\Starttoo;

use App\Jobs\ProcessStarttooCampaign;
use App\Services\ProjectDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;

/**
 * 푸시 캠페인(서버 명령 · 운영 콘솔). 수신자 전체를 메모리나 큐에 한 번에 올리지 않는다.
 * kind: marketing 광고성((광고) · 수신 동의자 · 09~20시) · info 정보성(앱 알림을 켠 모든 정상 회원 · 시간 제한 없음)
 */
class PushCampaigns
{
    private function db() { return app(ProjectDatabase::class)->database(); }

    private static function isInfo(object $c): bool
    {
        return ($c->kind ?? 'marketing') === 'info';
    }

    /** 정보성은 푸시만, 광고성은 광고 푸시 설정까지 켜져 있어야 한다 */
    private static function enabled(bool $info): bool
    {
        return config('starttoo.push_enabled') && ($info || config('starttoo.marketing_push_enabled'));
    }

    public function create(array $input): string
    {
        $info = ($input['kind'] ?? 'marketing') === 'info';
        $v = Validator::make($input, [
            'kind' => ['sometimes', 'in:marketing,info'],
            'title' => ['required', 'string', 'max:'.($info ? 60 : 45)], 'body' => ['required', 'string', 'max:600'],
            'sender' => [$info ? 'nullable' : 'required', 'string', 'max:50'],
            // notice: 운영 콘솔 공지 푸시(공지 번호) → 앱이 공지 상세를 연다
            'target_type' => ['required', 'in:home,settings,artwork,post,notice'],
            'target_id' => ['nullable', 'required_if:target_type,artwork,post,notice', ($input['target_type'] ?? '') === 'notice' ? 'integer' : 'uuid'],
            'user_ids' => ['nullable', 'array', 'min:1', 'max:1000'], 'user_ids.*' => ['integer', 'min:1', 'distinct'],
            'per_minute' => ['sometimes', 'integer', 'min:1', 'max:6000'],
        ]);
        if ($v->fails()) throw new \InvalidArgumentException('캠페인 JSON을 확인해주세요: '.$v->errors()->first());
        $input = $v->validated();
        if (in_array($input['target_type'], ['post', 'artwork'], true)) {
            $service = $input['target_type'] === 'post' ? app(Posts::class) : app(Artworks::class);
            $alias = $input['target_type'] === 'post' ? 'p' : 'a';
            if (!$service->feedQuery(null, 'public')->where($alias.'.id', $input['target_id'])->exists()) throw new \InvalidArgumentException('공개된 게시물만 광고 대상으로 지정할 수 있어요.');
        }
        if ($input['target_type'] === 'notice' && !$this->db()->table('starttoo_announcements')->where('id', (int) $input['target_id'])->where('active', true)->exists()) {
            throw new \InvalidArgumentException('게시 중인 공지만 푸시로 보낼 수 있어요.');
        }
        $id = (string) Str::uuid();
        $this->db()->table('starttoo_push_campaigns')->insert([
            'id' => $id, 'kind' => $info ? 'info' : 'marketing',
            'title' => $info ? $input['title'] : '(광고) '.$input['title'],
            'body' => $info ? $input['body'] : $input['sender']."\n".$input['body']."\n수신거부: 앱 > 알림 · 개인정보 설정 > 이벤트 · 혜택",
            'target_type' => $input['target_type'], 'target_id' => in_array($input['target_type'], ['post', 'artwork', 'notice'], true) ? (string) $input['target_id'] : null,
            'user_ids' => isset($input['user_ids']) ? json_encode($input['user_ids'], JSON_THROW_ON_ERROR) : null,
            'per_minute' => $input['per_minute'] ?? 300, 'created_at' => now(),
        ]);
        return $id;
    }

    public function control(string $id, string $action): void
    {
        if (!Str::isUuid($id)) throw new \InvalidArgumentException('캠페인 UUID를 확인해주세요.');
        $db = $this->db();
        $db->transaction(function () use ($db, $id, $action) {
            $c = $db->table('starttoo_push_campaigns')->where('id', $id)->lockForUpdate()->first();
            if (!$c) throw new \InvalidArgumentException('캠페인을 찾을 수 없어요.');
            if (in_array($c->state, ['cancelled', 'completed'], true)) throw new \InvalidArgumentException('종료된 캠페인은 다시 시작할 수 없어요.');
            if ($action === 'start') {
                if ($c->state === 'running') return;
                if (!self::enabled(self::isInfo($c))) throw new \InvalidArgumentException(self::isInfo($c) ? '푸시 서버 설정을 켜주세요.' : '푸시 및 광고 푸시 서버 설정을 켜주세요.');
                $db->table('starttoo_push_campaigns')->where('id', $id)->update([
                    'state' => 'running', 'max_device_id' => $c->started_at ? $c->max_device_id : (int) ($db->table('starttoo_push_devices')->max('id') ?? 0),
                    'started_at' => $c->started_at ?? now(), 'expires_at' => $c->expires_at ?? now()->addDays(7),
                ]);
                $db->afterCommit(fn () => ProcessStarttooCampaign::dispatch($id));
            } elseif (in_array($action, ['pause', 'cancel'], true)) {
                $db->table('starttoo_push_campaigns')->where('id', $id)->update(['state' => $action === 'pause' ? 'paused' : 'cancelled']);
                if ($action === 'cancel') $db->table('starttoo_campaign_deliveries')->where('campaign_id', $id)->where('state', 'pending')->update(['state' => 'skipped']);
            } else throw new \InvalidArgumentException('start, pause, cancel 중 선택해주세요.');
        }, 3);
    }

    private function dayDelay(): int
    {
        // 야간 수신 동의를 별도로 수집하지 않으므로 09:00~20:00 KST에만 광고를 발송한다.
        $now = now('Asia/Seoul');
        if ($now->hour >= 9 && $now->hour < 20) return 0;
        $next = $now->copy()->setTime(9, 0);
        if ($now->hour >= 20) $next->addDay();
        return max(1, (int) $now->diffInSeconds($next, false));
    }

    /** 한 큐 작업은 최대 25기기 대상 생성, 실제 FCM 호출은 한 대만 한다. */
    public function tick(string $id): ?int
    {
        $db = $this->db();
        $c = $db->table('starttoo_push_campaigns')->where('id', $id)->first();
        if (!$c || $c->state !== 'running') return null;
        if (!self::enabled(self::isInfo($c))) return null;
        if ($c->expires_at <= now()->toDateTimeString()) {
            $db->table('starttoo_campaign_deliveries')->where('campaign_id', $id)->whereIn('state', ['pending', 'sending'])->update(['state' => 'skipped']);
            $db->table('starttoo_push_campaigns')->where('id', $id)->update(['state' => 'completed']);
            return null;
        }
        // 광고성만 야간(20~9시)에 보내지 않는다
        if (!self::isInfo($c) && ($wait = $this->dayDelay())) return $wait;
        if ($c->next_send_at !== null && $c->next_send_at > now()->toDateTimeString()) {
            return max(1, (int) now()->diffInSeconds(Carbon::parse($c->next_send_at), false) + 1);
        }
        $ready = $db->table('starttoo_campaign_deliveries')->where('campaign_id', $id)->where('available_at', '<=', now())
            ->where(fn ($q) => $q->where('state', 'pending')->orWhere(fn ($l) => $l->where('state', 'sending')->where('leased_until', '<', now())))->orderBy('id')->first();
        if (!$ready) {
            // 미완료 기기가 있으면 그 배치가 끝난 뒤 다음 대상 25개를 생성한다.
            $waiting = $db->table('starttoo_campaign_deliveries')->where('campaign_id', $id)->whereIn('state', ['pending', 'sending'])->first();
            if ($waiting) return max(1, (int) now()->diffInSeconds(Carbon::parse($waiting->state === 'sending' ? $waiting->leased_until : $waiting->available_at), false) + 1);
            if ($c->expanded) {
                $db->table('starttoo_push_campaigns')->where('id', $id)->where('state', 'running')->update(['state' => 'completed']);
                return null;
            }
            $db->transaction(function () use ($db, $id) {
                $c = $db->table('starttoo_push_campaigns')->where('id', $id)->lockForUpdate()->first();
                if (!$c || $c->state !== 'running' || $c->expanded) return;
                if ($db->table('starttoo_campaign_deliveries')->where('campaign_id', $id)->whereIn('state', ['pending', 'sending'])->exists()) return;
                $q = $db->table('starttoo_push_devices')->where('id', '>', $c->last_device_id)->where('id', '<=', $c->max_device_id);
                if ($c->user_ids !== null) $q->whereIn('user_id', json_decode($c->user_ids, true, 512, JSON_THROW_ON_ERROR));
                $devices = $q->orderBy('id')->limit(25)->get();
                foreach ($devices as $d) $db->table('starttoo_campaign_deliveries')->insertOrIgnore([
                    'campaign_id' => $id, 'device_id' => $d->id, 'user_id' => $d->user_id,
                    'session_id' => $d->session_id, 'token_hash' => $d->token_hash, 'available_at' => now(),
                ]);
                $db->table('starttoo_push_campaigns')->where('id', $id)->update([
                    'last_device_id' => $devices->last()->id ?? $c->max_device_id, 'expanded' => $devices->count() < 25,
                ]);
            }, 3);
            return 0;
        }
        return $this->send($id, (int) $ready->id);
    }

    private function send(string $campaign, int $delivery): int
    {
        $db = $this->db();
        $key = (string) Str::uuid();
        $claimed = $db->transaction(function () use ($db, $campaign, $delivery, $key) {
            $c = $db->table('starttoo_push_campaigns')->where('id', $campaign)->lockForUpdate()->first();
            if (!$c || $c->state !== 'running') return null;
            if ($c->next_send_at !== null && $c->next_send_at > now()->toDateTimeString()) return null;
            $sameWindow = $c->rate_window_at !== null && $c->rate_window_at > now()->subMinute()->toDateTimeString();
            if ($sameWindow && $c->rate_count >= $c->per_minute) {
                $db->table('starttoo_push_campaigns')->where('id', $campaign)->update(['next_send_at' => Carbon::parse($c->rate_window_at)->addMinute()]);
                return null;
            }
            $d = $db->table('starttoo_campaign_deliveries')->where('id', $delivery)->where('campaign_id', $campaign)->lockForUpdate()->first();
            if (!$d || $d->available_at > now()->toDateTimeString() || ($d->state !== 'pending' && !($d->state === 'sending' && $d->leased_until < now()->toDateTimeString()))) return null;
            if ($d->attempts >= 8) {
                $db->table('starttoo_campaign_deliveries')->where('id', $delivery)->update(['state' => 'failed']);
                return null;
            }
            $db->table('starttoo_campaign_deliveries')->where('id', $delivery)->update(['state' => 'sending', 'attempts' => $d->attempts + 1, 'lease_key' => $key, 'leased_until' => now()->addMinutes(2)]);
            $db->table('starttoo_push_campaigns')->where('id', $campaign)->update([
                'rate_window_at' => $sameWindow ? $c->rate_window_at : now(), 'rate_count' => $sameWindow ? $c->rate_count + 1 : 1,
            ]);
            return [$c, $d];
        }, 3);
        if (!$claimed) return 1;
        [$c, $d] = $claimed;
        $audit = app(PushAudit::class);
        $info = self::isInfo($c);
        $log = $audit->begin('campaign', $campaign, $delivery, (int) $d->user_id, (int) $d->device_id, null, $d->attempts + 1, $info ? 'notice' : 'marketing', $c->title, $c->body);
        try {
            $device = $db->table('starttoo_push_devices as dev')->join('starttoo_auth_sessions as s', 's.id', '=', 'dev.session_id')
                ->join('starttoo_users as u', 'u.id', '=', 'dev.user_id')->where('dev.id', $d->device_id)->where('dev.user_id', $d->user_id)
                ->where('dev.session_id', $d->session_id)->where('dev.token_hash', $d->token_hash)->whereColumn('s.user_id', 'dev.user_id')
                ->whereNull('s.revoked_at')->where('s.idle_expires_at', '>', now())->where('s.absolute_expires_at', '>', now())
                ->where('u.status', 'active')->where('u.moderation_hidden', false)->first(['dev.*']);
            $result = ['state' => 'skipped', 'code' => 'SESSION_OR_MARKETING_CONSENT_UNAVAILABLE'];
            $running = $db->table('starttoo_push_campaigns')->where('id', $campaign)->where('state', 'running')->exists();
            if ($running && $device && in_array($c->target_type, ['post', 'artwork'], true)) {
                $service = $c->target_type === 'post' ? app(Posts::class) : app(Artworks::class);
                $alias = $c->target_type === 'post' ? 'p' : 'a';
                $running = $service->feedQuery((int) $device->user_id, 'public')->where($alias.'.id', $c->target_id)->exists();
            }
            // 광고성: 09~20시 · 광고 수신 동의자만 / 정보성: 시간 · 동의 조건 없음
            $allowed = $device && ($info || ($this->dayDelay() === 0 && app(UserPreferences::class)->marketingEligible((int) $device->user_id)));
            if ($device && $running && $allowed) {
                $fcm = app('starttoo.fcm');
                $result = $fcm->send(Crypt::decryptString($device->token_ciphertext), [
                    'notification' => ['title' => $c->title, 'body' => $c->body],
                    'data' => ['campaign_id' => $campaign, 'recipient_id' => (string) $d->user_id],
                    'android' => ['priority' => $info ? 'high' : 'normal', 'ttl' => '3600s', 'notification' => ['channel_id' => $info ? 'starttoo_notice' : 'starttoo_marketing', 'tag' => 'campaign:'.$campaign, 'icon' => 'ic_stat_starttoo']],
                    'apns' => ['headers' => ['apns-push-type' => 'alert', 'apns-priority' => '10', 'apns-collapse-id' => 'campaign:'.$campaign], 'payload' => ['aps' => ['sound' => 'default']]],
                ]);
                $db->table('starttoo_push_logs')->where('id', $log)->update(['platform' => $device->platform]);
            }
            $audit->finish($log, $result);
            $retry = in_array($result['state'], ['retry'], true) || ($result['code'] ?? null) === 'CREDENTIALS_UNAVAILABLE';
            $retry = $retry && $d->attempts < 7;
            $delay = max((int) ($result['delay'] ?? 60), min(3600, 60 * (2 ** min(6, $d->attempts)))) + random_int(0, 10);
            if ($retry && in_array($result['code'] ?? '', ['QUOTA_EXCEEDED', 'RESOURCE_EXHAUSTED', 'UNAVAILABLE', 'INTERNAL', 'CREDENTIALS_UNAVAILABLE'], true)) {
                $db->table('starttoo_push_campaigns')->where('id', $campaign)->update(['next_send_at' => now()->addSeconds($delay)]);
            }
            $db->table('starttoo_campaign_deliveries')->where('id', $delivery)->where('lease_key', $key)->update([
                'state' => $retry ? 'pending' : ($result['state'] === 'sent' ? 'sent' : ($result['state'] === 'skipped' ? 'skipped' : 'failed')),
                'available_at' => now()->addSeconds($delay), 'lease_key' => null, 'leased_until' => null,
            ]);
            if ($result['state'] === 'invalid' && $device) $db->table('starttoo_push_devices')->where('id', $device->id)->where('token_hash', $d->token_hash)->delete();
        } catch (\Throwable $e) {
            $audit->finish($log, ['state' => 'exception', 'code' => 'WORKER_EXCEPTION']);
            $db->table('starttoo_campaign_deliveries')->where('id', $delivery)->where('lease_key', $key)->update([
                'state' => 'pending', 'available_at' => now()->addMinute(), 'lease_key' => null, 'leased_until' => null,
            ]);
            throw $e;
        }
        return 0;
    }

    public function open(string $id, int $user): array
    {
        $db = $this->db();
        if (!$db->table('starttoo_campaign_deliveries')->where('campaign_id', $id)->where('user_id', $user)->where('attempts', '>', 0)->exists()) abort(404);
        $c = $db->table('starttoo_push_campaigns')->where('id', $id)->first();
        if (!$c || $c->state === 'cancelled') abort(404);
        if ($c->target_type === 'post') app(Posts::class)->detail($c->target_id, $user);
        if ($c->target_type === 'artwork') app(Artworks::class)->detail($c->target_id, $user);
        // 내려간 공지면 404(앱은 열지 않는다)
        if ($c->target_type === 'notice') app(Announcements::class)->detail((int) $c->target_id);
        $db->table('starttoo_campaign_deliveries')->where('campaign_id', $id)->where('user_id', $user)->whereNull('opened_at')->update(['opened_at' => now()]);
        app(PushAudit::class)->opened('campaign', $id, $user);
        return ['target_type' => $c->target_type, 'target_id' => $c->target_id];
    }
}
