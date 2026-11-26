<?php

namespace App\Http\Controllers\Admin;

use App\Services\Starttoo\Announcements;
use App\Services\Starttoo\PushCampaigns;
use App\Services\Starttoo\UserPreferences;
use App\Support\AdminUi as Ui;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\View\View;

/**
 * 운영 콘솔 소통: M-17 공지(MY 「공지사항」) · M-16 광고 푸시 발송.
 * 처리는 서버 명령과 같은 서비스(Announcements · PushCampaigns)를 부른다.
 * 홈 배너 · FAQ · 약관 탭, 정보성 푸시 · 예약 발송 · 자동 알림 종류별 끄기는 앱 · 서버 기능이 없어 아직 두지 않는다.
 */
class AdminCommsController extends AdminBaseController
{
    // ================================================================ M-14 상담 · 채팅 모니터링

    /**
     * 신고된 상담방만 보여 준다(상담 신고 · 상담 메시지 신고). 대화는 잠겨 있고,
     * 열람 사유를 고르면 그 방의 대화 전체(최근 500개)를 30분 동안 볼 수 있다. 열람할 때마다 감사 로그.
     * 2026-11-26 결정: 신고된 방은 사유 기록 후 전체 열람(개인정보 처리방침에 「신고 · 분쟁 처리 시 운영자 열람」 명시 필요)
     */
    private const CHAT_TABS = ['미처리 신고' => 'pending', '처리됨' => 'done', '전체' => null];
    private const CHAT_REASONS = ['신고 처리', '분쟁 조정'];
    private const CHAT_STAGE = ['requested' => ['상담 요청', 'gray'], 'consulting' => ['상담', 'gray'], 'quoted' => ['견적', 'blue'],
        'booked' => ['예약 확정', 'ink'], 'completed' => ['완료', 'green'], 'closed' => ['종료', 'gray']];
    private const CHAT_OPEN_MINUTES = 30;

    /** 상담방별 신고(상담 자체 · 그 방의 메시지) 묶음 */
    private function chatReports()
    {
        return $this->db()->table('starttoo_reports as r')
            ->leftJoin('starttoo_messages as m', fn ($j) => $j->on('m.id', '=', 'r.target_id')->where('r.target_type', '=', 'message'))
            ->whereIn('r.target_type', ['consultation', 'message'])
            ->selectRaw("CASE WHEN r.target_type = 'consultation' THEN CAST(r.target_id AS UNSIGNED) ELSE m.consultation_id END as room_id")
            ->selectRaw('r.id, r.status, r.reason, r.subject_user_id, r.created_at');
    }

