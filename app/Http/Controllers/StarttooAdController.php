<?php

namespace App\Http\Controllers;

use App\Services\Starttoo\Ads;
use App\Services\Starttoo\Artworks;
use App\Services\Starttoo\AuthSessions;
use Illuminate\Http\Request;

/** 자체 광고: 자리별 광고 1건 조회 · 누름 기록. 등록은 서버 명령(starttoo:ad). */
class StarttooAdController extends Controller
{
    public function show(Request $r, Ads $ads, AuthSessions $s, Artworks $a)
    {
        // 앱이 보내는 X-App-Platform(ios · android)으로 구글 광고 단위를 고른다
        // 검색 결과 상단은 q(검색어), 지역 탐색 상단은 region(지역 코드)에 맞는 작가 광고만 준다
        $context = ['q' => mb_substr((string) $r->query('q', ''), 0, 80), 'region' => mb_substr((string) $r->query('region', ''), 0, 40),
            // exclude=3,7: 지금 보고 있는 피드에 이미 나온 자체 광고(새로고침하면 앱이 비운다), 최대 50개
            'exclude' => array_slice(array_filter(explode(',', (string) $r->query('exclude', '')), 'ctype_digit'), 0, 50)];
        return $this->json($ads->pick((string) $r->query('slot'), $this->viewer($r, $s, $a), $r->header('X-App-Platform'), $context));
    }

    public function click(Request $r, int $ad, Ads $ads, AuthSessions $s, Artworks $a)
    {
        return $this->json($ads->click($ad, $this->viewer($r, $s, $a)));
    }

    private function viewer(Request $request, AuthSessions $sessions, Artworks $artworks): int
    {
        return (int) $artworks->ready((string) $request->bearerToken(), $sessions)['account']['id'];
    }

    private function json(array $data)
    {
        return response()->json(['data' => $data])->header('Cache-Control', 'no-store');
    }
}
