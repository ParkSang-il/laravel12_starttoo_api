<?php

namespace App\Services;

use App\Models\PhoneVerification;
use Carbon\Carbon;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

class PhoneVerificationService
{
    /**
     * 휴대폰 번호 유효성 검사
     *
     * @param string $phone
     * @return void
     * @throws ValidationException
     */
    public function validatePhoneNumber(string $phone): void
    {
        $validator = Validator::make(['phone' => $phone], [
            'phone' => 'required|string|regex:/^[0-9]{10,11}$/',
        ]);

        if ($validator->fails()) {
            throw ValidationException::withMessages($validator->errors()->toArray());
        }
    }

    /**
     * 만료된 인증번호 삭제 (인증되지 않은 것만)
     *
     * @param string $phone
     * @return void
     */
    public function deleteExpiredVerifications(string $phone): void
    {
        PhoneVerification::where('phone', $phone)
            ->where('expires_at', '<', Carbon::now())
            ->whereNull('verified_at')
            ->delete();
    }

    /**
     * 인증되지 않은 인증번호 모두 삭제
     *
     * @param string $phone
     * @return void
     */
    public function deleteUnverifiedVerifications(string $phone): void
    {
        PhoneVerification::where('phone', $phone)
            ->whereNull('verified_at')
            ->delete();
    }

    /**
     * 발송 횟수 제한 확인 (미인증된 것과 인증된 것 모두 포함)
     *
     * @param string $phone
     * @return bool true: 제한 초과, false: 정상
     */
    public function checkVerificationLimit(string $phone): bool
    {
        $threeHoursAgo = Carbon::now()->subHours(3);
        $recentVerificationCount = PhoneVerification::where('phone', $phone)
            ->where('created_at', '>=', $threeHoursAgo)
            ->count();

        return $recentVerificationCount >= 5;
    }

    /**
     * 인증번호 생성 및 저장
     *
     * @param string $phone
     * @return array [verification_code, expires_at]
     */
    public function createVerificationCode(string $phone): array
    {
        // 테스트 환경: 인증번호를 111111로 고정
        $verificationCode = '111111';
        $expiresAt = Carbon::now()->addMinutes(3);

        PhoneVerification::create([
            'phone' => $phone,
            'verification_code' => $verificationCode,
            'expires_at' => $expiresAt,
        ]);

        return [
            'verification_code' => $verificationCode,
            'expires_at' => $expiresAt,
        ];
    }

    /**
     * 가장 최근 인증번호 레코드 조회 (인증되지 않은 것)
     *
     * @param string $phone
     * @return PhoneVerification|null
     */
    public function findLatestUnverifiedVerification(string $phone): ?PhoneVerification
    {
        return PhoneVerification::where('phone', $phone)
            ->whereNull('verified_at')
            ->orderBy('created_at', 'desc')
            ->first();
    }

    /**
     * 인증번호 검증
     *
     * @param string $phone
     * @param string $verificationCode
     * @return PhoneVerification
     * @throws \RuntimeException
     */
    public function verifyCode(string $phone, string $verificationCode): PhoneVerification
    {
        // 가장 최근 인증번호 레코드 조회
        $phoneVerification = $this->findLatestUnverifiedVerification($phone);

        // 인증번호 레코드가 없는 경우
        if (!$phoneVerification) {
            throw new \RuntimeException('인증번호를 찾을 수 없습니다. 인증번호를 다시 발송해주세요.', 404);
        }

        // 만료 시간 확인
        if (Carbon::now()->gt($phoneVerification->expires_at)) {
            throw new \RuntimeException('인증번호가 만료되었습니다. 인증번호를 다시 발송해주세요.', 400);
        }

        // 인증번호 일치 확인
        if ($phoneVerification->verification_code !== $verificationCode) {
            throw new \RuntimeException('인증번호가 일치하지 않습니다.', 400);
        }

        // 인증 완료 처리 (verified_at 업데이트)
        $phoneVerification->update([
            'verified_at' => Carbon::now(),
        ]);

        // 인증 완료 후 만료된 인증번호 삭제 (발송 횟수에 영향을 주지 않도록 인증 완료 시점에 삭제)
        $this->deleteExpiredVerifications($phone);

        return $phoneVerification;
    }

    /**
     * 휴대폰 번호로 사용자 조회
     *
     * @param string $phone
     * @return \App\Models\User|null
     */
    public function findUserByPhone(string $phone): ?\App\Models\User
    {
        return \App\Models\User::where('phone', $phone)->first();
    }

    /**
     * 만료된 인증번호 삭제 (클라이언트에서 만료 감지 후 호출)
     *
     * @param string $phone
     * @return int 삭제된 레코드 수
     */
    public function deleteExpiredVerificationsOnExpiry(string $phone): int
    {
        return PhoneVerification::where('phone', $phone)
            ->where('expires_at', '<', Carbon::now())
            ->whereNull('verified_at')
            ->delete();
    }

    /**
     * 최근 5분 이내 인증된 기록이 있는지 확인
     *
     * @param string $phone
     * @return bool true: 인증 기록 있음, false: 인증 기록 없음
     */
    public function hasRecentVerification(string $phone): bool
    {
        $fiveMinutesAgo = Carbon::now()->subMinutes(5);
        
        return PhoneVerification::where('phone', $phone)
            ->whereNotNull('verified_at')
            ->where('verified_at', '>=', $fiveMinutesAgo)
            ->exists();
    }
}

