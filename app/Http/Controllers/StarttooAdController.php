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
        return $this->json($ads->pick((string) $r->query('slot'), $this->viewer($r, $s, $a), $r->header('X-App-Platform')));
    }

    public function click(Request $r, int $ad, Ads $ads, AuthSessions $s, Artworks $a)
    {
        $this->viewer($r, $s, $a);
        return $this->json($ads->click($ad));
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
