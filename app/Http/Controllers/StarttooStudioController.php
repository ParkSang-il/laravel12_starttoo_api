<?php

namespace App\Http\Controllers;

use App\Services\Starttoo\AuthSessions;
use App\Services\Starttoo\Studios;
use Illuminate\Http\Request;

/** 2-14·2-15·2-16 아티스트 스튜디오(승인된 아티스트 전용). */
class StarttooStudioController extends Controller
{
    public function dashboard(Request $request, Studios $studios, AuthSessions $sessions)
    {
        return $this->json($studios->dashboard($this->artist($request, $sessions, $studios)));
    }

    public function updateShop(Request $request, Studios $studios, AuthSessions $sessions)
    {
        return $this->json($studios->update($this->artist($request, $sessions, $studios), $request->all()));
    }

    public function artworks(Request $request, Studios $studios, AuthSessions $sessions)
    {
        return $this->json($studios->artworks($this->artist($request, $sessions, $studios), $request->query()));
    }

    public function updateArtwork(Request $request, string $artwork, Studios $studios, AuthSessions $sessions)
    {
        return $this->json($studios->updateArtwork($this->artist($request, $sessions, $studios), $artwork, $request->all()));
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
