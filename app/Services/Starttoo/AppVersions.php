<?php

namespace App\Services\Starttoo;

use App\Services\ProjectDatabase;
use Illuminate\Support\Carbon;

/**
 * 앱 버전 · 강제 업데이트(운영 콘솔 M-19).
 * 플랫폼(ios · android)별 정책을 starttoo_settings('app_version')에 두고, 저장한 적이 없으면 .env 값을 쓴다.
 * 판정(status): maintenance 점검 → force 최소 지원 미만(단계적 적용이면 enforce_at 이후) → soft 권장 미만 → ok
 * - 앱 API(StarttooDatabase): 헤더 X-App-Platform · X-App-Version 이 있으면 점검 503 · 강제 + API 차단 426
 * - 버전 확인(GET app/version): 판정 결과 + 버전별 확인 수 기록(버전 분포)
 */
class AppVersions
{
    public const PLATFORMS = ['ios' => 'iOS', 'android' => 'Android'];
    public const STAGED_HOURS = 24;
    private const DEFAULT_FORCE = '더 안전하게 쓰려면 업데이트가 필요해요. 업데이트 후 계속 이용할 수 있어요.';
    private const DEFAULT_SOFT = '새 버전이 나왔어요. 업데이트하고 새 기능을 써 보세요.';
    private const DEFAULT_MAINTENANCE = '더 나은 서비스를 위해 점검 중이에요. 잠시 후 다시 이용해주세요.';

    private function db() { return app(ProjectDatabase::class)->database(); }

    public static function valid(?string $v): bool
    {
        return is_string($v) && (bool) preg_match('/^\d+(\.\d+){0,3}$/', $v);
    }

    /** "1.4.2+57" · "v1.4" → 1.4.2 · 1.4.0 */
    public static function normalize(?string $version): string
    {
        $v = ltrim(trim(explode('+', (string) $version)[0]), 'vV');
        if (!preg_match('/^\d+(\.\d+){0,3}$/', $v)) return '';
        $parts = array_map('intval', array_slice(explode('.', $v), 0, 3));
        return implode('.', array_pad($parts, 3, 0));
    }

    /** 플랫폼 정책: 저장값 ← 없으면 .env */
    public function policy(string $platform): array
    {
        $stored = app(Settings::class)->json('app_version')[$platform] ?? [];
        $min = self::normalize(config('starttoo.app_min_version'));
        $latest = self::normalize(config('starttoo.app_latest_version'));
        $envMaintenance = trim((string) config('starttoo.app_maintenance_message'));
        $envMessage = trim((string) config('starttoo.app_update_message'));
        return array_merge([
            'min_version' => $min, 'recommended_version' => $latest, 'latest_version' => $latest,
            'store_url' => (string) config($platform === 'ios' ? 'starttoo.app_store_url' : 'starttoo.play_store_url'),
            'force_message' => $envMessage !== '' ? $envMessage : self::DEFAULT_FORCE,
            'soft_message' => $envMessage !== '' ? $envMessage : self::DEFAULT_SOFT,
            'maintenance' => $envMaintenance !== '',
            'maintenance_message' => $envMaintenance !== '' ? $envMaintenance : self::DEFAULT_MAINTENANCE,
            'block_api' => true, 'staged' => false, 'enforce_at' => null,
        ], $stored);
    }

    public function isForced(array $p, string $version): bool
    {
        if ($version === '' || !self::valid($p['min_version']) || version_compare($version, $p['min_version'], '>=')) return false;
        return !$p['staged'] || !$p['enforce_at'] || now()->gte(Carbon::parse($p['enforce_at']));
    }

