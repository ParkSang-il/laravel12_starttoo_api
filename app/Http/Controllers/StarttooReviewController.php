<?php

namespace App\Http\Controllers;

use App\Services\Starttoo\Artworks;
use App\Services\Starttoo\AuthSessions;
use App\Services\Starttoo\Reviews;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Http\Request;

/** Phase 5 인증 리뷰(5-01~5-03). 목록 · 상세는 상점과 같은 공개 범위, 변경은 가입을 마친 회원만. */
class StarttooReviewController extends Controller
{
    public function shop(Request $r, int $user, Reviews $v, AuthSessions $s) { return $this->json($v->shop($user, $this->guest($r, $s), $r->query())); }
    public function show(Request $r, int $review, Reviews $v, AuthSessions $s) { return $this->json($v->detail($review, $this->guest($r, $s))); }

    public function mine(Request $r, Reviews $v, AuthSessions $s, Artworks $a) { return $this->json($v->mine($this->viewer($r, $s, $a), $r->query())); }
    public function form(Request $r, Reviews $v, AuthSessions $s, Artworks $a) { return $this->json($v->form($this->viewer($r, $s, $a), (int) $r->query('booking'))); }
    public function create(Request $r, Reviews $v, AuthSessions $s, Artworks $a) { return $this->json($v->create($this->viewer($r, $s, $a), $r->all())); }
    public function update(Request $r, int $review, Reviews $v, AuthSessions $s, Artworks $a) { return $this->json($v->update($this->viewer($r, $s, $a), $review, $r->all())); }
    public function delete(Request $r, int $review, Reviews $v, AuthSessions $s, Artworks $a) { return $this->json($v->delete($this->viewer($r, $s, $a), $review)); }
    public function reply(Request $r, int $review, Reviews $v, AuthSessions $s, Artworks $a) { return $this->json($v->reply($this->viewer($r, $s, $a), $review, $r->all())); }
    public function helpful(Request $r, int $review, Reviews $v, AuthSessions $s, Artworks $a) { return $this->json($v->helpful($this->viewer($r, $s, $a), $review, $r->all())); }

    private function viewer(Request $request, AuthSessions $sessions, Artworks $artworks): int
    {
        return (int) $artworks->ready((string) $request->bearerToken(), $sessions)['account']['id'];
    }

    /** 상점과 같은 공개 범위(비회원 둘러보기 설정을 따른다) */
    private function guest(Request $request, AuthSessions $sessions): ?int
    {
        if (!$request->bearerToken()) {
            if (!config('starttoo.guest_browsing_enabled')) {
                throw new HttpResponseException(response()->json(['code' => 'GUEST_BROWSING_DISABLED', 'message' => '로그인 후 리뷰를 확인해주세요.'], 503));
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
