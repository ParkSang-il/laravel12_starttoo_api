<?php

namespace App\Http\Controllers;

use App\Services\Starttoo\ArtworkComments;
use App\Services\Starttoo\AuthSessions;
use Illuminate\Http\Request;

class StarttooCommentController extends Controller
{
    private function viewer(Request $request, AuthSessions $sessions): ?array
    {
        if (!$request->bearerToken() && !config('starttoo.guest_browsing_enabled')) {
            throw new \Illuminate\Http\Exceptions\HttpResponseException(response()->json([
                'code' => 'GUEST_BROWSING_DISABLED', 'message' => '로그인 후 댓글을 확인해주세요.',
            ], 503));
        }
        return $request->bearerToken() ? $sessions->authenticate($request->bearerToken(), true)['account'] : null;
    }

    public function index(Request $request, string $artwork, ArtworkComments $comments, AuthSessions $sessions)
    {
        $viewer = $this->viewer($request, $sessions);
        return response()->json(['data' => $comments->listing($artwork, $request->query(), $viewer['id'] ?? null,
            ($viewer['next_action'] ?? null) === 'home')])->header('Cache-Control', 'no-store');
    }

    public function replies(Request $request, string $artwork, int $comment, ArtworkComments $comments, AuthSessions $sessions)
    {
        $viewer = $this->viewer($request, $sessions);
        return response()->json(['data' => $comments->replies($artwork, $comment, $request->query(), $viewer['id'] ?? null,
            ($viewer['next_action'] ?? null) === 'home')])->header('Cache-Control', 'no-store');
    }

    public function store(Request $request, string $artwork, ArtworkComments $comments, AuthSessions $sessions)
    {
        return response()->json(['data' => $comments->change('create', $artwork, null, $request->bearerToken() ?? '', $request->all(), $sessions)])
            ->header('Cache-Control', 'no-store');
    }

    public function delete(Request $request, string $artwork, int $comment, ArtworkComments $comments, AuthSessions $sessions)
    {
        return response()->json(['data' => $comments->change('delete', $artwork, $comment, $request->bearerToken() ?? '', $request->all(), $sessions)])
            ->header('Cache-Control', 'no-store');
    }

    public function like(Request $request, string $artwork, int $comment, ArtworkComments $comments, AuthSessions $sessions)
    {
        return response()->json(['data' => $comments->change('like', $artwork, $comment, $request->bearerToken() ?? '', $request->all(), $sessions)])
            ->header('Cache-Control', 'no-store');
    }
}
