<?php

namespace App\Services\Starttoo;

use App\Services\ProjectDatabase;
use Illuminate\Support\Facades\Cache;

/**
 * 운영 콘솔에서 바꾸는 정책 값(starttoo_settings). 저장된 값이 없으면 .env(config) 기본값을 쓴다.
 * 요청마다 DB를 읽지 않도록 1분 캐시하고, 바꾸면 바로 캐시를 지운다.
 */
class Settings
{
    /** 콘솔 「운영 정책」 스위치: 앱 · 서버에 실제로 연결된 것만 둔다. */
    public const POLICIES = [
        'guest_browsing' => ['label' => '비회원 피드 공개', 'desc' => '로그인 없이 공개 피드 · 작가 검색 허용'],
        'report_auto_hide' => ['label' => '신고 누적 자동 숨김', 'desc' => '서로 다른 회원 신고 5회 이상이면 검토 전까지 숨김'],
        'feed_ranking' => ['label' => '홈 피드 추천 순서', 'desc' => '회원별 관심 · 반응으로 홈 피드 순서를 정함(끄면 최신순)'],
    ];
    public const AUTO_HIDE_REPORTS = 5;

    private function db() { return app(ProjectDatabase::class)->database(); }

    public function bool(string $key): bool
    {
        $value = $this->stored()[$key] ?? null;
        return $value === null ? $this->default($key) : (bool) $value;
    }

    /** 숫자 값(AI 하루 한도 등). 저장된 값이 없으면 .env 기본값 */
    public function int(string $key): int
    {
        $value = $this->stored()[$key] ?? null;
        if ($value !== null) return (int) $value;
        return match ($key) {
            'fit_daily_limit' => (int) config('starttoo.fit_daily_limit', 3),
            // 아티스트 한도를 따로 정하지 않았으면 일반 회원과 같다
            'fit_artist_daily_limit' => $this->int('fit_daily_limit'),
            default => 0,
        };
    }

    /** 묶음 값(앱 버전 정책 등). 저장된 값이 없으면 null */
    public function json(string $key): ?array
    {
        $value = $this->stored()[$key] ?? null;
        return is_array($value) ? $value : null;
    }

    public function set(string $key, mixed $value, ?int $adminId): void
    {
        $this->db()->table('starttoo_settings')->updateOrInsert(['key' => $key],
            ['value' => json_encode($value, JSON_THROW_ON_ERROR), 'updated_by' => $adminId, 'updated_at' => now()]);
        Cache::forget($this->cacheKey());
    }

    private function default(string $key): bool
    {
        return match ($key) {
            'guest_browsing' => (bool) config('starttoo.guest_browsing_enabled'),
            'report_auto_hide' => false,
            'feed_ranking' => true,
            'ai_budget_block' => false,
            default => false,
        };
    }

    private function stored(): array
    {
        return Cache::remember($this->cacheKey(), 60, fn () => $this->db()->getSchemaBuilder()->hasTable('starttoo_settings')
            ? $this->db()->table('starttoo_settings')->pluck('value', 'key')->map(fn ($v) => json_decode((string) $v, true))->all()
            : []);
    }

    private function cacheKey(): string
    {
        return 'starttoo:settings:'.app(ProjectDatabase::class)->code();
    }
}
