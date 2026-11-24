<?php

namespace App\Services\Starttoo;

use App\Services\ProjectDatabase;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Support\Facades\Validator;

class UserPreferences
{
    public const DEFAULTS = ['chat' => true, 'booking' => true, 'social' => true, 'shopNews' => true, 'privateSave' => false, 'dm' => true];

    public function get(int $user): array
    {
        $db = app(ProjectDatabase::class)->database();
        $row = $db->table('starttoo_user_preferences')->where('user_id', $user)->first();
        $values = [];
        foreach (self::DEFAULTS as $key => $default) $values[$key] = $row ? (bool) $row->$key : $default;
        // 가입 동의 원본은 수정하지 않는다. 이후 변경과 가입 기록 중 최신 선택을 사용한다.
        $change = $db->table('starttoo_marketing_choices')->where('user_id', $user)->orderByDesc('recorded_at')->orderByDesc('id')->first();
        $signup = $db->table('starttoo_consent_records as r')->join('starttoo_consent_submissions as s', 's.id', '=', 'r.submission_id')
            ->where('s.user_id', $user)->where('r.document_id', 'marketing')->orderByDesc('r.recorded_at')->orderByDesc('r.id')->first(['r.accepted', 'r.recorded_at']);
        $values['mkt'] = $change && (!$signup || $change->recorded_at >= $signup->recorded_at) ? (bool) $change->accepted : (bool) ($signup->accepted ?? false);
        return ['values' => $values, 'version' => (int) ($row->version ?? 0)];
    }

    /** 알림 종류별 푸시 허용 여부. 상점 소식은 별도 설정, 나머지 소셜 알림은 소셜 설정을 따른다. */
    public function pushAllowed(int $user, string $kind): bool
    {
        $values = $this->get($user)['values'];
        return $kind === 'shop_news' ? $values['shopNews'] : $values['social'];
    }

    public function savedPublic(int $user): bool
    {
        return (bool) app(ProjectDatabase::class)->database()->table('starttoo_user_preferences')->where('user_id', $user)->value('privateSave');
    }

    /** 개발용 초안 동의를 실제 광고 수신 동의로 사용하지 않는다. */
    public function marketingEligible(int $user): bool
    {
        $db = app(ProjectDatabase::class)->database();
        $change = $db->table('starttoo_marketing_choices')->where('user_id', $user)->orderByDesc('recorded_at')->orderByDesc('id')->first();
        $signup = $db->table('starttoo_consent_records as r')->join('starttoo_consent_submissions as s', 's.id', '=', 'r.submission_id')
            ->where('s.user_id', $user)->where('r.document_id', 'marketing')->orderByDesc('r.recorded_at')->orderByDesc('r.id')
            ->first(['r.accepted', 'r.recorded_at', 's.is_draft']);
        if ($change && (!$signup || $change->recorded_at >= $signup->recorded_at)) {
            $snapshot = json_decode($change->document_snapshot ?? 'null', true);
            return (bool) $change->accepted && is_array($snapshot) && ($snapshot['is_draft'] ?? true) === false;
        }
        return $signup && (bool) $signup->accepted && !$signup->is_draft;
    }

    public function change(int $user, array $input): array
    {
        if (Validator::make($input, ['key' => ['required', 'in:chat,booking,social,shopNews,mkt,privateSave,dm'], 'value' => ['required', 'boolean'],
            'version' => ['required', 'integer', 'min:0'], 'request_key' => ['required', 'uuid']])->fails() || !is_bool($input['value'] ?? null)) $this->fail(422, 'INVALID_PREFERENCES', '설정 값을 확인해주세요.');
        $db = app(ProjectDatabase::class)->database();
        return $db->transaction(function () use ($db, $user, $input) {
            $db->table('starttoo_users')->where('id', $user)->lockForUpdate()->first();
            $hash = hash('sha256', json_encode([$input['key'], $input['value'], (int) $input['version']], JSON_THROW_ON_ERROR));
            $retry = $db->table('starttoo_preference_requests')->where('user_id', $user)->where('request_key', $input['request_key'])->first();
            if ($retry) {
                if ($retry->payload_hash !== $hash) $this->fail(409, 'PREFERENCES_REQUEST_CONFLICT', '설정 변경 요청이 달라졌어요. 다시 불러와주세요.');
                return json_decode($retry->response_payload, true, 512, JSON_THROW_ON_ERROR);
            }
            $current = $this->get($user);
            if ($current['version'] !== (int) $input['version']) $this->fail(409, 'PREFERENCES_CONFLICT', '다른 곳에서 설정이 변경됐어요. 최신 설정을 확인해주세요.');
            $snapshot = null;
            if ($input['key'] === 'mkt') {
                // 철회는 약관 서비스가 불가능한 상황에서도 허용한다.
                if ($input['value']) {
                    $catalog = app(TermsConsents::class)->catalog();
                    $doc = collect($catalog['documents'])->firstWhere('id', 'marketing');
                    if (!$doc || $doc['required']) $this->fail(503, 'TERMS_NOT_AVAILABLE', '수신 동의 문서를 준비하고 있어요.');
                    $snapshot = json_encode(['document' => $doc, 'is_draft' => $catalog['is_draft'], 'revision' => $catalog['revision']], JSON_THROW_ON_ERROR);
                }
                $db->table('starttoo_marketing_choices')->insert(['user_id' => $user, 'accepted' => $input['value'], 'document_snapshot' => $snapshot, 'recorded_at' => now()]);
            }
            $db->table('starttoo_user_preferences')->insertOrIgnore(['user_id' => $user]);
            $update = ['version' => $current['version'] + 1, 'updated_at' => now()];
            if ($input['key'] !== 'mkt') $update[$input['key']] = $input['value'];
            $db->table('starttoo_user_preferences')->where('user_id', $user)->update($update);
            $result = $this->get($user);
            $db->table('starttoo_preference_requests')->insert(['user_id' => $user, 'request_key' => $input['request_key'], 'payload_hash' => $hash, 'response_payload' => json_encode($result, JSON_THROW_ON_ERROR)]);
            return $result;
        }, 3);
    }

    private function fail(int $status, string $code, string $message): never
    {
        throw new HttpResponseException(response()->json(compact('code', 'message'), $status));
    }
}
