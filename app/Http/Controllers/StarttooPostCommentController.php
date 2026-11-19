<?php

namespace App\Http\Controllers;

use App\Services\Starttoo\PostComments;
use App\Services\Starttoo\AuthSessions;
use Illuminate\Http\Request;

class StarttooPostCommentController extends Controller
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

    public function index(Request $request, string $post, PostComments $comments, AuthSessions $sessions)
    {
        $viewer = $this->viewer($request, $sessions);
        return response()->json(['data' => $comments->listing($post, $request->query(), $viewer['id'] ?? null,
            ($viewer['next_action'] ?? null) === 'home')])->header('Cache-Control', 'no-store');
    }

    public function replies(Request $request, string $post, int $comment, PostComments $comments, AuthSessions $sessions)
    {
        $viewer = $this->viewer($request, $sessions);
        return response()->json(['data' => $comments->replies($post, $comment, $request->query(), $viewer['id'] ?? null,
            ($viewer['next_action'] ?? null) === 'home')])->header('Cache-Control', 'no-store');
    }

    public function store(Request $request, string $post, PostComments $comments, AuthSessions $sessions)
    {
        return response()->json(['data' => $comments->change('create', $post, null, $request->bearerToken() ?? '', $request->all(), $sessions)])
            ->header('Cache-Control', 'no-store');
    }

    public function delete(Request $request, string $post, int $comment, PostComments $comments, AuthSessions $sessions)
    {
        return response()->json(['data' => $comments->change('delete', $post, $comment, $request->bearerToken() ?? '', $request->all(), $sessions)])
            ->header('Cache-Control', 'no-store');
    }

    public function like(Request $request, string $post, int $comment, PostComments $comments, AuthSessions $sessions)
    {
        return response()->json(['data' => $comments->change('like', $post, $comment, $request->bearerToken() ?? '', $request->all(), $sessions)])
            ->header('Cache-Control', 'no-store');
    }
}
