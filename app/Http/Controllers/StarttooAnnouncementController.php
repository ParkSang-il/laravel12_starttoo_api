<?php

namespace App\Http\Controllers;

use App\Services\Starttoo\Announcements;
use Illuminate\Http\Request;

/** MY 「공지사항」 목록 · 상세. 공개 정보라 로그인 없이도 읽을 수 있다. */
class StarttooAnnouncementController extends Controller
{
    public function index(Request $r, Announcements $a) { return $this->json($a->listing($r->query())); }
    public function show(int $announcement, Announcements $a) { return $this->json($a->detail($announcement)); }

    private function json(array $data)
    {
        return response()->json(['data' => $data])->header('Cache-Control', 'no-store');
    }
}