    public function chats(Request $request): View
    {
        $db = $this->db();
        $tab = array_key_exists((string) $request->query('tab'), self::CHAT_TABS) ? (string) $request->query('tab') : '미처리 신고';
        $reason = in_array($request->query('reason'), self::CHAT_REASONS, true) ? (string) $request->query('reason') : self::CHAT_REASONS[0];
        $all = $db->query()->fromSub($this->chatReports(), 'x')->whereNotNull('x.room_id')->orderByDesc('x.id')->get();
        $byRoom = $all->groupBy('room_id');
        $pendingRooms = $byRoom->filter(fn ($g) => $g->contains('status', 'pending'))->keys();
        $roomIds = match (self::CHAT_TABS[$tab]) {
            'pending' => $pendingRooms,
            'done' => $byRoom->keys()->diff($pendingRooms),
            default => $byRoom->keys(),
        };
        $roomIds = $roomIds->map(fn ($v) => (int) $v)->values()->take(100)->all();
        $rooms = $roomIds ? $db->table('starttoo_consultations as c')
            ->leftJoin('starttoo_users as cu', 'cu.id', '=', 'c.customer_id')->leftJoin('starttoo_users as au', 'au.id', '=', 'c.artist_id')
            ->leftJoin('starttoo_consultation_cases as k', 'k.id', '=', 'c.current_case_id')
            ->whereIn('c.id', $roomIds)->orderByDesc('c.last_message_at')
            ->get(['c.id', 'c.customer_id', 'c.artist_id', 'c.last_message_at', 'k.status as stage', 'cu.nickname as customer', 'au.nickname as artist'])->keyBy('id') : collect();

        $selId = (int) $request->query('sel', 0);
        if (!$selId || !$byRoom->has($selId)) $selId = (int) ($rooms->keys()->first() ?? 0);
        $reasonLabel = fn ($code) => self::REPORT_REASON[$code] ?? $code;
        $flag = fn ($g) => $reasonLabel($g->first()->reason) . ($g->count() > 1 ? ' 외 ' . ($g->count() - 1) : '');

        $list = $rooms->map(fn ($r) => [
            'id' => 'room_' . $r->id, 'customer' => $r->customer ?? '-', 'artist' => $r->artist ?? '-',
            'customerHref' => Ui::memberUrl($r->customer_id), 'artistHref' => Ui::memberUrl($r->artist_id),
            'stage' => self::CHAT_STAGE[$r->stage][0] ?? '상담', 'stagePill' => Ui::pill(self::CHAT_STAGE[$r->stage][1] ?? 'gray'),
            'last' => Ui::date($r->last_message_at, 'm.d H:i'),
            'flag' => $flag($byRoom[$r->id]), 'pill' => Ui::pill($pendingRooms->contains($r->id) ? 'red' : 'gray'),
            'rowStyle' => Ui::rowBg((int) $r->id === $selId), 'href' => Ui::url(['sel' => $r->id]),
        ])->values()->all();

        $sel = null;
        $msgs = [];
        $grant = $request->session()->get('admin_chat_open.' . $selId);
        $opened = $grant && time() - (int) $grant['at'] <= self::CHAT_OPEN_MINUTES * 60;
        if ($selId && ($room = $db->table('starttoo_consultations as c')
                ->leftJoin('starttoo_users as cu', 'cu.id', '=', 'c.customer_id')->leftJoin('starttoo_users as au', 'au.id', '=', 'c.artist_id')
                ->where('c.id', $selId)->first(['c.*', 'cu.nickname as customer', 'au.nickname as artist']))) {
            $counts = $db->table('starttoo_messages')->where('consultation_id', $selId)->groupBy('kind')->selectRaw('kind, COUNT(*) as n')->pluck('n', 'kind');
            $reports = $byRoom[$selId];
            $subject = (int) $reports->first()->subject_user_id;
            $sel = [
                'id' => 'room_' . $selId, 'customer' => $room->customer ?? '-', 'artist' => $room->artist ?? '-',
                'flag' => $flag($reports), 'pill' => Ui::pill($reports->contains('status', 'pending') ? 'red' : 'gray'),
                'msgs' => number_format((int) $counts->sum()), 'files' => number_format((int) ($counts['image'] ?? 0)),
                'events' => number_format((int) (($counts['system'] ?? 0) + ($counts['quote'] ?? 0))),
                'reportsCount' => $reports->count(),
                'subjectName' => $subject === (int) $room->customer_id ? ($room->customer ?? '-') : ($room->artist ?? '-'),
                'openAction' => route('admin.chats.open', $selId), 'warnAction' => route('admin.chats.warn', $selId),
                'reportsHref' => Ui::memberReportsUrl($subject),
                'grantText' => $opened ? '열람 사유: ' . $grant['reason'] . ' · ' . date('m.d H:i', (int) $grant['at']) . ' · ' . $this->admin($request)->name
                    . ' · ' . self::CHAT_OPEN_MINUTES . '분 동안' : null,
            ];
            if ($opened) {
                $names = [(int) $room->customer_id => $room->customer ?? '고객', (int) $room->artist_id => $room->artist ?? '작가'];
                $bubble = fn ($bg, $c) => "max-width: 82%; padding: 10px 12px; border-radius: 14px; font-size: 13px; line-height: 1.5; white-space: pre-line; overflow-wrap: anywhere; background: $bg; color: $c";
                $rows = $db->table('starttoo_messages')->where('consultation_id', $selId)->orderByDesc('id')->limit(500)->get()->reverse();
                $reported = $all->where('room_id', $selId)->pluck('id')->all();
                $reportedMessageIds = $db->table('starttoo_reports')->whereIn('id', $reported)->where('target_type', 'message')->pluck('target_id')->map(fn ($v) => (int) $v)->all();
                foreach ($rows as $m) {
                    $isArtist = (int) $m->sender_id === (int) $room->artist_id;
                    $text = match ($m->kind) {
                        'text' => (string) $m->body,
                        'image' => '(사진)',
                        'artwork' => '(작품 공유)',
                        'request' => '(상담 요청서)' . ($m->body ? "\n" . $m->body : ''),
                        'quote' => '(견적)' . ($m->body ? "\n" . $m->body : ''),
                        default => (string) ($m->body ?? ''),
                    };
                    if ($m->moderation_hidden ?? false) $text = '[운영자가 가림] ' . $text;
                    $flagged = in_array((int) $m->id, $reportedMessageIds, true);
                    $msgs[] = [
                        'who' => $m->sender_id === null ? '시스템' : ($names[(int) $m->sender_id] ?? 'U-' . $m->sender_id) . ($flagged ? ' · 신고된 메시지' : ''),
                        't' => Ui::date($m->created_at, 'm.d H:i'), 'text' => $text,
                        'wrap' => 'display: flex; flex-direction: column; gap: 4px; align-items: ' . ($m->sender_id === null ? 'center' : ($isArtist ? 'flex-end' : 'flex-start')),
                        'bubble' => $flagged ? $bubble('#FDE4DE', '#6E2111') : ($m->sender_id === null ? $bubble('#E3EEFF', '#1F4F99')
                            : ($isArtist ? $bubble('#141413', '#F2EFEA') : $bubble('#FFFFFF; border: 1px solid #E4E0D8', '#141413'))),
                    ];
                }
            }
        }

        $tabs = [];
        foreach (self::CHAT_TABS as $label => $code) {
            $n = match ($code) { 'pending' => $pendingRooms->count(), 'done' => $byRoom->count() - $pendingRooms->count(), default => $byRoom->count() };
            $tabs[] = ['label' => "{$label} {$n}", 'style' => Ui::tab($label === $tab), 'href' => Ui::url(['tab' => $label, 'sel' => null])];
        }

        return view('admin.chats', [
            'tabs' => $tabs, 'rooms' => $list, 'sel' => $sel, 'msgs' => $msgs, 'opened' => $opened, 'reason' => $reason,
            'reasons' => Ui::chips(self::CHAT_REASONS, $reason, 'reason'),
            'canOpen' => $this->can($request, 'chat.view'), 'canWarn' => $this->can($request, 'report.warn'),
        ]);
    }

