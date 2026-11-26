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

        return view('admin.notices', [
            'rows' => $list, 'knob' => Ui::KNOB, 'canManage' => $canManage, 'isNew' => $isNew || !$sel,
            'categories' => Announcements::CATEGORIES,
            'sel' => $sel ? ['title' => $sel->title, 'category' => $sel->category, 'body' => $sel->body, 'pinned' => (bool) $sel->pinned,
                'state' => $state($sel), 'pill' => Ui::pill($kind[$state($sel)])] : ['title' => '', 'category' => 'notice', 'body' => '', 'pinned' => false, 'state' => '새 공지', 'pill' => Ui::pill('blue')],
            'newHref' => Ui::url(['sel' => 'new']),
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
        ], ['title.required' => '제목을 입력해주세요.', 'body.required' => '본문을 입력해주세요.']);
    }

    public function storeNotice(Request $request): RedirectResponse
    {
        $this->need($request, 'notice.manage');
        $in = $this->noticeInput($request);
        try {
            $id = app(Announcements::class)->add(['title' => $in['title'], 'body' => $in['body'], 'category' => $in['category'], 'pin' => (bool) ($in['pinned'] ?? false)]);
        } catch (\InvalidArgumentException $e) {
            return back()->withErrors(['title' => $e->getMessage()])->withInput();
        }
        $this->audit($request, '콘텐츠', '공지 게시', "공지 #{$id} · {$in['title']}");

        return redirect(route('admin.notices', ['sel' => $id]))->with('status', '공지를 게시했어요');
    }

    public function saveNotice(Request $request, int $id): RedirectResponse
    {
        $this->need($request, 'notice.manage');
        $in = $this->noticeInput($request);
        $before = $this->db()->table('starttoo_announcements')->where('id', $id)->first();
        abort_unless($before, 404);
        app(Announcements::class)->update($id, ['title' => trim($in['title']), 'body' => trim($in['body']), 'category' => $in['category'], 'pinned' => (bool) ($in['pinned'] ?? false)]);
        $this->audit($request, '콘텐츠', '공지 수정', "공지 #{$id} · {$in['title']}", ['pinned' => [(bool) $before->pinned, (bool) ($in['pinned'] ?? false)]]);

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
            return ['devices' => count($ids), 'eligible' => count($eligible), 'ids' => $target === null ? null : $eligible];
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
        $history = $campaigns->map(function ($c) use ($counts) {
            $n = $counts[$c->id] ?? null;
            $sent = (int) ($n->sent ?? 0);
            $users = $c->user_ids ? count(json_decode($c->user_ids, true) ?: []) : null;
            return [
                'title' => $c->title, 'type' => ($c->kind ?? 'marketing') === 'info' ? '정보성' : '광고성',
                'target' => $users === null ? '전체 회원' : "지정 회원 {$users}명",
                'sent' => $sent ? number_format($sent) : '-', 'open' => $sent ? number_format((int) ($n->opened ?? 0) / $sent * 100, 1) . '%' : '-',
                'state' => self::CAMPAIGN_STATE[$c->state][0] ?? $c->state, 'pill' => Ui::pill(self::CAMPAIGN_STATE[$c->state][1] ?? 'gray'),
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
        $campaigns = app(PushCampaigns::class);
        try {
            $id = $campaigns->create(['kind' => self::TYPES[$data['type']], 'title' => $title, 'body' => trim($data['body']), 'sender' => 'STARTTOO',
                'target_type' => $data['landing'], 'user_ids' => $reach['ids']]);
            $campaigns->control($id, 'start');
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
