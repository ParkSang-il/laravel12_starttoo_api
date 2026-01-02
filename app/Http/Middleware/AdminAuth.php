<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

class AdminAuth
{
    /**
     * Handle an incoming request.
     *
     * @param  \Closure(\Illuminate\Http\Request): (\Symfony\Component\HttpFoundation\Response)  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        // 세션이 시작되었는지 확인
        if (!$request->hasSession()) {
            if ($request->expectsJson()) {
                return response()->json([
                    'success' => false,
                    'message' => '세션이 시작되지 않았습니다.',
                ], 500);
            }
            return redirect()->route('admin.login')->with('error', '세션 오류가 발생했습니다.');
        }

        // 세션에서 관리자 정보 확인 (디버깅용)
        $adminId = $request->session()->get('admin_id');
        $isAuthenticated = Auth::guard('admin')->check();

        if (!$isAuthenticated) {
            if ($request->expectsJson()) {
                return response()->json([
                    'success' => false,
                    'message' => '인증이 필요합니다.',
                ], 401);
            }
            return redirect()->route('admin.login');
        }

        // 관리자 세션 만료 시간 체크 (기본 30분)
        $adminSessionLifetime = env('ADMIN_SESSION_LIFETIME', 30); // 분 단위
        $loginAt = $request->session()->get('admin_login_at');
        
        if ($loginAt) {
            $loginTime = \Carbon\Carbon::parse($loginAt);
            $elapsedMinutes = $loginTime->diffInMinutes(now());
            
            // 세션 유지 시간 초과 시 자동 로그아웃
            if ($elapsedMinutes > $adminSessionLifetime) {
                Auth::guard('admin')->logout();
                $request->session()->invalidate();
                $request->session()->regenerateToken();
                
                if ($request->expectsJson()) {
                    return response()->json([
                        'success' => false,
                        'message' => '세션이 만료되었습니다. 다시 로그인해주세요.',
                    ], 401);
                }
                return redirect()->route('admin.login')->with('error', '세션이 만료되었습니다. 다시 로그인해주세요.');
            }
        }

        return $next($request);
    }
}