    public function openChat(Request $request, int $id): RedirectResponse
    {
        $this->need($request, 'chat.view');
        $data = $request->validate(['reason' => ['required', 'in:' . implode(',', self::CHAT_REASONS)]]);
        // 신고된 방만 열 수 있다
        abort_unless($this->db()->query()->fromSub($this->chatReports(), 'x')->where('x.room_id', $id)->exists(), 404);
        $request->session()->put('admin_chat_open.' . $id, ['reason' => $data['reason'], 'at' => time()]);
        $this->audit($request, '접속', '채팅 열람 · ' . $data['reason'], "room_{$id}");

        return back();
    }

    /** 신고된 회원(가장 최근 신고 기준)에게 경고 — 신고 처리의 「경고」와 같은 제재 이력 · 안내 알림 */
    public function warnChat(Request $request, int $id): RedirectResponse
    {
        $this->need($request, 'report.warn');
        $grant = $request->session()->get('admin_chat_open.' . $id);
        abort_unless($grant && time() - (int) $grant['at'] <= self::CHAT_OPEN_MINUTES * 60, 403, '열람 사유를 먼저 기록해주세요.');
        $report = $this->db()->query()->fromSub($this->chatReports(), 'x')->where('x.room_id', $id)->orderByDesc('x.id')->first();
        abort_unless($report, 404);
        try {
            app(\App\Services\Starttoo\Sanctions::class)->apply((int) $report->subject_user_id, '경고', '운영 콘솔 · 상담 모니터링 경고', (int) $report->id, (int) $this->admin($request)->id);
        } catch (\InvalidArgumentException $e) {
            return back()->withErrors(['warn' => $e->getMessage()]);
        }
        $name = $this->db()->table('starttoo_users')->where('id', $report->subject_user_id)->value('nickname') ?? ('U-' . $report->subject_user_id);
        $this->audit($request, '제재', '상담 경고', "room_{$id} · {$name} (U-{$report->subject_user_id})");

        return back()->with('status', "{$name}님에게 경고를 보냈어요");
    }

    private const REPORT_REASON = ['spam' => '스팸 · 광고', 'harassment' => '욕설 · 혐오 · 괴롭힘', 'sexual' => '음란 · 불쾌한 콘텐츠', 'violence' => '폭력적인 내용',
        'illegal' => '불법 정보', 'rights' => '저작권 침해', 'fraud' => '사기 · 외부 거래 유도', 'privacy' => '개인정보 노출', 'other' => '기타'];

    // ================================================================ M-17 공지사항

