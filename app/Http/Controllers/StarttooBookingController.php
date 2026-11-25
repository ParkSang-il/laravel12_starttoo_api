<?php

namespace App\Http\Controllers;

use App\Services\Starttoo\Artworks;
use App\Services\Starttoo\AuthSessions;
use App\Services\Starttoo\Bookings;
use Illuminate\Http\Request;

/** Phase 4 예약 · 캘린더 · 변경 · 취소. 가입을 마친 회원만 이용한다. */
class StarttooBookingController extends Controller
{
    public function availability(Request $r, int $artist, Bookings $b, AuthSessions $s, Artworks $a)
    {
        $this->viewer($r, $s, $a);
        return $this->json($b->availability($artist, $r->query('month')));
    }

    public function request(Request $r, Bookings $b, AuthSessions $s, Artworks $a) { return $this->json($b->request($this->viewer($r, $s, $a), $r->all())); }
    public function list(Request $r, Bookings $b, AuthSessions $s, Artworks $a) { return $this->json($b->list($this->viewer($r, $s, $a), $r->query())); }
    public function next(Request $r, Bookings $b, AuthSessions $s, Artworks $a) { return $this->json(['booking' => $b->next($this->viewer($r, $s, $a))]); }
    public function room(Request $r, int $consultation, Bookings $b, AuthSessions $s, Artworks $a) { return $this->json(['booking' => $b->forRoom($this->viewer($r, $s, $a), $consultation)]); }
    public function show(Request $r, int $booking, Bookings $b, AuthSessions $s, Artworks $a) { return $this->json($b->detail($this->viewer($r, $s, $a), $booking)); }
    public function change(Request $r, int $booking, Bookings $b, AuthSessions $s, Artworks $a) { return $this->json($b->change($this->viewer($r, $s, $a), $booking, $r->all())); }
    public function cancel(Request $r, int $booking, Bookings $b, AuthSessions $s, Artworks $a) { return $this->json($b->cancel($this->viewer($r, $s, $a), $booking, $r->all())); }
    public function confirm(Request $r, int $booking, Bookings $b, AuthSessions $s, Artworks $a) { return $this->json($b->confirm($this->viewer($r, $s, $a), $booking)); }
    public function reject(Request $r, int $booking, Bookings $b, AuthSessions $s, Artworks $a) { return $this->json($b->reject($this->viewer($r, $s, $a), $booking)); }
    public function acceptChange(Request $r, int $booking, Bookings $b, AuthSessions $s, Artworks $a) { return $this->json($b->acceptChange($this->viewer($r, $s, $a), $booking)); }
    public function rejectChange(Request $r, int $booking, Bookings $b, AuthSessions $s, Artworks $a) { return $this->json($b->rejectChange($this->viewer($r, $s, $a), $booking)); }
    public function complete(Request $r, int $booking, Bookings $b, AuthSessions $s, Artworks $a) { return $this->json($b->complete($this->viewer($r, $s, $a), $booking)); }
    public function calendar(Request $r, Bookings $b, AuthSessions $s, Artworks $a) { return $this->json($b->calendar($this->viewer($r, $s, $a), $r->query('month'))); }
    public function day(Request $r, Bookings $b, AuthSessions $s, Artworks $a) { return $this->json($b->day($this->viewer($r, $s, $a), (string) $r->query('date'))); }
    public function settings(Request $r, Bookings $b, AuthSessions $s, Artworks $a) { return $this->json($b->mySettings($this->viewer($r, $s, $a))); }
    public function updateSettings(Request $r, Bookings $b, AuthSessions $s, Artworks $a) { return $this->json($b->updateSettings($this->viewer($r, $s, $a), $r->all())); }
    public function closure(Request $r, Bookings $b, AuthSessions $s, Artworks $a) { return $this->json($b->setClosure($this->viewer($r, $s, $a), $r->all())); }

    private function viewer(Request $request, AuthSessions $sessions, Artworks $artworks): int
    {
        return (int) $artworks->ready((string) $request->bearerToken(), $sessions)['account']['id'];
    }

    private function json(array $data)
    {
        return response()->json(['data' => $data])->header('Cache-Control', 'no-store');
    }
}
