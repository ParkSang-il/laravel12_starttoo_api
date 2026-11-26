<?php

namespace App\Services\Starttoo;

use App\Services\ProjectDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Hash;
use PragmaRX\Google2FA\Google2FA;

/**
 * 운영 콘솔 관리자 계정. 앱 회원(JWT)과 완전히 분리된 계정 · 세션 · 2단계 인증(인증 앱 6자리).
 * - 5회 연속 실패하면 30분 잠금(계정 단위). 로그인 주소에는 IP 단위 throttle 도 걸려 있다.
 * - 같은 6자리 코드는 한 번만 쓸 수 있다(재사용 막기).
 * - 계정 생성 · 인증 앱 다시 등록은 서버 명령(starttoo:admin-create · starttoo:admin-otp)으로만 한다.
 */
class AdminAccounts
{
    public const ROLES = ['super' => '최고 관리자', 'operator' => '운영자', 'cs' => 'CS 담당', 'viewer' => '열람 전용'];
    public const MAX_FAILURES = 5;
    public const LOCK_MINUTES = 30;

    /**
     * 역할별로 할 수 있는 처리(docs/admin.md 결정, 2026-11-26).
     * 조회는 모든 역할이 할 수 있다. 여기 없는 처리는 막는다.
     */
    private const ABILITIES = [
        'super' => ['*'],
        'operator' => ['artist.review', 'report.dismiss', 'report.hide', 'report.warn', 'report.suspend',
            'content.hide', 'content.delete', 'content.recommend', 'content.fit', 'ads.manage', 'export', 'license.view',
            'ai.manage', 'notice.manage', 'push.send'],
        'cs' => ['report.dismiss', 'report.hide', 'report.warn', 'content.hide', 'license.view'],
        'viewer' => [],
    ];

    private function db() { return app(ProjectDatabase::class)->database(); }

    public function can(?object $admin, string $ability): bool
    {
        if (!$admin) return false;
        $list = self::ABILITIES[$admin->role] ?? [];
        return in_array('*', $list, true) || in_array($ability, $list, true);
    }

    public function roleLabel(string $role): string
    {
        return self::ROLES[$role] ?? $role;
    }

    public function find(int $id): ?object
    {
        return $this->db()->table('starttoo_admins')->where('id', $id)->whereNull('disabled_at')->first();
    }

    /**
     * 이메일 · 비밀번호 · 6자리 코드 확인. 성공하면 관리자, 실패하면 InvalidArgumentException(화면에 보일 문구).
     * 없는 이메일도 같은 문구로 답해 계정이 있는지 알 수 없게 한다.
     */
    public function attempt(string $email, string $password, string $otp, string $ip): object
    {
        $email = mb_strtolower(trim($email));
        $wrong = '이메일, 비밀번호 또는 인증 코드를 확인해주세요.';
        $admin = $this->db()->table('starttoo_admins')->where('email', $email)->first();
        if (!$admin || $admin->disabled_at) {
            Hash::check($password, '$2y$12$'.str_repeat('a', 53)); // 응답 시간을 맞춘다
            throw new \InvalidArgumentException($wrong);
        }
        if ($admin->locked_until && now()->lt($admin->locked_until)) {
            $minutes = max(1, (int) ceil(abs(now()->diffInSeconds(\Illuminate\Support\Carbon::parse($admin->locked_until))) / 60));
            throw new \InvalidArgumentException("로그인을 5회 실패해 계정이 잠겼어요. {$minutes}분 뒤에 다시 시도해주세요.");
        }
        $ok = Hash::check($password, $admin->password) && $admin->otp_secret && $this->verifyOtp($admin, $otp);
        if (!$ok) {
            $failures = (int) $admin->failed_attempts + 1;
            $this->db()->table('starttoo_admins')->where('id', $admin->id)->update([
                'failed_attempts' => $failures >= self::MAX_FAILURES ? 0 : $failures,
                'locked_until' => $failures >= self::MAX_FAILURES ? now()->addMinutes(self::LOCK_MINUTES) : $admin->locked_until,
                'updated_at' => now(),
            ]);
            app(AdminAudit::class)->record($admin, '접속', $failures >= self::MAX_FAILURES ? '로그인 실패 · 30분 잠금' : '로그인 실패', '-', $ip);
            throw new \InvalidArgumentException($failures >= self::MAX_FAILURES ? '로그인을 5회 실패해 계정이 30분간 잠겼어요.' : $wrong);
        }
        if (Hash::needsRehash($admin->password)) {
            $this->db()->table('starttoo_admins')->where('id', $admin->id)->update(['password' => Hash::make($password)]);
        }
        $this->db()->table('starttoo_admins')->where('id', $admin->id)->update([
            'failed_attempts' => 0, 'locked_until' => null, 'last_login_at' => now(), 'last_login_ip' => $ip, 'updated_at' => now(),
        ]);
        return $this->find((int) $admin->id) ?? throw new \InvalidArgumentException($wrong);
    }