    public function notices(Request $request): View
    {
        $rows = $this->db()->table('starttoo_announcements')->orderByDesc('pinned')->orderByDesc('published_at')->orderByDesc('id')->limit(100)->get();
        $isNew = $request->query('sel') === 'new';
        $sel = $isNew ? null : ($rows->firstWhere('id', (int) $request->query('sel')) ?? $rows->first());
        $canManage = $this->can($request, 'notice.manage');
        $kind = ['게시 중' => 'green', '상단 고정' => 'ink', '예약' => 'blue', '중지' => 'gray'];
        $state = fn ($a) => !$a->active ? '중지' : (Carbon::parse($a->published_at)->isFuture() ? '예약' : ($a->pinned ? '상단 고정' : '게시 중'));

        $list = $rows->map(fn ($a) => [
            'title' => $a->title, 'b' => Announcements::CATEGORIES[$a->category] ?? $a->category,
            'c' => Ui::date($a->published_at, 'm.d') . (Carbon::parse($a->published_at)->isFuture() ? ' 예약' : ''),
            'state' => $state($a), 'pill' => Ui::pill($kind[$state($a)]),
            'on' => (bool) $a->active, 'aria' => $a->title . ' 노출', 'canToggle' => $canManage,
            'track' => Ui::switchTrack((bool) $a->active, !$canManage), 'action' => route('admin.notices.toggle', $a->id),
            'rowStyle' => Ui::rowBg($sel && !$isNew && $a->id === $sel->id), 'href' => Ui::url(['sel' => $a->id]),
        ])->all();

        // 새 공지 · 아직 게시 전(예약) 공지만 게시 시각 · 「게시할 때 푸시 보내기」를 바꿀 수 있다
        $editable = $isNew || !$sel || Carbon::parse($sel->published_at)->isFuture();
        $pushAllowed = $editable && $this->can($request, 'push.send') && config('starttoo.push_enabled');
        $sentCampaign = $sel && !$isNew && $sel->push_campaign_id ? $this->db()->table('starttoo_push_campaigns')->where('id', $sel->push_campaign_id)->first(['state']) : null;
        return view('admin.notices', [
            'rows' => $list, 'knob' => Ui::KNOB, 'canManage' => $canManage, 'isNew' => $isNew || !$sel,
            'categories' => Announcements::CATEGORIES,
            'sel' => $sel ? ['title' => $sel->title, 'category' => $sel->category, 'body' => $sel->body, 'pinned' => (bool) $sel->pinned,
                'state' => $state($sel), 'pill' => Ui::pill($kind[$state($sel)]),
                'at' => Carbon::parse($sel->published_at)->timezone('Asia/Seoul')->format('Y-m-d\TH:i'), 'push' => (bool) $sel->push_pending]
                : ['title' => '', 'category' => 'notice', 'body' => '', 'pinned' => false, 'state' => '새 공지', 'pill' => Ui::pill('blue'), 'at' => '', 'push' => false],
            'editableTime' => $editable,
            'pushSent' => $sentCampaign ? '이 공지로 보낸 푸시가 있어요 · ' . (['running' => '발송 중', 'completed' => '발송 완료', 'cancelled' => '취소', 'paused' => '일시 중지'][$sentCampaign->state] ?? $sentCampaign->state) : null,
            'pushHref' => route('admin.push'),
            'newHref' => Ui::url(['sel' => 'new']),
            // 받을 회원 수는 5분 캐시
            'canPush' => $pushAllowed,
            'pushNote' => $pushAllowed
                ? '공지 · 업데이트 → 정보성 · 알림 켠 회원 ' . number_format($this->reach(null, true)['eligible']) . '명 / 이벤트 → 광고성 · 수신 동의자 '
                    . number_format($this->reach(null, false)['eligible']) . '명 (09~20시). 예약 공지는 게시 시각에 보내요. 누르면 이 공지가 열려요.'
                : null,
            'saveAction' => $isNew || !$sel ? route('admin.notices.store') : route('admin.notices.save', $sel->id),
            'saveLabel' => $isNew || !$sel ? '게시' : '저장',
        ]);
    }

    private function noticeInput(Request $request): array
    {
        return $request->validate([
            'title' => ['required', 'string', 'max:120'],
            'category' => ['required', 'in:' . implode(',', array_keys(Announcements::CATEGORIES))],
            'body' => ['required', 'string', 'max:10000'],
            'pinned' => ['nullable', 'boolean'],
            'push' => ['nullable', 'boolean'],
            // 게시 시각(비우면 바로). 앞으로의 시각이면 예약 게시
            'published_at' => ['nullable', 'date'],
        ], ['title.required' => '제목을 입력해주세요.', 'body.required' => '본문을 입력해주세요.', 'published_at.date' => '게시 시각을 확인해주세요.']);
    }

    /** 게시 시각: 비었거나 지난 시각이면 지금 */
    private static function publishAt(?string $value): Carbon
    {
        $at = $value ? Carbon::parse($value, 'Asia/Seoul') : now();
        return $at->isPast() ? now() : $at;
    }

