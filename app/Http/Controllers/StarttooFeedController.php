<?php

namespace App\Http\Controllers;

use App\Services\Starttoo\AuthSessions;
use App\Services\Starttoo\FeedEvents;
use App\Services\Starttoo\UnifiedFeed;
use Illuminate\Http\Request;

class StarttooFeedController extends Controller
{
    public function index(Request $request, UnifiedFeed $feed, AuthSessions $sessions)
    {
        if (!$request->bearerToken() && $request->query('scope', 'public') === 'public' && !config('starttoo.guest_browsing_enabled')) {
            return response()->json(['code' => 'GUEST_BROWSING_DISABLED', 'message' => '로그인 후 피드를 확인해주세요.'], 503);
        }
        $identity = $request->bearerToken() ? $sessions->authenticate($request->bearerToken(), true) : null;
        return response()->json(['data' => [...$feed->list($request->query(), isset($identity['account']['id']) ? (int) $identity['account']['id'] : null),
            'account' => $identity['account'] ?? null]])->header('Cache-Control', 'no-store');
    }

    /** 로그인 회원만 기록한다(비회원 추천은 개인화하지 않는다). */
    public function events(Request $request, FeedEvents $events, AuthSessions $sessions)
    {
        $identity = $sessions->authenticate((string) $request->bearerToken(), true);
        return response()->json(['data' => $events->record((int) $identity['account']['id'], $request->all())])
            ->header('Cache-Control', 'no-store');
    }
}
