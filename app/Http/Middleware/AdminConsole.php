<?php

namespace App\Http\Middleware;

use App\Services\ProjectDatabase;
use App\Services\Starttoo\AdminAccounts;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\IpUtils;

/**
 * 운영 콘솔 공통: 접속 IP 제한(.env STARTTOO_ADMIN_ALLOWED_IPS, 비어 있으면 끔) → 스타투 DB 선택
 * → (auth) 관리자 세션 확인 · 60분 미사용 시 로그아웃 → 검색엔진 · 캐시 · 프레임 차단 헤더.
 * 사용: ->middleware('admin.console') 로그인 화면, ->middleware('admin.console:auth') 그 밖의 화면
 */
class AdminConsole
{
    public function handle(Request $request, Closure $next, ?string $mode = null)
    {
        $allowed = array_values(array_filter(array_map('trim', explode(',', (string) config('starttoo.admin_allowed_ips')))));
        if ($allowed && !IpUtils::checkIp((string) $request->ip(), $allowed)) {
            abort(404);
        }
        // 콘솔 세션 쿠키: HTTPS 에서만 · 자바스크립트 접근 불가 · 다른 사이트 요청에 안 실림
        config(['session.secure' => $request->isSecure() || app()->isProduction(), 'session.http_only' => true, 'session.same_site' => 'lax']);

        $response = app(ProjectDatabase::class)->run('starttoo', function () use ($request, $next, $mode) {
            if ($mode === 'auth') {
                $admin = null;
                $id = (int) $request->session()->get('admin_id');
                $last = (int) $request->session()->get('admin_seen_at');
                $idle = (int) config('starttoo.admin_idle_minutes', 60) * 60;
                if ($id && $last && time() - $last <= $idle) {
                    $admin = app(AdminAccounts::class)->find($id);
                }
                if (!$admin) {
                    $request->session()->forget(['admin_id', 'admin_seen_at']);
                    return redirect()->route('admin.login')->with('status', $id ? '오래 사용하지 않아 로그아웃됐어요. 다시 로그인해주세요.' : null);
                }
                $request->session()->put('admin_seen_at', time());
                $request->attributes->set('admin', $admin);
                view()->share('admin', $admin);
            }
            return $next($request);
        });

        $response->headers->set('X-Robots-Tag', 'noindex, nofollow');
        $response->headers->set('Cache-Control', 'no-store, private');
        $response->headers->set('X-Frame-Options', 'DENY');
        $response->headers->set('Referrer-Policy', 'same-origin');
        return $response;
    }
}
