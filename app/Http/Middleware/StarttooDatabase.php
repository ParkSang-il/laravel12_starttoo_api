<?php

namespace App\Http\Middleware;

use App\Services\ProjectDatabase;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;

class StarttooDatabase
{
    public function handle(Request $request, Closure $next)
    {
        // 기존 앱의 JWT 가드를 호출하지 않고 IP와 경로로 요청 횟수를 제한한다.
        $limit = $request->isMethod('GET') ? 120 : ($request->is('*/google') ? 10 : 30);
        $key = 'starttoo:auth:'.hash('sha256', $request->ip().':'.$request->path());
        if (RateLimiter::tooManyAttempts($key, $limit)) {
            return response()->json(['code' => 'RATE_LIMITED', 'message' => '잠시 후 다시 시도해주세요.'], 429)
                ->header('Retry-After', RateLimiter::availableIn($key));
        }
        RateLimiter::hit($key, 60);

        // 서버에 고정된 프로젝트 코드만 사용하며 입력값으로 DB를 선택하지 않는다.
        return app(ProjectDatabase::class)->run('starttoo', fn () => $next($request));
    }
}
