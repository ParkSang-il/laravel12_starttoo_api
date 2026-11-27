<?php

namespace App\Services\Starttoo;

use App\Services\ProjectDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

/**
 * 외부 광고주 계정(웹 /ads/account). 앱 회원 · 운영자 계정과 완전히 따로다.
 * - 계정 행은 운영자가 견적을 만들 때 견적 이메일로 생긴다(비밀번호 없음 = 로그인 불가).
 * - 비밀번호는 이메일 소유가 확인된 곳에서만 만든다: 견적서 비밀 링크 · 비밀번호 설정 메일 링크.
 * - 로그인 5번 틀리면 15분 잠금.
 */
class Advertisers
{
    public const MAX_FAILURES = 5;
    public const LOCK_MINUTES = 15;

    private function db() { return app(ProjectDatabase::class)->database(); }

    public static function normEmail(string $email): string
    {
        return mb_strtolower(trim($email), 'UTF-8');
    }

    public function find(int $id): ?object
    {
        return $this->db()->table('starttoo_advertisers')->where('id', $id)->first();
    }

    public function findByEmail(string $email): ?object
    {
        return $this->db()->table('starttoo_advertisers')->where('email', self::normEmail($email))->first();
    }

    /** 견적 이메일로 계정을 찾거나 만든다(비밀번호 · 이미 있는 정보는 건드리지 않는다) */
    public function findOrCreate(string $email, array $attrs): object
    {
        $email = self::normEmail($email);
        $row = $this->findByEmail($email);
        if ($row) {
            $fill = array_filter([
                'contact_name' => $row->contact_name ? null : ($attrs['contact_name'] ?? null),
                'phone' => $row->phone ? null : ($attrs['phone'] ?? null),
            ]);
            if ($fill) $this->db()->table('starttoo_advertisers')->where('id', $row->id)->update($fill + ['updated_at' => now()]);
            return $this->find((int) $row->id);
        }
        $this->db()->table('starttoo_advertisers')->insertOrIgnore([
            'email' => $email,
            'company' => mb_substr((string) ($attrs['company'] ?? $email), 0, 100),
            'contact_name' => $attrs['contact_name'] ?? null,
            'phone' => $attrs['phone'] ?? null,
            'created_at' => now(), 'updated_at' => now(),
        ]);
        return $this->findByEmail($email);
    }

    /**
     * 로그인. 성공하면 계정, 아니면 사람이 읽을 사유(InvalidArgumentException).
     * 계정이 없거나 비밀번호를 아직 안 만든 경우도 같은 문구로 답한다(가입 여부를 알려 주지 않게).
     */
    public function attempt(string $email, string $password): object
    {
        $row = $this->findByEmail($email);
        $wrong = '이메일 또는 비밀번호를 확인해주세요.';
        if (!$row || !$row->password) {
            // 응답 시간 차이로 가입 여부가 드러나지 않게 같은 비용의 해시 비교를 한 번 한다
            try { Hash::check($password, '$2y$12$' . str_repeat('a', 53)); } catch (\Throwable) {}
            throw new \InvalidArgumentException($wrong);
        }
        if ($row->locked_until && now()->lt($row->locked_until)) {
            throw new \InvalidArgumentException('로그인을 여러 번 틀려 잠시 막혔어요. ' . self::LOCK_MINUTES . '분 뒤에 다시 해주세요.');
        }
        if (!Hash::check($password, $row->password)) {
            $fails = (int) $row->failed_logins + 1;
            $this->db()->table('starttoo_advertisers')->where('id', $row->id)->update([
                'failed_logins' => $fails >= self::MAX_FAILURES ? 0 : $fails,
                'locked_until' => $fails >= self::MAX_FAILURES ? now()->addMinutes(self::LOCK_MINUTES) : null,
                'updated_at' => now(),
            ]);
            throw new \InvalidArgumentException($wrong);
        }
        $this->db()->table('starttoo_advertisers')->where('id', $row->id)->update([
            'failed_logins' => 0, 'locked_until' => null, 'last_login_at' => now(), 'updated_at' => now(),
        ]);
        return $this->find((int) $row->id);
    }

    public function setPassword(int $id, string $password): void
    {
        $this->db()->table('starttoo_advertisers')->where('id', $id)->update([
            'password' => Hash::make($password), 'password_set_at' => now(),
            'failed_logins' => 0, 'locked_until' => null, 'updated_at' => now(),
        ]);
        // 비밀번호가 바뀌면 남은 설정 링크는 모두 막는다
        $this->db()->table('starttoo_advertiser_tokens')->where('advertiser_id', $id)->whereNull('used_at')->update(['used_at' => now()]);
    }

    public function checkPassword(object $row, string $password): bool
    {
        return $row->password && Hash::check($password, $row->password);
    }