    public function storeNotice(Request $request): RedirectResponse
    {
        $this->need($request, 'notice.manage');
        $in = $this->noticeInput($request);
        $push = (bool) ($in['push'] ?? false);
        if ($push) $this->need($request, 'push.send');
        $at = self::publishAt($in['published_at'] ?? null);
        $scheduled = $at->isFuture();
        $notices = app(Announcements::class);
        try {
            $id = $notices->add(['title' => $in['title'], 'body' => $in['body'], 'category' => $in['category'],
                'pin' => (bool) ($in['pinned'] ?? false), 'at' => $at, 'push' => $push]);
        } catch (\InvalidArgumentException $e) {
            return back()->withErrors(['title' => $e->getMessage()])->withInput();
        }
        $when = $scheduled ? Ui::date($at, 'm.d H:i') . ' 예약' : '바로';
        $this->audit($request, '콘텐츠', $scheduled ? '공지 예약 게시' : '공지 게시', "공지 #{$id} · {$in['title']} · {$when}" . ($push ? ' · 푸시' : ''));
        $message = $scheduled ? Ui::date($at, 'n월 j일 H:i') . '에 게시되도록 예약했어요' : '공지를 게시했어요';
        if (!$push) return redirect(route('admin.notices', ['sel' => $id]))->with('status', $message);

        $info = Announcements::pushIsInfo($in['category']);
        if ($scheduled) {
            return redirect(route('admin.notices', ['sel' => $id]))->with('status', $message . ' · 게시되면 ' . ($info ? '정보성' : '광고성') . ' 푸시를 보내요');
        }
        // 바로 게시: 지금 보낸다(매분 명령을 기다리지 않게)
        [$sent, $errors] = $notices->sendDuePushes($id);
        if (!$sent) {
            return redirect(route('admin.notices', ['sel' => $id]))->withErrors(['push' => '공지는 게시했지만 푸시는 보내지 못했어요: ' . ($errors[0] ?? '다시 확인해주세요')]);
        }
        $reach = $this->reach(null, $info)['eligible'];
        $this->audit($request, '설정', $info ? '정보성 푸시 발송' : '광고 푸시 발송', "공지 #{$id} · {$in['title']} · 전체 회원 " . number_format($reach) . '명');
        $hour = (int) now('Asia/Seoul')->format('G');
        $message .= !$info && ($hour < 9 || $hour >= 20) ? ' · 광고 푸시는 오전 9시부터 보내요' : ' · 푸시를 보내기 시작했어요';

        return redirect(route('admin.notices', ['sel' => $id]))->with('status', $message);
    }

    /** 캠페인 만들고 바로 시작(정보성 · 광고성). $ids = null 이면 전체 */
    private function startCampaign(bool $info, string $title, string $body, string $landing, ?string $targetId, ?array $ids): string
    {
        $campaigns = app(PushCampaigns::class);
        $id = $campaigns->create(['kind' => $info ? 'info' : 'marketing', 'title' => $title, 'body' => $body, 'sender' => 'STARTTOO',
            'target_type' => $landing, 'target_id' => $targetId, 'user_ids' => $ids]);
        $campaigns->control($id, 'start');
        return $id;
    }

    public function saveNotice(Request $request, int $id): RedirectResponse
    {
        $this->need($request, 'notice.manage');
        $in = $this->noticeInput($request);
        $before = $this->db()->table('starttoo_announcements')->where('id', $id)->first();
        abort_unless($before, 404);
        $values = ['title' => trim($in['title']), 'body' => trim($in['body']), 'category' => $in['category'], 'pinned' => (bool) ($in['pinned'] ?? false)];
        // 아직 게시 전(예약)이면 게시 시각 · 푸시 여부도 바꿀 수 있다
        $wasScheduled = Carbon::parse($before->published_at)->isFuture();
        if ($wasScheduled) {
            $values['published_at'] = self::publishAt($in['published_at'] ?? null);
            $push = (bool) ($in['push'] ?? false);
            if ($push && !$before->push_pending) $this->need($request, 'push.send');
            $values['push_pending'] = $push;
        }
        app(Announcements::class)->update($id, $values);
        $this->audit($request, '콘텐츠', '공지 수정', "공지 #{$id} · {$in['title']}", ['pinned' => [(bool) $before->pinned, $values['pinned']]]
            + ($wasScheduled ? ['published_at' => [(string) $before->published_at, $values['published_at']->toDateTimeString()], 'push' => [(bool) $before->push_pending, $values['push_pending']]] : []));
        // 예약을 지금으로 당겼으면 푸시도 바로
        if ($wasScheduled && $values['push_pending'] && !$values['published_at']->isFuture()) app(Announcements::class)->sendDuePushes($id);

        return back()->with('status', '저장했어요');
    }

    public function toggleNotice(Request $request, int $id): RedirectResponse
    {
        $this->need($request, 'notice.manage');
        $a = $this->db()->table('starttoo_announcements')->where('id', $id)->first();
        abort_unless($a, 404);
        app(Announcements::class)->update($id, ['active' => !$a->active]);
        $this->audit($request, '콘텐츠', ($a->active ? '공지 내리기 · ' : '공지 다시 올리기 · ') . $a->title, "공지 #{$id}");

        return back()->with('status', $a->active ? '공지를 내렸어요' : '공지를 다시 올렸어요');
    }

