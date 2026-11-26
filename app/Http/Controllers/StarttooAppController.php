<?php

namespace App\Http\Controllers;

use App\Services\ProjectDatabase;
use App\Services\Starttoo\AppVersions;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * 앱 업데이트 안내. 로그인 없이 앱 시작 · 앞으로 올 때 부른다.
 * GET /api/v1/projects/starttoo/app/version?platform=ios&version=1.2.0
 * 정책은 운영 콘솔 「앱 버전 · 강제 업데이트」에서 정한다(저장 전에는 .env STARTTOO_APP_MIN_VERSION 등).
 * 응답: status(ok · soft · force · maintenance) · update(none · recommended · required, 이전 빌드 호환)
 *       · current_version · min_version · recommended_version · latest_version · store_url · message · soft_snooze_days
 */
class StarttooAppController extends Controller
{
    public function version(Request $request): JsonResponse
    {
        $platform = $request->query('platform') === 'ios' ? 'ios' : 'android';
        $version = (string) $request->query('version', '');

        $result = app(ProjectDatabase::class)->run('starttoo', function () use ($platform, $version) {
            $versions = app(AppVersions::class);
            // 버전 분포 집계가 실패해도 안내는 그대로 준다
            try { $versions->record($platform, $version); } catch (\Throwable $e) { report($e); }
            return $versions->evaluate($platform, $version);
        });

        // 점검 · 강제는 바로 반영되게 캐시하지 않는다
        return response()->json($result)->header('Cache-Control', 'no-store');
    }
}
