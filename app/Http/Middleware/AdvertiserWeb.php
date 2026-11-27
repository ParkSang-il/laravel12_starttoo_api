<?php

namespace App\Http\Middleware;

use App\Services\ProjectDatabase;
use App\Services\Starttoo\Advertisers;
use Closure;
use Illuminate\Http\Request;

/**
 * 광고주 웹(/ads) 공통: 스타투 DB 선택 → 로그인한 광고주를 화면에 넘김 → 모드별 헤더.
 *  advertiser.web          공개(문의 · 상품 소개 · 로그인)
 *  advertiser.web:private  비밀 링크(견적서 · 성과 보고 · 비밀번호 설정): 검색엔진 · 캐시 차단, 주소가 다른 사이트로 새지 않게
 *  advertiser.web:auth     + 광고주 로그인 필요(내 광고). 120분 쓰지 않으면 로그아웃
 */
class AdvertiserWeb
{
    public const IDLE_MINUTES = 120;

    public function handle(Request $request, Closure $next, ?string $mode = null)
    {
        config(['session.secure' => $request->isSecure() || app()->isProduction(), 'session.http_only' => true, 'session.same_site' => 'lax']);

        $response = app(ProjectDatabase::class)->run('starttoo', function () use ($request, $next, $mode) {
            $me = null;
            $id = (int) $request->session()->get('advertiser_id');
            $seen = (int) $request->session()->get('advertiser_seen_at');
            if ($id && $seen && time() - $seen <= self::IDLE_MINUTES * 60) {
                $me = app(Advertisers::class)->find($id);
                // 비밀번호가 바뀌면 다른 기기 세션은 끝낸다
                if ($me && (string) $request->session()->get('advertiser_pw') !== substr(hash('sha256', (string) $me->password), 0, 16)) $me = null;
            }
            if (!$me && $id) $request->session()->forget(['advertiser_id', 'advertiser_seen_at', 'advertiser_pw']);
            if ($me) $request->session()->put('advertiser_seen_at', time());
            if ($mode === 'auth' && !$me) {
                return redirect()->guest(route('advertiser.login'))->with('status', $id ? '오래 사용하지 않아 로그아웃됐어요. 다시 로그인해주세요.' : null);
            }
            $request->attributes->set('advertiser', $me);
            view()->share('advertiserMe', $me);
            return $next($request);
        });

        if ($mode === 'private' || $mode === 'auth') {
            $response->headers->set('X-Robots-Tag', 'noindex, nofollow');
            $response->headers->set('Cache-Control', 'no-store, private');
            $response->headers->set('Referrer-Policy', 'no-referrer');
        }
        $response->headers->set('X-Frame-Options', 'DENY');
        return $response;
    }

    /** 로그인 세션 시작(세션 고정 공격 방지로 새 세션 id) */
    public static function login(Request $request, object $advertiser): void
    {
        $request->session()->regenerate();
        $request->session()->put([
            'advertiser_id' => (int) $advertiser->id,
            'advertiser_seen_at' => time(),
            'advertiser_pw' => substr(hash('sha256', (string) $advertiser->password), 0, 16),
        ]);
    }
}
