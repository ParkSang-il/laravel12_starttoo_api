<?php

namespace App\Http\Controllers;

use App\Services\Starttoo\ArtistAds;
use App\Services\Starttoo\AuthSessions;
use App\Services\Starttoo\KakaoLocal;
use App\Services\Starttoo\Studios;
use Illuminate\Http\Request;

/** 2-14·2-15·2-16 아티스트 스튜디오(승인된 아티스트 전용). */
class StarttooStudioController extends Controller
{
    // ── 6-05 ~ 6-09 광고 ──

    public function ads(Request $request, Studios $studios, AuthSessions $sessions, ArtistAds $ads)
    {
        return $this->json($ads->list($this->artist($request, $sessions, $studios)));
    }

    public function adProducts(Request $request, Studios $studios, AuthSessions $sessions, ArtistAds $ads)
    {
        return $this->json($ads->products($this->artist($request, $sessions, $studios)));
    }

    /** 광고 안내 페이지(운영 콘솔에서 고친 섹션) */
    public function adGuide(Request $request, Studios $studios, AuthSessions $sessions, ArtistAds $ads)
    {
        $this->artist($request, $sessions, $studios);
        return $this->json($ads->guide());
    }

    public function createAd(Request $request, Studios $studios, AuthSessions $sessions, ArtistAds $ads)
    {
        return $this->json($ads->create($this->artist($request, $sessions, $studios), $request->all()));
    }

    public function ad(Request $request, int $ad, Studios $studios, AuthSessions $sessions, ArtistAds $ads)
    {
        return $this->json($ads->detail($this->artist($request, $sessions, $studios), $ad));
    }

    public function editAd(Request $request, int $ad, Studios $studios, AuthSessions $sessions, ArtistAds $ads)
    {
        return $this->json($ads->edit($this->artist($request, $sessions, $studios), $ad, $request->all()));
    }

    public function pauseAd(Request $request, int $ad, Studios $studios, AuthSessions $sessions, ArtistAds $ads)
    {
        return $this->json($ads->pause($this->artist($request, $sessions, $studios), $ad, $request->boolean('paused')));
    }

    public function extendAd(Request $request, int $ad, Studios $studios, AuthSessions $sessions, ArtistAds $ads)
    {
        return $this->json($ads->extend($this->artist($request, $sessions, $studios), $ad, $request->input('days')));
    }

    public function endAd(Request $request, int $ad, Studios $studios, AuthSessions $sessions, ArtistAds $ads)
    {
        return $this->json($ads->end($this->artist($request, $sessions, $studios), $ad));
    }

    public function dashboard(Request $request, Studios $studios, AuthSessions $sessions)
    {
        return $this->json($studios->dashboard($this->artist($request, $sessions, $studios)));
    }

    public function updateShop(Request $request, Studios $studios, AuthSessions $sessions)
    {
        return $this->json($studios->update($this->artist($request, $sessions, $studios), $request->all()));
    }

    /** 상점 위치 등록용 주소 검색(카카오 로컬 API 대리 호출). */
    public function addressSearch(Request $request, Studios $studios, AuthSessions $sessions, KakaoLocal $local)
    {
        $this->artist($request, $sessions, $studios);
        $q = $request->query('q');
        if (!is_string($q) || mb_strlen(trim($q)) > 80) {
            return response()->json(['code' => 'INVALID_ADDRESS_QUERY', 'message' => '검색어를 확인해주세요.'], 422);
        }
        return $this->json(['items' => $local->search($q)]);
    }

    public function artworks(Request $request, Studios $studios, AuthSessions $sessions)
    {
        return $this->json($studios->artworks($this->artist($request, $sessions, $studios), $request->query()));
    }

    public function updateArtwork(Request $request, string $artwork, Studios $studios, AuthSessions $sessions)
    {
        return $this->json($studios->updateArtwork($this->artist($request, $sessions, $studios), $artwork, $request->all()));
    }

    /** 스튜디오 「고객 관리」 */
    public function customers(Request $request, Studios $studios, AuthSessions $sessions, \App\Services\Starttoo\StudioInsights $insights)
    {
        return $this->json($insights->customers($this->artist($request, $sessions, $studios), $request->query()));
    }

    /** 스튜디오 「매출 · 통계」(견적 기준) */
    public function stats(Request $request, Studios $studios, AuthSessions $sessions, \App\Services\Starttoo\StudioInsights $insights)
    {
        return $this->json($insights->stats($this->artist($request, $sessions, $studios)));
    }

    private function artist(Request $request, AuthSessions $sessions, Studios $studios): int
    {
        $userId = (int) $sessions->authenticate((string) $request->bearerToken(), true)['account']['id'];
        $studios->shop($userId); // 아티스트 승인 확인
        return $userId;
    }

    private function json(array $data)
    {
        return response()->json(['data' => $data])->header('Cache-Control', 'no-store');
    }
}
