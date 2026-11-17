<?php

namespace App\Http\Controllers;

use App\Services\Starttoo\Artworks;
use App\Services\Starttoo\AuthSessions;
use App\Services\Starttoo\PublicProfiles;
use Illuminate\Http\Request;
use Illuminate\Http\Exceptions\HttpResponseException;

class StarttooPublicProfileController extends Controller
{
    private function identity(Request $request, AuthSessions $sessions): ?array
    {
        if (!$request->bearerToken() && !config('starttoo.guest_browsing_enabled')) {
            throw new HttpResponseException(response()->json([
                'code' => 'GUEST_BROWSING_DISABLED', 'message' => '로그인 후 프로필을 확인해주세요.',
            ], 503));
        }
        return $request->bearerToken() ? $sessions->authenticate($request->bearerToken(), true) : null;
    }

    public function show(Request $request, string $user, PublicProfiles $profiles, AuthSessions $sessions)
    {
        $identity = $this->identity($request, $sessions);
        $viewer = isset($identity['account']['id']) ? (int) $identity['account']['id'] : null;
        return response()->json(['data' => ['profile' => $profiles->show((int) $user, $viewer),
            'account' => $identity['account'] ?? null]])->header('Cache-Control', 'no-store');
    }

    public function artworks(Request $request, string $user, PublicProfiles $profiles, Artworks $artworks, AuthSessions $sessions)
    {
        $identity = $this->identity($request, $sessions);
        $profiles->user((int) $user);
        // scope=mine 등을 받아도 타인의 숨김 작품으로 범위를 넓힐 수 없다.
        return response()->json(['data' => [...$artworks->feed($request->only(['cursor', 'style']),
            isset($identity['account']['id']) ? (int) $identity['account']['id'] : null, (int) $user),
            'account' => $identity['account'] ?? null]])->header('Cache-Control', 'no-store');
    }
}
