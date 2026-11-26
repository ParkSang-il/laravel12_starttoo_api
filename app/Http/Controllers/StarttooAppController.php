<?php

namespace App\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * 앱 업데이트 안내. 로그인 없이 앱 시작할 때 부른다.
 * .env STARTTOO_APP_MIN_VERSION(이보다 낮으면 강제) · STARTTOO_APP_LATEST_VERSION(이보다 낮으면 권장).
 * STARTTOO_APP_MAINTENANCE_MESSAGE 가 있으면 모든 버전을 점검 화면으로 막는다.
 * 앱이 version 을 보내면 서버가 판단한 update 값(none · recommended · required)과
 * 앱 화면용 status(ok · soft · force · maintenance)를 같이 준다.
 */
class StarttooAppController extends Controller
{
    public function version(Request $request): JsonResponse
    {
        $min = trim((string) config('starttoo.app_min_version'));
        $latest = trim((string) config('starttoo.app_latest_version'));
        $platform = $request->query('platform') === 'ios' ? 'ios' : 'android';
        $current = (string) $request->query('version', '');
        $valid = fn (string $v) => (bool) preg_match('/^\d+(\.\d+){0,3}$/', $v);

        $update = 'none';
        if ($valid($current)) {
            if ($valid($min) && version_compare($current, $min, '<')) $update = 'required';
            elseif ($valid($latest) && version_compare($current, $latest, '<')) $update = 'recommended';
        }
        $maintenance = trim((string) config('starttoo.app_maintenance_message'));
        $message = trim((string) config('starttoo.app_update_message'));
        $status = $maintenance !== '' ? 'maintenance' : match ($update) {
            'required' => 'force',
            'recommended' => 'soft',
            default => 'ok',
        };

        return response()->json([
            'status' => $status,
            'current_version' => $valid($current) ? $current : null,
            'min_version' => $valid($min) ? $min : null,
            'latest_version' => $valid($latest) ? $latest : null,
            'update' => $update,
            'store_url' => (string) config($platform === 'ios' ? 'starttoo.app_store_url' : 'starttoo.play_store_url') ?: null,
            'message' => match ($status) {
                'maintenance' => $maintenance,
                'force', 'soft' => $message !== '' ? $message : null,
                default => null,
            },
            'soft_snooze_days' => max(1, (int) config('starttoo.app_soft_snooze_days', 7)),
        ])->header('Cache-Control', 'public, max-age=60');
    }
}