    // ================================================================ M-16 광고 푸시

    private const TARGETS = ['전체 회원' => null, '아티스트' => 'artist'];
    private const LANDINGS = ['home' => '홈 피드', 'settings' => '알림 · 개인정보 설정'];
    private const CAMPAIGN_STATE = ['draft' => ['준비', 'gray'], 'running' => ['발송 중', 'blue'], 'paused' => ['일시 중지', 'amber'], 'completed' => ['발송 완료', 'green'], 'cancelled' => ['취소', 'gray']];
    /** PushCampaigns 의 user_ids 상한 */
    private const MAX_USERS = 1000;

    private const TYPES = ['광고성' => 'marketing', '정보성' => 'info'];

    /**
     * 대상별 받을 회원(5분 캐시). 광고성: 앱 알림을 켠 회원 중 광고 수신 동의자 · 정보성: 앱 알림을 켠 모든 정상 회원.
     * ids: 지정 대상(아티스트)일 때 보낼 회원 번호, 전체면 null
     */
    private function reach(?string $target, bool $info): array
    {
        return Cache::remember('starttoo:admin:push-reach:' . ($target ?? 'all') . ($info ? ':info' : ':ad'), 300, function () use ($target, $info) {
            $q = $this->db()->table('starttoo_push_devices as d')->join('starttoo_users as u', 'u.id', '=', 'd.user_id')
                ->where('u.status', 'active')->where('u.moderation_hidden', false);
            if ($target === 'artist') $q->where('u.artist_status', 'approved');
            $ids = $q->distinct()->limit(20000)->pluck('d.user_id')->map(fn ($v) => (int) $v)->all();
            if ($info) {
                $eligible = $ids;
            } else {
                $prefs = app(UserPreferences::class);
                $eligible = array_values(array_filter($ids, fn ($id) => $prefs->marketingEligible($id)));
            }
            return ['devices' => count($ids), 'eligible' => count($eligible), 'ids' => $target === null ? null : $eligible,
                // 화면에 보여 줄 받을 회원(앞 50명)
                'preview' => array_slice($eligible, 0, 50)];
        });
    }

