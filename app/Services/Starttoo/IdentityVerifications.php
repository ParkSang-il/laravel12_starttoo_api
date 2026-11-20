<?php

namespace App\Services\Starttoo;

use App\Services\ProjectDatabase;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Validator;

/**
 * 휴대폰 본인인증.
 * 실명인증 업체 연동 전에는 STARTTOO_IDENTITY_PROVIDER=dev(운영 환경에서는 사용 불가)로만 동작한다.
 * 업체를 정하면 verifyWithProvider()에 해당 업체의 인증 결과 조회를 추가한다.
 * 실명·번호·생년월일은 암호화해 보관하고, 응답에는 가린 이름만 준다.
 */
class IdentityVerifications
{
    private function db() { return app(ProjectDatabase::class)->database(); }

    public function provider(): ?string
    {
        $provider = config('starttoo.identity_provider');
        if ($provider === 'dev' && app()->environment('production')) return null;
        return is_string($provider) && $provider !== '' ? $provider : null;
    }

    /** 회원의 인증 상태(가린 이름). */
    public function summary(int $userId): array
    {
        $row = $this->db()->table('starttoo_identity_verifications')->where('user_id', $userId)->first();
        return ['provider' => $this->provider(), 'verified' => (bool) $row,
            'name' => $row ? $this->mask(Crypt::decryptString($row->name_enc)) : null,
            'verified_at' => $row?->verified_at];
    }

    public function verify(int $userId, array $input): array
    {
        $provider = $this->provider();
        if ($provider === null) {
            $this->fail(503, 'IDENTITY_NOT_AVAILABLE', '본인인증을 준비하고 있어요. 잠시 후 다시 시도해주세요.');
        }
        $person = $provider === 'dev' ? $this->dev($input) : $this->verifyWithProvider($provider, $input);
        $hash = $person['ci'] ? hash('sha256', $provider.'|'.$person['ci']) : null;
        // 같은 사람이 여러 계정으로 아티스트 신청하는 것을 막는다(탈퇴 회원 제외).
        if ($hash && $this->db()->table('starttoo_identity_verifications as v')
            ->join('starttoo_users as u', 'u.id', '=', 'v.user_id')
            ->where('v.person_hash', $hash)->where('v.user_id', '!=', $userId)->where('u.status', '!=', 'withdrawn')->exists()) {
            $this->fail(409, 'IDENTITY_IN_USE', '이미 다른 계정에서 본인인증한 정보예요.');
        }
        $this->db()->table('starttoo_identity_verifications')->updateOrInsert(['user_id' => $userId], [
            'provider' => $provider, 'person_hash' => $hash,
            'name_enc' => Crypt::encryptString($person['name']),
            'phone_enc' => $person['phone'] ? Crypt::encryptString($person['phone']) : null,
            'birth_enc' => $person['birth'] ? Crypt::encryptString($person['birth']) : null,
            'verified_at' => now(), 'created_at' => now(),
        ]);
        return $this->summary($userId);
    }

    /** 심사용 실명(복호화). 서버 명령에서만 사용한다. */
    public function realName(int $userId): ?string
    {
        $row = $this->db()->table('starttoo_identity_verifications')->where('user_id', $userId)->first();
        return $row ? Crypt::decryptString($row->name_enc) : null;
    }

    /** 개발 서버 전용: 입력한 이름·번호를 인증된 것으로 기록한다. */
    private function dev(array $input): array
    {
        if (Validator::make($input, [
            'name' => ['required', 'string', 'min:2', 'max:20', 'regex:/^[가-힣a-zA-Z ]+$/u'],
            'phone' => ['required', 'string', 'regex:/^01[0-9]{8,9}$/'],
        ])->fails()) {
            $this->fail(422, 'INVALID_IDENTITY', '이름과 휴대폰 번호를 확인해주세요.');
        }
        return ['name' => trim($input['name']), 'phone' => $input['phone'], 'birth' => null, 'ci' => 'dev:'.$input['phone']];
    }

    /** 실명인증 업체 연동 자리: 업체 인증 결과(이름·번호·생년월일·CI)를 조회해 돌려준다. */
    private function verifyWithProvider(string $provider, array $input): array
    {
        $this->fail(503, 'IDENTITY_NOT_AVAILABLE', '본인인증을 준비하고 있어요. 잠시 후 다시 시도해주세요.');
    }

    private function mask(string $name): string
    {
        $chars = mb_str_split($name);
        if (count($chars) <= 1) return $name;
        if (count($chars) === 2) return $chars[0].'*';
        return $chars[0].str_repeat('*', count($chars) - 2).end($chars);
    }

    private function fail(int $status, string $code, string $message): never
    {
        throw new HttpResponseException(response()->json(compact('code', 'message'), $status));
    }
}
