<?php

namespace App\Http\Controllers;

use App\Services\Starttoo\AuthSessions;
use App\Services\Starttoo\Discover;
use Illuminate\Http\Request;

class StarttooDiscoverController extends Controller
{
    public function options(Request $request, Discover $discover, AuthSessions $sessions)
    {
        $identity = $this->identity($request, $sessions);
        return response()->json(['data' => [...$discover->options(), 'account' => $identity['account'] ?? null]])->header('Cache-Control', 'no-store');
    }

    public function search(Request $request, Discover $discover, AuthSessions $sessions)
    {
        $identity = $this->identity($request, $sessions);
        return response()->json(['data' => [...$discover->search($request->query(), isset($identity['account']['id']) ? (int) $identity['account']['id'] : null),
            'account' => $identity['account'] ?? null]])->header('Cache-Control', 'no-store');
    }

    private function identity(Request $request, AuthSessions $sessions): ?array
    {
        if ($request->bearerToken()) return $sessions->authenticate($request->bearerToken(), true);
        if (!config('starttoo.guest_browsing_enabled')) {
            throw new \Illuminate\Http\Exceptions\HttpResponseException(response()->json([
                'code' => 'GUEST_BROWSING_DISABLED', 'message' => '로그인 후 검색을 이용해주세요.',
            ], 503));
        }
        return null;
    }
}
