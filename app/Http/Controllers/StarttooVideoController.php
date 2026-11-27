<?php

namespace App\Http\Controllers;

use App\Services\Starttoo\Artworks;
use App\Services\Starttoo\AuthSessions;
use App\Services\Starttoo\Videos;
use Illuminate\Http\Request;

/** 무빙(동영상) 업로드 · 게시 대기함 · 시청 기록(docs/video.md). 가입을 마친 회원만. */
class StarttooVideoController extends Controller
{
    public function create(Request $r, Videos $v, AuthSessions $s, Artworks $a) { return $this->json($v->create($this->viewer($r, $s, $a), $r->all())); }
    public function parts(Request $r, string $video, Videos $v, AuthSessions $s, Artworks $a) { return $this->json($v->parts($this->viewer($r, $s, $a), $video, $r->all())); }
    public function complete(Request $r, string $video, Videos $v, AuthSessions $s, Artworks $a) { return $this->json($v->complete($this->viewer($r, $s, $a), $video, $r->all())); }
    public function update(Request $r, string $video, Videos $v, AuthSessions $s, Artworks $a) { return $this->json($v->update($this->viewer($r, $s, $a), $video, $r->all())); }
    public function publish(Request $r, string $video, Videos $v, AuthSessions $s, Artworks $a) { return $this->json($v->publishByUser($this->viewer($r, $s, $a), $video)); }
    public function delete(Request $r, string $video, Videos $v, AuthSessions $s, Artworks $a) { return $this->json($v->delete($this->viewer($r, $s, $a), $video)); }
    public function show(Request $r, string $video, Videos $v, AuthSessions $s, Artworks $a) { return $this->json($v->show($this->viewer($r, $s, $a), $video)); }
    public function mine(Request $r, Videos $v, AuthSessions $s, Artworks $a) { return $this->json($v->listMine($this->viewer($r, $s, $a), $r->query('pending') === '1')); }
    /** 무빙 피드(홈 무빙 탭 · ?artist=&from= 작가 이어 보기) */
    public function feed(Request $r, Videos $v, AuthSessions $s, Artworks $a) { return $this->json($v->shorts($this->viewer($r, $s, $a), $r->query())); }

    public function views(Request $r, Videos $v, AuthSessions $s, Artworks $a) { return $this->json($v->views($this->viewer($r, $s, $a), $r->all())); }

    private function viewer(Request $request, AuthSessions $sessions, Artworks $artworks): int
    {
        return (int) $artworks->ready((string) $request->bearerToken(), $sessions)['account']['id'];
    }

    private function json(array $data)
    {
        return response()->json(['data' => $data])->header('Cache-Control', 'no-store');
    }
}