    public function push(Request $request): View
    {
        $db = $this->db();
        $type = array_key_exists((string) $request->query('type'), self::TYPES) ? (string) $request->query('type') : '광고성';
        $info = self::TYPES[$type] === 'info';
        $target = array_key_exists((string) $request->query('target'), self::TARGETS) ? (string) $request->query('target') : '전체 회원';
        $landing = array_key_exists((string) $request->query('landing'), self::LANDINGS) ? (string) $request->query('landing') : 'home';
        $reach = $this->reach(self::TARGETS[$target], $info);
        $hour = (int) now('Asia/Seoul')->format('G');
        $enabled = config('starttoo.push_enabled') && ($info || config('starttoo.marketing_push_enabled'));

        $today = today();
        $logs = $db->table('starttoo_push_logs')->where('created_at', '>=', $today)->where('result', '<>', 'opened');
        $sentToday = (clone $logs)->where('result', 'sent')->count();
        $campaignToday = (clone $logs)->where('result', 'sent')->where('source_type', 'campaign')->count();
        $tried = (clone $logs)->whereIn('result', ['sent', 'invalid', 'rejected', 'exception'])->count();
        $invalid = (clone $logs)->where('result', 'invalid')->count();
        $month = now()->subDays(30);
        $sent30 = $db->table('starttoo_campaign_deliveries as d')->join('starttoo_push_campaigns as c', 'c.id', '=', 'd.campaign_id')->where('c.created_at', '>=', $month)->where('d.state', 'sent')->count();
        $open30 = $db->table('starttoo_campaign_deliveries as d')->join('starttoo_push_campaigns as c', 'c.id', '=', 'd.campaign_id')->where('c.created_at', '>=', $month)->whereNotNull('d.opened_at')->count();
        $kpis = [
            ['label' => '오늘 푸시 발송', 'value' => number_format($sentToday), 'sub' => '자동 알림 ' . number_format($sentToday - $campaignToday) . ' · 캠페인 ' . number_format($campaignToday), 'subStyle' => Ui::sub('muted')],
            ['label' => '도달률', 'value' => $tried ? number_format($sentToday / $tried * 100, 1) . '%' : '-', 'sub' => '만료된 기기 ' . number_format($invalid) . '건 정리됨', 'subStyle' => Ui::sub('muted')],
            ['label' => '캠페인 오픈율', 'value' => $sent30 ? number_format($open30 / $sent30 * 100, 1) . '%' : '-', 'sub' => '최근 30일 · 발송 ' . number_format($sent30) . '건', 'subStyle' => Ui::sub('muted')],
            ['label' => '진행 중 캠페인', 'value' => number_format($db->table('starttoo_push_campaigns')->whereIn('state', ['running', 'paused'])->count()), 'sub' => '광고 푸시는 09:00~20:00에만 나가요', 'subStyle' => Ui::sub('muted')],
        ];

        $campaigns = $db->table('starttoo_push_campaigns')->orderByDesc('created_at')->limit(30)->get();
        $counts = $campaigns->isEmpty() ? collect() : $db->table('starttoo_campaign_deliveries')->whereIn('campaign_id', $campaigns->pluck('id')->all())
            ->groupBy('campaign_id')->selectRaw("campaign_id, SUM(state = 'sent') as sent, SUM(opened_at IS NOT NULL) as opened")->get()->keyBy('campaign_id');
        // 캠페인별 실제 대상(회원 ID · 닉네임 · 결과), 캠페인마다 앞 50건
        $stateLabel = ['pending' => '대기', 'sending' => '보내는 중', 'sent' => '보냄', 'skipped' => '건너뜀', 'failed' => '실패'];
        $deliveries = $campaigns->isEmpty() ? collect() : $db->table('starttoo_campaign_deliveries as d')->leftJoin('starttoo_users as u', 'u.id', '=', 'd.user_id')
            ->whereIn('d.campaign_id', $campaigns->pluck('id')->all())->orderBy('d.id')
            ->get(['d.campaign_id', 'd.user_id', 'd.state', 'd.opened_at', 'u.nickname'])->groupBy('campaign_id');
        $history = $campaigns->map(function ($c) use ($counts, $deliveries, $stateLabel) {
            $n = $counts[$c->id] ?? null;
            $rows = $deliveries[$c->id] ?? collect();
            $recipients = $rows->take(50)->map(fn ($d) => ['id' => 'U-' . $d->user_id, 'nick' => $d->nickname ?? '-',
                'state' => ($stateLabel[$d->state] ?? $d->state) . ($d->opened_at ? ' · 열어 봄' : '')])->values()->all();
            $sent = (int) ($n->sent ?? 0);
            $users = $c->user_ids ? count(json_decode($c->user_ids, true) ?: []) : null;
            return [
                'title' => $c->title, 'type' => ($c->kind ?? 'marketing') === 'info' ? '정보성' : '광고성',
                'target' => $users === null ? '전체 회원' : "지정 회원 {$users}명",
                'sent' => $sent ? number_format($sent) : '-', 'open' => $sent ? number_format((int) ($n->opened ?? 0) / $sent * 100, 1) . '%' : '-',
                'state' => self::CAMPAIGN_STATE[$c->state][0] ?? $c->state, 'pill' => Ui::pill(self::CAMPAIGN_STATE[$c->state][1] ?? 'gray'),
                'recipients' => $recipients, 'more' => max(0, $rows->count() - 50),
                'canCancel' => in_array($c->state, ['running', 'paused', 'draft'], true) && $this->can(request(), 'push.send'),
                'cancelAction' => route('admin.push.cancel', $c->id),
            ];
        })->all();

        $pushOn = (bool) config('starttoo.push_enabled');
        $autos = array_map(fn ($a) => ['label' => $a[0], 'ch' => $a[1], 'on' => $pushOn, 'aria' => $a[0] . ' 자동 알림', 'track' => Ui::switchTrack($pushOn, true)], [
            ['상담 · 채팅 메시지', '앱 사용 중 소켓 · 나가 있으면 푸시'],
            ['예약 요청 · 확정 · 24시간 전 안내', '푸시 · 알림함'],
            ['AI 작업 완료', '앱 사용 중 소켓 · 나가 있으면 푸시'],
            ['좋아요 · 댓글 · 팔로우', '회원별 알림 설정을 따라요'],
            ['심사 · 신고 처리 결과', '푸시 · 알림함'],
        ]);

        $tooMany = $reach['ids'] !== null && count($reach['ids']) > self::MAX_USERS;
        return view('admin.push', [
            'kpis' => $kpis, 'history' => $history, 'autos' => $autos, 'knob' => Ui::KNOB,
            'type' => $type, 'info' => $info, 'target' => $target, 'landing' => $landing,
            'types' => Ui::chips(array_keys(self::TYPES), $type, 'type'),
            'typeNote' => $info
                ? '서비스 점검 · 정책 변경처럼 모든 회원이 알아야 하는 내용만 보내요. 이벤트 · 혜택 같은 광고 내용은 넣으면 안 돼요(정보통신망법).'
                : '제목 앞에 (광고)가 자동으로 붙고, 수신 동의자에게만 보내요. 끝에 수신 거부 방법이 들어가요.',
            'titleMax' => $info ? 60 : 45,
            'targets' => Ui::chips(array_keys(self::TARGETS), $target, 'target'),
            'landings' => self::LANDINGS,
            'reach' => number_format($reach['eligible']) . '명',
            'reachList' => $this->db()->table('starttoo_users')->whereIn('id', $reach['preview'] ?? [])->orderBy('id')->get(['id', 'nickname'])
                ->map(fn ($u) => ['id' => 'U-' . $u->id, 'nick' => $u->nickname ?? '-'])->all(),
            'reachMore' => max(0, $reach['eligible'] - 50),
            'reachNote' => $info ? '앱 알림을 켠 모든 회원(광고 수신 동의와 무관)' : '앱 알림을 켠 회원 ' . number_format($reach['devices']) . '명 중 광고성 알림 수신 동의자',
            'night' => !$info && ($hour < 9 || $hour >= 20),
            'canSend' => $this->can($request, 'push.send') && $enabled && $reach['eligible'] > 0 && !$tooMany,
            'blocked' => !$enabled ? ($info ? '서버 설정에서 푸시가 꺼져 있어요(.env STARTTOO_PUSH_ENABLED).' : '서버 설정에서 푸시 · 광고 푸시가 꺼져 있어요(.env STARTTOO_PUSH_ENABLED · STARTTOO_MARKETING_PUSH_ENABLED).')
                : ($tooMany ? '지정 대상은 ' . number_format(self::MAX_USERS) . '명까지 보낼 수 있어요.' : ($reach['eligible'] === 0 ? '받을 수 있는 회원이 없어요.' : null)),
            'sendAction' => route('admin.push.send'),
            'sendLabel' => number_format($reach['eligible']) . '명에게 보내기',
            'title' => (string) old('title', ''), 'body' => (string) old('body', ''),
        ]);
    }

