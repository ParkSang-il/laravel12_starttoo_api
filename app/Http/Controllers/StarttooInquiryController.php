<?php

namespace App\Http\Controllers;

use App\Services\Starttoo\AuthSessions;
use App\Services\Starttoo\Inquiries;
use Illuminate\Http\Request;

/** MY 「1:1 문의」: 내 문의 목록(대화 포함) · 작성 · 수정(답변 대기 중) · 추가 문의(답변 후) */
class StarttooInquiryController extends Controller
{
    public function index(Request $r, AuthSessions $s, Inquiries $q) { return $this->json($q->listing($this->user($r, $s), $r->query())); }
    public function options(Request $r, AuthSessions $s, Inquiries $q) { $this->user($r, $s); return $this->json($q->options()); }
    public function show(Request $r, int $inquiry, AuthSessions $s, Inquiries $q) { return $this->json($q->detail($this->user($r, $s), $inquiry)); }
    public function store(Request $r, AuthSessions $s, Inquiries $q) { return $this->json($q->create($this->user($r, $s), $r->all())); }
    public function update(Request $r, int $inquiry, AuthSessions $s, Inquiries $q) { return $this->json($q->edit($this->user($r, $s), $inquiry, $r->all())); }
    public function followUp(Request $r, int $inquiry, AuthSessions $s, Inquiries $q) { return $this->json($q->followUp($this->user($r, $s), $inquiry, $r->all())); }

    private function user(Request $r, AuthSessions $s): int
    {
        return (int) $s->authenticate((string) $r->bearerToken(), true)['account']['id'];
    }

    private function json(array $data)
    {
        return response()->json(['data' => $data])->header('Cache-Control', 'no-store');
    }
}
