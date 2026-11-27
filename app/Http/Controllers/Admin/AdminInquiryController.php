<?php

namespace App\Http\Controllers\Admin;

use App\Services\Starttoo\Inquiries;
use App\Support\AdminUi as Ui;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\View\View;

/**
 * M-15 1:1 문의. 앱 MY 「1:1 문의」에서 회원이 남긴 문의에 답변한다(답변하면 앱 알림 inquiry_answer).
 * 답변 대기 24시간이 지나면 빨갛게 표시한다. 답변은 CS 담당 이상(inquiry.answer).
 */
class AdminInquiryController extends AdminBaseController
{
    private const TABS = ['답변 대기' => 'waiting', '답변 완료' => 'answered', '전체' => null];
    private const LATE_HOURS = 24;

    public function index(Request $request): View
    {
        $tab = array_key_exists((string) $request->query('tab'), self::TABS) ? (string) $request->query('tab') : '답변 대기';
        $status = self::TABS[$tab];
        $svc = app(Inquiries::class);
        $page = $this->page($request);

        $q = $this->db()->table('starttoo_inquiries as i')->leftJoin('starttoo_users as u', 'u.id', '=', 'i.user_id');
        if ($status) $q->where('i.status', $status);
        $total = (clone $q)->count();
        // 답변 대기는 오래 기다린 문의부터, 나머지는 최근 순
        $status === 'waiting' ? $q->orderBy('i.asked_at') : $q->orderByDesc('i.updated_at');
        $rows = $q->offset(($page - 1) * self::PER_PAGE)->limit(self::PER_PAGE)
            ->get(['i.*', 'u.nickname', 'u.display_name']);

        $selId = (int) $request->query('sel', 0);
        $late = fn ($i) => $i->status === 'waiting' && $i->asked_at && Carbon::parse($i->asked_at)->lt(now()->subHours(self::LATE_HOURS));
        $list = $rows->map(fn ($i) => [
            'id' => 'Q-' . $i->id,
            'category' => $svc->categoryLabel($i->category),
            'title' => $i->title,
            'who' => ($i->nickname ?: $i->display_name ?: '회원') . ' · U-' . $i->user_id,
            'whoHref' => Ui::memberUrl($i->user_id),
            'asked' => $i->asked_at ? Ui::ago($i->asked_at) : '-',
            'askedStyle' => $late($i) ? 'color: #B3261E; font-weight: 700' : 'color: #66625B',
            'state' => $i->status === 'waiting' ? ($late($i) ? '24시간 지남' : '답변 대기') : '답변 완료',
            'pill' => Ui::pill($i->status === 'waiting' ? ($late($i) ? 'red' : 'amber') : 'green'),
            'href' => Ui::url(['sel' => $i->id]),
            'rowStyle' => Ui::rowBg((int) $i->id === $selId),
        ])->all();

        $sel = null;
        if ($selId && ($i = $this->db()->table('starttoo_inquiries as i')->leftJoin('starttoo_users as u', 'u.id', '=', 'i.user_id')
                ->where('i.id', $selId)->first(['i.*', 'u.nickname', 'u.display_name']))) {
            $sel = [
                'id' => 'Q-' . $i->id, 'title' => $i->title, 'category' => $svc->categoryLabel($i->category),
                'who' => ($i->nickname ?: $i->display_name ?: '회원') . ' · U-' . $i->user_id, 'whoHref' => Ui::memberUrl($i->user_id),
                'created' => Ui::date($i->created_at, 'Y.m.d H:i'),
                'state' => $i->status === 'waiting' ? '답변 대기' : '답변 완료',
                'pill' => Ui::pill($i->status === 'waiting' ? ($late($i) ? 'red' : 'amber') : 'green'),
                'waiting' => $i->status === 'waiting',
                'answerAction' => route('admin.inquiries.answer', $i->id),
                'messages' => array_map(fn ($m) => $m + [
                    'who' => $m['author'] === 'admin' ? '운영자 · ' . ($m['admin_name'] ?? '관리자') : '회원',
                    't' => Ui::date($m['created_at'], 'm.d H:i') . ($m['edited'] ? ' · 수정됨' : ''),
                ], $svc->thread((int) $i->id)),
            ];
        }

        return view('admin.inquiries', [
            'tabs' => Ui::tabs(array_keys(self::TABS), $tab),
            'list' => $list, 'sel' => $sel,
            'pages' => Ui::pages($page, false, (int) ceil($total / self::PER_PAGE)),
            'canAnswer' => $this->can($request, 'inquiry.answer'),
            'answerMax' => Inquiries::ANSWER_MAX,
            'lateHours' => self::LATE_HOURS,
        ]);
    }

    public function answer(Request $request, int $id): RedirectResponse
    {
        $this->need($request, 'inquiry.answer');
        $data = $request->validate(['body' => ['required', 'string', 'max:' . Inquiries::ANSWER_MAX]],
            ['body.required' => '답변을 적어주세요.', 'body.max' => '답변은 ' . number_format(Inquiries::ANSWER_MAX) . '자까지 적을 수 있어요.']);
        try {
            $q = app(Inquiries::class)->answer($id, $data['body'], (int) $this->admin($request)->id);
        } catch (\InvalidArgumentException $e) {
            return back()->withErrors(['body' => $e->getMessage()])->withInput();
        }
        $this->audit($request, '설정', '1:1 문의 답변', "Q-{$id} · U-{$q->user_id} · {$q->title}");

        return back()->with('status', "Q-{$id}에 답변했어요 · 회원에게 앱 알림을 보냈어요");
    }
}