    /** 비밀번호 설정 · 재설정 메일 링크(1회용 · 해시만 저장) */
    public function issueLink(int $id): string
    {
        $token = Str::random(48);
        $this->db()->table('starttoo_advertiser_tokens')->insert([
            'advertiser_id' => $id, 'token_hash' => hash('sha256', $token),
            'expires_at' => now()->addMinutes((int) config('advertiser.password_link_minutes', 60)),
            'created_at' => now(),
        ]);
        return AdSales::link('advertiser.password.reset', $token);
    }

    /** 링크가 살아 있으면 그 계정(쓰지는 않음) */
    public function linkOwner(string $token): ?object
    {
        $t = $this->db()->table('starttoo_advertiser_tokens')->where('token_hash', hash('sha256', $token))
            ->whereNull('used_at')->where('expires_at', '>', now())->first();
        return $t ? $this->find((int) $t->advertiser_id) : null;
    }

    /** 비밀번호를 메일로 받을 링크 보내기(계정이 없으면 아무것도 안 하지만 같은 응답) */
    public function sendPasswordLink(string $email): bool
    {
        $row = $this->findByEmail($email);
        if (!$row) return false;
        // 최근 1분 안에 보낸 링크가 있으면 다시 보내지 않는다(메일 폭탄 방지)
        $recent = $this->db()->table('starttoo_advertiser_tokens')->where('advertiser_id', $row->id)
            ->where('created_at', '>', now()->subMinute())->exists();
        if ($recent) return false;
        $url = $this->issueLink((int) $row->id);
        return app(AdSales::class)->mail($row->email, '[STARTTOO 광고] 비밀번호 설정 링크', [
            "{$row->company} 담당자님, 광고주 계정 비밀번호를 설정할 수 있는 링크예요.",
            '',
            $url,
            '',
            '링크는 ' . config('advertiser.password_link_minutes', 60) . '분 동안 한 번만 쓸 수 있어요.',
            '요청하지 않았다면 이 메일을 무시해주세요. 비밀번호는 바뀌지 않아요.',
        ]);
    }

    public function useLink(string $token, string $password): ?object
    {
        $hash = hash('sha256', $token);
        $t = $this->db()->table('starttoo_advertiser_tokens')->where('token_hash', $hash)
            ->whereNull('used_at')->where('expires_at', '>', now())->first();
        if (!$t) return null;
        $this->db()->table('starttoo_advertiser_tokens')->where('id', $t->id)->update(['used_at' => now()]);
        $this->setPassword((int) $t->advertiser_id, $password);
        return $this->find((int) $t->advertiser_id);
    }

    /** 내 정보(회사 · 담당자 · 세금계산서 정보) */
    public function updateProfile(int $id, array $in): void
    {
        $this->db()->table('starttoo_advertisers')->where('id', $id)->update([
            'company' => $in['company'],
            'contact_name' => $in['contact_name'] ?? null,
            'phone' => $in['phone'] ?? null,
            'biz_no' => $in['biz_no'] ?? null,
            'biz_name' => $in['biz_name'] ?? null,
            'tax_email' => $in['tax_email'] ?? null,
            'updated_at' => now(),
        ]);
    }

    /** 세금계산서 정보 기억(입금 알림 때) */
    public function rememberTax(int $id, array $in): void
    {
        $this->db()->table('starttoo_advertisers')->where('id', $id)->update([
            'biz_no' => $in['biz_no'], 'biz_name' => $in['biz_name'], 'tax_email' => $in['tax_email'], 'updated_at' => now(),
        ]);
    }

    /** 운영 콘솔 광고주 목록 */
    public function page(int $page, int $per, string $q = ''): array
    {
        $query = $this->db()->table('starttoo_advertisers as a')
            ->when($q !== '', fn ($w) => $w->where(fn ($x) => $x->where('a.email', 'like', '%' . addcslashes($q, '%_') . '%')
                ->orWhere('a.company', 'like', '%' . addcslashes($q, '%_') . '%')));
        $total = (clone $query)->count();
        $rows = $query->orderByDesc('a.id')->offset(($page - 1) * $per)->limit($per)->get(['a.*'])->all();
        $ids = array_map(fn ($r) => (int) $r->id, $rows);
        $counts = $ids ? $this->db()->table('starttoo_ad_contracts')->whereIn('advertiser_id', $ids)
            ->whereNotIn('status', ['cancelled'])->groupBy('advertiser_id')
            ->selectRaw('advertiser_id, COUNT(*) n, SUM(CASE WHEN paid_at IS NOT NULL THEN total ELSE 0 END) paid')
            ->get()->keyBy('advertiser_id') : collect();
        foreach ($rows as $r) {
            $r->contracts = (int) ($counts[$r->id]->n ?? 0);
            $r->paid_total = (int) ($counts[$r->id]->paid ?? 0);
        }
        return [$rows, $total];
    }
}