    private function verifyOtp(object $admin, string $otp): bool
    {
        if (!preg_match('/^\d{6}$/', $otp)) return false;
        $secret = Crypt::decryptString($admin->otp_secret);
        $used = 'starttoo:admin-otp:'.$admin->id.':'.$otp;
        if (Cache::has($used)) return false;
        // 앞뒤 30초(window 1)까지 허용
        if (!app(Google2FA::class)->verifyKey($secret, $otp, 1)) return false;
        Cache::put($used, true, 120);
        return true;
    }

    // ── 서버 명령 ────────────────────────────────

    /** 관리자 만들기. 인증 앱에 넣을 비밀값과 otpauth 주소를 돌려준다(명령 화면에서 한 번만 보여 준다). */
    public function create(string $email, string $name, string $role, string $password): array
    {
        $email = mb_strtolower(trim($email));
        $name = trim($name);
        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) throw new \InvalidArgumentException('이메일을 확인해주세요.');
        if ($name === '' || mb_strlen($name) > 50) throw new \InvalidArgumentException('이름은 1~50자로 적어주세요.');
        if (!isset(self::ROLES[$role])) throw new \InvalidArgumentException('역할은 '.implode(' · ', array_keys(self::ROLES)).' 중 하나예요.');
        if (mb_strlen($password) < 12) throw new \InvalidArgumentException('비밀번호는 12자 이상으로 정해주세요.');
        if ($this->db()->table('starttoo_admins')->where('email', $email)->exists()) throw new \InvalidArgumentException('이미 등록된 이메일이에요.');
        $secret = app(Google2FA::class)->generateSecretKey(32);
        $id = $this->db()->table('starttoo_admins')->insertGetId([
            'email' => $email, 'password' => Hash::make($password), 'name' => $name, 'role' => $role,
            'otp_secret' => Crypt::encryptString($secret), 'created_at' => now(), 'updated_at' => now(),
        ]);
        return ['id' => $id, 'secret' => $secret, 'otpauth' => $this->otpauth($email, $secret)];
    }

    /** 인증 앱 다시 등록(휴대폰 분실 등): 새 비밀값 */
    public function resetOtp(string $email): array
    {
        $admin = $this->db()->table('starttoo_admins')->where('email', mb_strtolower(trim($email)))->first();
        if (!$admin) throw new \InvalidArgumentException('그 이메일의 관리자가 없어요.');
        $secret = app(Google2FA::class)->generateSecretKey(32);
        $this->db()->table('starttoo_admins')->where('id', $admin->id)->update(['otp_secret' => Crypt::encryptString($secret), 'updated_at' => now()]);
        return ['secret' => $secret, 'otpauth' => $this->otpauth($admin->email, $secret)];
    }

    public function setRole(string $email, string $role): void
    {
        if (!isset(self::ROLES[$role])) throw new \InvalidArgumentException('역할은 '.implode(' · ', array_keys(self::ROLES)).' 중 하나예요.');
        $n = $this->db()->table('starttoo_admins')->where('email', mb_strtolower(trim($email)))->update(['role' => $role, 'updated_at' => now()]);
        if (!$n) throw new \InvalidArgumentException('그 이메일의 관리자가 없어요.');
    }

    public function setDisabled(string $email, bool $disabled): void
    {
        $n = $this->db()->table('starttoo_admins')->where('email', mb_strtolower(trim($email)))
            ->update(['disabled_at' => $disabled ? now() : null, 'updated_at' => now()]);
        if (!$n) throw new \InvalidArgumentException('그 이메일의 관리자가 없어요.');
    }

    public function setPassword(string $email, string $password): void
    {
        if (mb_strlen($password) < 12) throw new \InvalidArgumentException('비밀번호는 12자 이상으로 정해주세요.');
        $n = $this->db()->table('starttoo_admins')->where('email', mb_strtolower(trim($email)))
            ->update(['password' => Hash::make($password), 'failed_attempts' => 0, 'locked_until' => null, 'updated_at' => now()]);
        if (!$n) throw new \InvalidArgumentException('그 이메일의 관리자가 없어요.');
    }

    private function otpauth(string $email, string $secret): string
    {
        return app(Google2FA::class)->getQRCodeUrl('STARTTOO 운영 콘솔', $email, $secret);
    }
}
