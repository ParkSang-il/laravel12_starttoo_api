<?php

namespace App\Http\Controllers;

use App\Services\Starttoo\AuthSessions;
use App\Services\Starttoo\Posts;
use App\Services\Starttoo\PostLikes;
use Illuminate\Http\Request;

class StarttooPostController extends Controller
{
    public function destroy(Request $request, string $post, Posts $posts, AuthSessions $sessions)
    {
        return response()->json(['data' => $posts->delete($post, $request->bearerToken() ?? '', $request->all(), $sessions)])->header('Cache-Control', 'no-store');
    }

    public function hide(Request $request, string $post, Posts $posts, AuthSessions $sessions)
    {
        return response()->json(['data' => $posts->hide($post, $request->bearerToken() ?? '', $request->all(), $sessions)])->header('Cache-Control', 'no-store');
    }

    public function interaction(Request $request, string $post, PostLikes $likes, AuthSessions $sessions)
    {
        return response()->json(['data' => $likes->set($post, $request->bearerToken() ?? '', $request->all(), $sessions)])
            ->header('Cache-Control', 'no-store');
    }

    public function options(Request $request, Posts $posts, AuthSessions $sessions)
    {
        return response()->json(['data' => ['account' => $posts->ready($request->bearerToken() ?? '', $sessions)['account']]])->header('Cache-Control', 'no-store');
    }

    public function show(Request $request, string $post, Posts $posts, AuthSessions $sessions)
    {
        if (!$request->bearerToken() && !config('starttoo.guest_browsing_enabled')) {
            return response()->json(['code' => 'GUEST_BROWSING_DISABLED', 'message' => '로그인 후 게시물을 확인해주세요.'], 503);
        }
        $identity = $request->bearerToken() ? $sessions->authenticate($request->bearerToken(), true) : null;
        return response()->json(['data' => $posts->detail($post, isset($identity['account']['id']) ? (int) $identity['account']['id'] : null)])->header('Cache-Control', 'no-store');
    }

    public function store(Request $request, Posts $posts, AuthSessions $sessions)
    {
        return $this->save($request, null, $posts, $sessions);
    }

    public function update(Request $request, string $post, Posts $posts, AuthSessions $sessions)
    {
        return $this->save($request, $post, $posts, $sessions);
    }

    private function save(Request $request, ?string $post, Posts $posts, AuthSessions $sessions)
    {
        $token = $request->bearerToken() ?? '';
        $posts->ready($token, $sessions);
        $input = $request->json()->all();
        if ($input !== [] && array_is_list($input)) {
            return response()->json(['code' => 'INVALID_POST', 'message' => '게시물 입력 정보를 확인해주세요.'], 422);
        }
        // Laravel의 빈 문자열 -> null 변환 이후에도 사진만 있는 게시물을 허용한다.
        if (array_key_exists('content', $input) && $input['content'] === null) $input['content'] = '';
        return response()->json(['data' => $posts->save($post, $token, $input, $sessions)])->header('Cache-Control', 'no-store');
    }
}
