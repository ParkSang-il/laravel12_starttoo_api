<?php

namespace App\Http\Controllers;

use App\Services\Starttoo\Artworks;
use App\Services\Starttoo\AuthSessions;
use App\Services\Starttoo\Consultations;
use Illuminate\Http\Request;

/** 3-01~3-03 · 3-06 · 3-07 상담·채팅. 가입을 마친 회원만 이용한다. */
class StarttooConsultationController extends Controller
{
    public function availability(Request $request, int $artist, Consultations $c, AuthSessions $sessions, Artworks $artworks)
    {
        return $this->json($c->availability($this->viewer($request, $sessions, $artworks), $artist));
    }

    public function start(Request $request, Consultations $c, AuthSessions $sessions, Artworks $artworks)
    {
        return $this->json($c->start($this->viewer($request, $sessions, $artworks), $request->all()));
    }

    public function list(Request $request, Consultations $c, AuthSessions $sessions, Artworks $artworks)
    {
        return $this->json($c->list($this->viewer($request, $sessions, $artworks), $request->query()));
    }

    public function unread(Request $request, Consultations $c, AuthSessions $sessions, Artworks $artworks)
    {
        return $this->json(['unread_total' => $c->unreadTotal($this->viewer($request, $sessions, $artworks))]);
    }

    public function show(Request $request, int $consultation, Consultations $c, AuthSessions $sessions, Artworks $artworks)
    {
        return $this->json($c->detail($this->viewer($request, $sessions, $artworks), $consultation));
    }

    public function messages(Request $request, int $consultation, Consultations $c, AuthSessions $sessions, Artworks $artworks)
    {
        return $this->json($c->messages($this->viewer($request, $sessions, $artworks), $consultation, $request->query()));
    }

    public function send(Request $request, int $consultation, Consultations $c, AuthSessions $sessions, Artworks $artworks)
    {
        return $this->json($c->send($this->viewer($request, $sessions, $artworks), $consultation, $request->all()));
    }

    public function read(Request $request, int $consultation, Consultations $c, AuthSessions $sessions, Artworks $artworks)
    {
        return $this->json($c->read($this->viewer($request, $sessions, $artworks), $consultation, $request->all()));
    }

    private function viewer(Request $request, AuthSessions $sessions, Artworks $artworks): int
    {
        return (int) $artworks->ready((string) $request->bearerToken(), $sessions)['account']['id'];
    }

    private function json(array $data)
    {
        return response()->json(['data' => $data])->header('Cache-Control', 'no-store');
    }
}