    public function evaluate(string $platform, ?string $version): array
    {
        $p = $this->policy($platform);
        $version = self::normalize($version);
        $status = match (true) {
            (bool) $p['maintenance'] => 'maintenance',
            $this->isForced($p, $version) => 'force',
            $version !== '' && self::valid($p['recommended_version']) && version_compare($version, $p['recommended_version'], '<') => 'soft',
            default => 'ok',
        };
        return [
            'status' => $status,
            // 이전 앱 빌드 호환: none · recommended · required
            'update' => ['force' => 'required', 'soft' => 'recommended'][$status] ?? 'none',
            'current_version' => $version !== '' ? $version : null,
            'min_version' => self::valid($p['min_version']) ? $p['min_version'] : null,
            'recommended_version' => self::valid($p['recommended_version']) ? $p['recommended_version'] : null,
            'latest_version' => self::valid($p['latest_version']) ? $p['latest_version'] : null,
            'store_url' => $p['store_url'] !== '' ? $p['store_url'] : null,
            'message' => match ($status) {
                'maintenance' => $p['maintenance_message'],
                'force' => $p['force_message'],
                'soft' => $p['soft_message'],
                default => null,
            },
            'soft_snooze_days' => max(1, (int) config('starttoo.app_soft_snooze_days', 7)),
        ];
    }

    /** 앱 API 차단 판정: 503 점검 · 426 강제 업데이트(API 차단 켬) · null 통과 */
    public function blockFor(?string $platform, ?string $version): ?array
    {
        $platform = strtolower((string) $platform);
        if (!isset(self::PLATFORMS[$platform]) || self::normalize($version) === '') return null;
        $result = $this->evaluate($platform, $version);
        if ($result['status'] === 'maintenance') return [503, ['code' => 'MAINTENANCE', 'message' => $result['message']] + $result];
        if ($result['status'] === 'force' && $this->policy($platform)['block_api']) {
            return [426, ['code' => 'UPGRADE_REQUIRED', 'message' => $result['message']] + $result];
        }
        return null;
    }

    /** 버전 분포용: 오늘 이 버전의 확인 수 +1 */
    public function record(string $platform, ?string $version): void
    {
        $version = self::normalize($version);
        if (!isset(self::PLATFORMS[$platform]) || $version === '') return;
        $key = ['day' => today()->toDateString(), 'platform' => $platform, 'version' => $version];
        $updated = $this->db()->table('starttoo_app_version_stats')->where($key)->increment('checks');
        if (!$updated) $this->db()->table('starttoo_app_version_stats')->insertOrIgnore($key + ['checks' => 1]);
    }

    /** 최근 7일 버전별 확인 수(높은 버전 먼저) */
    public function distribution(string $platform): array
    {
        return $this->db()->table('starttoo_app_version_stats')->where('platform', $platform)
            ->where('day', '>=', today()->subDays(6)->toDateString())
            ->groupBy('version')->selectRaw('version, SUM(checks) as n')->pluck('n', 'version')
            ->map(fn ($n) => (int) $n)->sortKeysUsing(fn ($a, $b) => version_compare($b, $a))->all();
    }

    /**
     * 콘솔 스텝퍼가 고르는 버전 목록(낮은 순): .env STARTTOO_APP_RELEASES + 실제로 확인된 버전 + 지금 정책 값
     */
    public function releases(string $platform): array
    {
        $list = array_filter(array_map(fn ($v) => self::normalize(trim($v)), explode(',', (string) config('starttoo.app_releases', '1.0.0'))));
        $list = array_merge($list, array_keys($this->distribution($platform)));
        $p = $this->policy($platform);
        foreach (['min_version', 'recommended_version', 'latest_version'] as $k) if (self::valid($p[$k])) $list[] = self::normalize($p[$k]);
        $list = array_values(array_unique(array_filter($list)));
        usort($list, 'version_compare');
        return $list ?: ['1.0.0'];
    }

    public function save(string $platform, array $values, ?int $adminId): void
    {
        $settings = app(Settings::class);
        $all = $settings->json('app_version') ?? [];
        $all[$platform] = array_merge($this->policy($platform), $values);
        $settings->set('app_version', $all, $adminId);
    }
}
