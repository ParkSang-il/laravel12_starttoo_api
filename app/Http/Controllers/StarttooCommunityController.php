<?php

namespace App\Http\Controllers;

use App\Services\Starttoo\Artworks;
use App\Services\Starttoo\AuthSessions;
use App\Services\Starttoo\Community;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Http\Request;

/** Phase 5 커뮤니티(5-04~5-07). 목록 · 상세는 피드와 같은 공개 범위, 변경은 가입을 마친 회원만. */
class StarttooCommunityController extends Controller
{
    public function feed(Request $r, Community $c, AuthSessions $s) { return $this->json($c->feed($this->guest($r, $s), $r->query())); }
    public function show(Request $r, int $post, Community $c, AuthSessions $s) { return $this->json($c->detail($post, $this->guest($r, $s), $r->query('count') !== '0')); }

    public function artists(Request $r, Community $c, AuthSessions $s, Artworks $a) { return $this->json($c->artists($this->viewer($r, $s, $a), $r->query())); }
    public function create(Request $r, Community $c, AuthSessions $s, Artworks $a) { return $this->json($c->create($this->viewer($r, $s, $a), $r->all())); }
    public function update(Request $r, int $post, Community $c, AuthSessions $s, Artworks $a) { return $this->json($c->update($this->viewer($r, $s, $a), $post, $r->all())); }
    public function delete(Request $r, int $post, Community $c, AuthSessions $s, Artworks $a) { return $this->json($c->delete($this->viewer($r, $s, $a), $post)); }
    public function like(Request $r, int $post, Community $c, AuthSessions $s, Artworks $a) { return $this->json($c->like($this->viewer($r, $s, $a), $post, $r->all())); }
    public function comment(Request $r, int $post, Community $c, AuthSessions $s, Artworks $a) { return $this->json($c->comment($this->viewer($r, $s, $a), $post, $r->all())); }
    public function deleteComment(Request $r, int $comment, Community $c, AuthSessions $s, Artworks $a) { return $this->json($c->deleteComment($this->viewer($r, $s, $a), $comment)); }

    private function viewer(Request $request, AuthSessions $sessions, Artworks $artworks): int
    {
        return (int) $artworks->ready((string) $request->bearerToken(), $sessions)['account']['id'];
    }

    /** 비회원 둘러보기 설정을 따른다 */
    private function guest(Request $request, AuthSessions $sessions): ?int
    {
        if (!$request->bearerToken()) {
            if (!config('starttoo.guest_browsing_enabled')) {
                throw new HttpResponseException(response()->json(['code' => 'GUEST_BROWSING_DISABLED', 'message' => '로그인 후 커뮤니티를 확인해주세요.'], 503));
            }
            return null;
        }
        return (int) $sessions->authenticate($request->bearerToken(), true)['account']['id'];
    }

    private function json(array $data)
    {
        return response()->json(['data' => $data])->header('Cache-Control', 'no-store');
    }
}
