<?php

namespace App\Http\Controllers;

use App\Services\Starttoo\Artworks;
use App\Services\Starttoo\AuthSessions;
use App\Services\Starttoo\PublicProfiles;
use App\Services\Starttoo\Follows;
use Illuminate\Http\Request;
use Illuminate\Http\Exceptions\HttpResponseException;

class StarttooPublicProfileController extends Controller
{
    public function saved(Request $request, string $user, \App\Services\Starttoo\UnifiedFeed $feed, AuthSessions $sessions)
    {
        $identity = $this->identity($request, $sessions);
        $viewer = isset($identity['account']['id']) ? (int) $identity['account']['id'] : null;
        return response()->json(['data' => [...$feed->list([...$request->only(['cursor', 'style', 'collection']), 'scope' => 'saved'], $viewer, null, (int) $user),
            'account' => $identity['account'] ?? null]])->header('Cache-Control', 'no-store');
    }

    public function feed(Request $request, string $user, PublicProfiles $profiles, \App\Services\Starttoo\UnifiedFeed $feed, AuthSessions $sessions)
    {
        $identity = $this->identity($request, $sessions);
        $viewer = isset($identity['account']['id']) ? (int) $identity['account']['id'] : null;
        $profiles->user((int) $user, $viewer);
        return response()->json(['data' => [...$feed->list($request->only(['cursor', 'style']), $viewer, (int) $user),
            'account' => $identity['account'] ?? null]])->header('Cache-Control', 'no-store');
    }

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
        $profiles->user((int) $user, isset($identity['account']['id']) ? (int) $identity['account']['id'] : null);
        // scope=mine 등을 받아도 타인의 숨김 작품으로 범위를 넓힐 수 없다.
        return response()->json(['data' => [...$artworks->feed($request->only(['cursor', 'style']),
            isset($identity['account']['id']) ? (int) $identity['account']['id'] : null, (int) $user),
            'account' => $identity['account'] ?? null]])->header('Cache-Control', 'no-store');
    }

    public function follow(Request $request, string $user, Follows $follows, AuthSessions $sessions, Artworks $artworks)
    {
        return response()->json(['data' => $follows->set((int) $user, $request->bearerToken() ?? '', $request->all(), $sessions, $artworks)])
            ->header('Cache-Control', 'no-store');
    }

    public function follows(Request $request, string $user, Follows $follows, AuthSessions $sessions)
    {
        $identity = $this->identity($request, $sessions);
        $viewer = isset($identity['account']['id']) ? (int) $identity['account']['id'] : null;
        if ($user === 'me' && $viewer === null) {
            return response()->json(['code' => 'SESSION_EXPIRED', 'message' => '로그인이 필요해요.'], 401);
        }
        return response()->json(['data' => [...$follows->listing($user === 'me' ? $viewer : (int) $user, $viewer, $request->query()),
            'account' => $identity['account'] ?? null]])->header('Cache-Control', 'no-store');
    }
}