    public function sendPush(Request $request): RedirectResponse
    {
        $this->need($request, 'push.send');
        $info = $request->input('type') === '정보성';
        $data = $request->validate([
            'type' => ['required', 'in:' . implode(',', array_keys(self::TYPES))],
            'target' => ['required', 'in:' . implode(',', array_keys(self::TARGETS))],
            'landing' => ['required', 'in:' . implode(',', array_keys(self::LANDINGS))],
            'title' => ['required', 'string', 'max:' . ($info ? 60 : 45)],
            'body' => ['required', 'string', 'max:500'],
        ], ['title.max' => $info ? '제목은 60자까지 쓸 수 있어요.' : '제목은 45자까지 쓸 수 있어요((광고) 표시는 자동으로 붙어요).']);
        // 광고성은 (광고)를 서비스가 붙이므로 입력에서 지운다. 정보성은 (광고)로 시작할 수 없다.
        $title = trim(preg_replace('/^\(광고\)\s*/u', '', trim($data['title'])));
        $reach = $this->reach(self::TARGETS[$data['target']], $info);
        if ($reach['ids'] !== null && ($reach['ids'] === [] || count($reach['ids']) > self::MAX_USERS)) {
            return back()->withErrors(['target' => '보낼 수 있는 대상이 아니에요.'])->withInput();
        }
        try {
            $id = $this->startCampaign($info, $title, trim($data['body']), $data['landing'], null, $reach['ids']);
        } catch (\InvalidArgumentException $e) {
            return back()->withErrors(['title' => $e->getMessage()])->withInput();
        }
        $label = $info ? $title : "(광고) {$title}";
        $this->audit($request, '설정', $info ? '정보성 푸시 발송' : '광고 푸시 발송', "{$label} · {$data['target']} " . number_format($reach['eligible']) . '명', ['campaign' => $id]);

        $hour = (int) now('Asia/Seoul')->format('G');
        return back()->with('status', !$info && ($hour < 9 || $hour >= 20) ? '발송을 등록했어요 · 오전 9시부터 순서대로 보내요' : '발송을 시작했어요 · 큐에서 순서대로 보내요');
    }

    public function cancelPush(Request $request, string $id): RedirectResponse
    {
        $this->need($request, 'push.send');
        try {
            app(PushCampaigns::class)->control($id, 'cancel');
        } catch (\InvalidArgumentException $e) {
            return back()->withErrors(['push' => $e->getMessage()]);
        }
        $this->audit($request, '설정', '광고 푸시 취소', "캠페인 {$id}");

        return back()->with('status', '남은 발송을 취소했어요');
    }
}
