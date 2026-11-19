<?php

namespace App\Http\Controllers;

use App\Services\Starttoo\AuthSessions;
use App\Services\Starttoo\ContentLikers;
use Illuminate\Http\Request;

/** 작품·일반 게시물의 좋아요한 회원 목록. */
class StarttooLikersController extends Controller
{
    private function viewer(Request $request, AuthSessions $sessions): ?int
    {
        if (!$request->bearerToken() && !config('starttoo.guest_browsing_enabled')) {
            throw new \Illuminate\Http\Exceptions\HttpResponseException(response()->json([
                'code' => 'GUEST_BROWSING_DISABLED', 'message' => '로그인 후 좋아요 목록을 확인해주세요.',
            ], 503));
        }
        $account = $request->bearerToken() ? $sessions->authenticate($request->bearerToken(), true)['account'] : null;
        return isset($account['id']) ? (int) $account['id'] : null;
    }

    public function artwork(Request $request, string $artwork, ContentLikers $likers, AuthSessions $sessions)
    {
        return response()->json(['data' => $likers->listing('artwork', $artwork, $request->query(), $this->viewer($request, $sessions))])
            ->header('Cache-Control', 'no-store');
    }

    public function post(Request $request, string $post, ContentLikers $likers, AuthSessions $sessions)
    {
        return response()->json(['data' => $likers->listing('post', $post, $request->query(), $this->viewer($request, $sessions))])
            ->header('Cache-Control', 'no-store');
    }
}
