<?php

namespace App\Http\Controllers\Admin;

use App\Services\Starttoo\AdminAccounts;
use App\Services\Starttoo\AdminAudit;
use App\Services\Starttoo\Ads;
use App\Services\Starttoo\Settings;
use App\Support\AdminUi as Ui;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\View\View;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * 운영 콘솔 조회 화면: M-02 대시보드 · M-03 회원 · M-07 예약 · M-08 광고 · M-09 설정 · 감사 로그.
 * 화면 상태(탭 · 검색 · 페이지)는 쿼리 파라미터, 처리는 CSRF POST → 감사 로그.
 * 숫자는 모두 실제 데이터에서 센다(샘플 · 가짜 숫자 없음).
 */
class AdminController extends AdminBaseController
{
    private const UP = 'font-size: 12px; font-weight: 700; color: #1D6B3A';
    private const DOWN = 'font-size: 12px; font-weight: 700; color: #A3301A';
    private const FLAT = 'font-size: 12px; font-weight: 700; color: #66625B';

    // ================================================================ M-02 대시보드

    public function dashboard(): View
    {
        // 무거운 집계는 5분 캐시
        $data = Cache::remember('starttoo:admin:dashboard', 300, fn () => $this->dashboardData());

        $badges = Ui::navBadges();
        $queue = [
            ['label' => '아티스트 신청 심사', 'count' => $badges['artistReview'] . '건', 'pill' => Ui::pill('amber'), 'href' => route('admin.artist-review')],
            ['label' => '미처리 신고', 'count' => $badges['reports'] . '건', 'pill' => Ui::pill('red'), 'href' => route('admin.reports')],
            ['label' => '예약 변경 요청', 'count' => $data['changeRequests'] . '건', 'pill' => Ui::pill('amber'), 'href' => route('admin.payments', ['tab' => '변경 요청'])],
            ['label' => '숨김 처리 콘텐츠 재검토', 'count' => $data['hidden'] . '건', 'pill' => Ui::pill('gray'), 'href' => route('admin.contents', ['state' => '숨김'])],
            ['label' => '오늘 AI 생성 실패', 'count' => ($badges['aiJobs'] ?? 0) . '건', 'pill' => Ui::pill('red'), 'href' => route('admin.ai-jobs', ['tab' => '실패'])],
            // 무빙: 오늘 변환 실패 · 신고로 검토 중(숨김)인 영상
            ['label' => '오늘 무빙 변환 실패', 'count' => ($badges['videoFailed'] ?? 0) . '건', 'pill' => Ui::pill('red'), 'href' => route('admin.ai-jobs.videos', ['tab' => '실패'])],
            ['label' => '무빙 영상 검수', 'count' => ($badges['videoHeld'] ?? 0) . '건', 'pill' => Ui::pill('amber'), 'href' => route('admin.contents', ['tab' => '무빙', 'state' => '숨김'])],
        ];

        $logs = app(AdminAudit::class)->query(null)->limit(4)->get()->map(fn ($l) => app(AdminAudit::class)->row($l))->all();

        return view('admin.dashboard', [
            'kpis' => $data['kpis'], 'bars' => $data['bars'], 'queue' => $queue, 'logs' => $logs,
            'today' => now()->format('Y.m.d') . ' (' . ['일', '월', '화', '수', '목', '금', '토'][now()->dayOfWeek] . ') 기준',
        ]);
    }

    private function dashboardData(): array
    {
        $db = $this->db();
        $week = now()->subDays(7);
        $twoWeeks = now()->subDays(14);

        $approved = $db->table('starttoo_users')->where('artist_status', 'approved')->where('status', 'active')->count();
        $newApproved = $db->table('starttoo_users')->where('artist_status', 'approved')->where('artist_approved_at', '>=', $week)->count();

        $uploads = fn ($from, $to) => $db->table('starttoo_artworks')->whereBetween('created_at', [$from, $to])->count();
        $saves = fn ($from, $to) => $db->table('starttoo_artwork_interactions')->where('kind', 'save')->whereBetween('created_at', [$from, $to])->count();
        // 상담 → 예약 전환율: 기간 안에 시작된 상담 건 가운데 예약이 확정(완료 포함)된 비율
        $conversion = function ($from, $to) use ($db) {
            $cases = $db->table('starttoo_consultation_cases')->whereBetween('created_at', [$from, $to]);
            $total = (clone $cases)->count();
            if ($total === 0) return null;
            $booked = (clone $cases)->whereExists(fn ($q) => $q->selectRaw('1')->from('starttoo_bookings as b')
                ->whereColumn('b.case_id', 'starttoo_consultation_cases.id')->whereNotNull('b.confirmed_at'))->count();
            return $booked / $total * 100;
        };

        $upNow = $uploads($week, now());
        $upPrev = $uploads($twoWeeks, $week);
        $saveNow = $saves($week, now());
        $savePrev = $saves($twoWeeks, $week);
        $convNow = $conversion($week, now());
        $convPrev = $conversion($twoWeeks, $week);

        $kpis = [
            $this->kpi('승인 아티스트', number_format($approved), $newApproved > 0 ? "지난주 대비 +{$newApproved}" : '지난주와 같음', $newApproved > 0 ? 1 : 0),
            $this->kpi('주간 작품 업로드', number_format($upNow), ...$this->percentDelta($upNow, $upPrev)),
            // 작품 조회 수를 따로 세지 않아 「저장률」 대신 실제 저장 수를 보여 준다
            $this->kpi('주간 작품 저장', number_format($saveNow), ...$this->percentDelta($saveNow, $savePrev)),
            $this->kpi('상담 → 예약 전환율', $convNow === null ? '-' : number_format($convNow, 1) . '%',
                ...($convNow === null || $convPrev === null ? ['지난주 비교 없음', 0]
                    : [sprintf('지난주 대비 %+.1f%%p', $convNow - $convPrev), $convNow <=> $convPrev])),
        ];

        // 최근 7일 업로드(오늘 포함)
        $days = [];
        for ($i = 6; $i >= 0; $i--) $days[now()->subDays($i)->format('Y-m-d')] = 0;
        $rows = $db->table('starttoo_artworks')->where('created_at', '>=', now()->subDays(6)->startOfDay())
            ->selectRaw('DATE(created_at) as d, COUNT(*) as n')->groupBy('d')->pluck('n', 'd');
        foreach ($rows as $d => $n) if (isset($days[$d])) $days[$d] = (int) $n;
        $max = max(1, max($days));
        $last = array_key_last($days);
        $bars = [];
        foreach ($days as $d => $v) {
            $bars[] = [
                'v' => $v, 'd' => Carbon::parse($d)->format('m.d'),
                'style' => 'display: block; width: 100%; max-width: 44px; border-radius: 6px 6px 2px 2px; height: '
                    . max(2, (int) round($v / $max * 140)) . 'px; background: ' . ($d === $last ? '#FF5A36' : '#141413'),
            ];
        }

        $hidden = 0;
        foreach (['starttoo_artworks', 'starttoo_posts', 'starttoo_reviews', 'starttoo_community_posts'] as $table) {
            $hidden += $db->table($table)->where('moderation_hidden', true)->whereNull('moderation_removed_at')->count();
        }

        return [
            'kpis' => $kpis, 'bars' => $bars, 'hidden' => $hidden,
            'changeRequests' => $db->table('starttoo_bookings')->where('status', 'change_requested')->count(),
        ];
    }

    private function kpi(string $label, string $value, string $delta, int $direction): array
    {
        return ['label' => $label, 'value' => $value, 'delta' => $delta,
            'deltaStyle' => $direction > 0 ? self::UP : ($direction < 0 ? self::DOWN : self::FLAT)];
    }

    /** [문구, 방향] — 지난주가 0이면 퍼센트 대신 건수 */
    private function percentDelta(int $now, int $prev): array
    {
        if ($prev === 0) return [$now > 0 ? "지난주 0건 → {$now}건" : '지난주와 같음', $now <=> 0];
        $pct = ($now - $prev) / $prev * 100;
        return [sprintf('지난주 대비 %+.1f%%', $pct), $now <=> $prev];
    }

    // ================================================================ M-03 회원

    private const MEMBER_TABS = ['전체', '아티스트', '활성', '정지', '휴면'];
    private const PROVIDERS = ['kakao' => '카카오', 'naver' => '네이버', 'apple' => 'Apple', 'google' => 'Google'];
    private const DORMANT_DAYS = 365;

    private function memberQuery(Request $request)
    {
        $tab = in_array($request->query('tab'), self::MEMBER_TABS, true) ? $request->query('tab') : '전체';
        $q = trim((string) $request->query('q', ''));
        $lastSeen = '(select max(coalesce(s.updated_at, s.created_at)) from starttoo_auth_sessions s where s.user_id = u.id)';
        $dormantAt = now()->subDays(self::DORMANT_DAYS)->toDateTimeString();
        $query = $this->db()->table('starttoo_users as u')
            ->select('u.id', 'u.nickname', 'u.status', 'u.artist_status', 'u.created_at')
            ->selectRaw("$lastSeen as last_seen")
            ->selectRaw('(select i.provider from starttoo_auth_identities i where i.user_id = u.id order by i.id limit 1) as via')
            ->selectRaw('(select count(*) from starttoo_reports r where r.subject_user_id = u.id) as report_count');
        match ($tab) {
            '아티스트' => $query->where('u.artist_status', 'approved'),
            '활성' => $query->where('u.status', 'active')->whereRaw("coalesce($lastSeen, u.created_at) >= ?", [$dormantAt]),
            '정지' => $query->where('u.status', 'suspended'),
            '휴면' => $query->where('u.status', 'active')->whereRaw("coalesce($lastSeen, u.created_at) < ?", [$dormantAt]),
            default => null,
        };
        if ($q !== '') {
            $id = preg_match('/^(?:U-?)?(\d{1,12})$/i', $q, $m) ? (int) $m[1] : null;
            $like = '%' . addcslashes(mb_substr($q, 0, 40), '%_\\') . '%';
            $query->where(fn ($w) => $id ? $w->where('u.id', $id)->orWhere('u.nickname', 'like', $like) : $w->where('u.nickname', 'like', $like));
        }
        return [$tab, $q, $query];
    }

    private function memberRow(object $r): array
    {
        $dormant = $r->status === 'active' && Carbon::parse($r->last_seen ?? $r->created_at)->lt(now()->subDays(self::DORMANT_DAYS));
        $state = match ($r->status) { 'suspended' => '정지', 'withdrawn' => '탈퇴', default => $dormant ? '휴면' : '활성' };
        return [
            'id' => 'U-' . $r->id,
            'nick' => $r->status === 'withdrawn' ? '(탈퇴 회원)' : ($r->nickname ?: '(가입 중)'),
            'type' => $r->artist_status === 'approved' ? '아티스트' : '일반',
            'via' => self::PROVIDERS[$r->via ?? ''] ?? '-',
            'joined' => Ui::date($r->created_at),
            'last' => $r->status === 'withdrawn' ? '-' : Ui::ago($r->last_seen),
            'reports' => number_format((int) $r->report_count),
            'state' => $state,
        ];
    }

    public function members(Request $request): View
    {
        [$tab, $q, $query] = $this->memberQuery($request);
        $page = $this->page($request);
        $filtered = (clone $query)->count();
        $rows = $query->orderByDesc('u.id')->offset(($page - 1) * self::PER_PAGE)->limit(self::PER_PAGE)->get();
        $kind = ['활성' => 'green', '정지' => 'red', '휴면' => 'gray', '탈퇴' => 'gray'];
        $rows = $rows->map(function ($r) use ($kind) {
            $row = $this->memberRow($r);
            $id = (int) $r->id;
            return $row + ['statePill' => Ui::pill($kind[$row['state']]), 'typePill' => Ui::pill($row['type'] === '아티스트' ? 'ink' : 'gray'),
                // 이 회원으로 이어지는 링크
                'profileHref' => $r->status === 'active' ? Ui::publicUrl($r->artist_status === 'approved' ? 'shop' : 'user', $id) : null,
                'contentsHref' => Ui::memberContentsUrl($id),
                'reportsHref' => (int) $r->report_count > 0 ? Ui::memberReportsUrl($id) : null,
                'bookingsHref' => Ui::memberBookingsUrl($id)];
        })->all();

        return view('admin.members', [
            'tabs' => Ui::tabs(self::MEMBER_TABS, $tab),
            'rows' => $rows,
            'q' => $q,
            'countLabel' => '총 ' . number_format($this->db()->table('starttoo_users')->count()) . '명 중 ' . number_format($filtered) . '명',
            'pages' => Ui::pages($page, false, (int) ceil($filtered / self::PER_PAGE)),
            'canExport' => $this->can($request, 'export'),
        ]);
    }

    public function exportMembers(Request $request): StreamedResponse
    {
        $this->need($request, 'export');
        [$tab, $q, $query] = $this->memberQuery($request);
        $rows = $query->orderByDesc('u.id')->limit(50000)->get()->map(fn ($r) => array_values($this->memberRow($r)))->all();
        $this->audit($request, '설정', '회원 목록 내보내기', "{$tab}" . ($q !== '' ? " · 검색 {$q}" : '') . ' · ' . count($rows) . '명');
        return $this->csv('starttoo-members-' . now()->format('Ymd-His') . '.csv',
            ['회원 ID', '닉네임', '유형', '가입 경로', '가입일', '최근 접속', '신고 누적', '상태'], $rows);
    }

    // ================================================================ M-07 예약 · 결제

    private const BOOKING_TABS = ['전체' => null, '확정 대기' => ['pending'], '확정' => ['confirmed'], '변경 요청' => ['change_requested'],
        '취소' => ['cancelled'], '완료' => ['completed'], '거절 · 만료' => ['rejected', 'expired']];
    private const BOOKING_STATE = ['pending' => ['확정 대기', 'amber'], 'confirmed' => ['확정', 'blue'], 'change_requested' => ['변경 요청', 'amber'],
        'cancelled' => ['취소', 'gray'], 'completed' => ['완료', 'green'], 'rejected' => ['거절', 'red'], 'expired' => ['만료', 'gray']];

    private function bookingQuery(Request $request)
    {
        $tab = array_key_exists((string) $request->query('tab'), self::BOOKING_TABS) ? (string) $request->query('tab') : '전체';
        $q = $this->db()->table('starttoo_bookings as b')
            ->leftJoin('starttoo_users as c', 'c.id', '=', 'b.customer_id')
            ->leftJoin('starttoo_users as a', 'a.id', '=', 'b.artist_id')
            ->select('b.*', 'c.nickname as customer_nickname', 'c.status as customer_status', 'a.nickname as artist_nickname');
        if (self::BOOKING_TABS[$tab]) $q->whereIn('b.status', self::BOOKING_TABS[$tab]);
        // 회원 관리 「예약」에서 오면: 그 회원이 고객이거나 작가인 예약만
        if (ctype_digit((string) $request->query('member', ''))) {
            $member = (int) $request->query('member');
            $q->where(fn ($w) => $w->where('b.customer_id', $member)->orWhere('b.artist_id', $member));
        }
        return [$tab, $q];
    }

    private function bookingRow(object $b): array
    {
        $when = Carbon::parse($b->starts_at);
        $log = match ($b->status) {
            'confirmed' => Ui::date($b->confirmed_at, 'm.d') . ' 작가 확정',
            'change_requested' => Ui::date($b->updated_at, 'm.d H:i') . ' 일정 변경 요청',
            'cancelled' => Ui::date($b->cancelled_at ?? $b->updated_at, 'm.d') . ' ' . (['customer' => '고객', 'artist' => '작가', 'system' => '자동'][$b->cancelled_by] ?? '') . ' 취소',
            'completed' => Ui::date($b->completed_at ?? $b->updated_at, 'm.d') . ' 시술 완료',
            'rejected' => Ui::date($b->updated_at, 'm.d') . ' 작가 거절',
            'expired' => Ui::date($b->updated_at, 'm.d') . ' 확정 기한 만료',
            default => Ui::date($b->created_at, 'm.d H:i') . ' 요청',
        };
        return [
            'id' => 'B-' . $b->id,
            'customer' => $b->customer_status === 'withdrawn' ? '(탈퇴 회원)' : ($b->customer_nickname ?? '-'),
            'artist' => $b->artist_nickname ?? '-',
            'when' => $when->format('m.d') . ' (' . ['일', '월', '화', '수', '목', '금', '토'][$when->dayOfWeek] . ') ' . $when->format('H:i'),
            'quote' => Ui::won((int) $b->total),
            'state' => self::BOOKING_STATE[$b->status][0] ?? $b->status,
            'log' => $log,
        ];
    }

    public function payments(Request $request): View
    {
        $db = $this->db();
        $today = now()->startOfDay();
        $week = now()->subDays(7);
        $created = $db->table('starttoo_bookings')->where('created_at', '>=', $week);
        $createdN = (clone $created)->count();
        $fast = (clone $created)->whereNotNull('confirmed_at')->whereRaw('confirmed_at <= DATE_ADD(created_at, INTERVAL 1 DAY)')->count();
        $kpis = [
            ['label' => '오늘 예약 요청', 'value' => number_format($db->table('starttoo_bookings')->where('created_at', '>=', $today)->count()),
                'sub' => '어제 ' . number_format($db->table('starttoo_bookings')->whereBetween('created_at', [$today->copy()->subDay(), $today])->count()) . '건'],
            ['label' => '작가 확정률', 'value' => $createdN ? round($fast / $createdN * 100) . '%' : '-', 'sub' => '요청 후 24시간 내 확정 · 최근 7일'],
            ['label' => '변경 · 취소', 'value' => number_format($db->table('starttoo_bookings')->where('status', 'change_requested')->count()
                + $db->table('starttoo_bookings')->where('cancelled_at', '>=', $week)->count()), 'sub' => '변경 요청 중 + 최근 7일 취소'],
            ['label' => '거절 · 만료', 'value' => number_format($db->table('starttoo_bookings')->whereIn('status', ['rejected', 'expired'])->where('updated_at', '>=', $week)->count()),
                'sub' => '최근 7일'],
        ];

        [$tab, $q] = $this->bookingQuery($request);
        $page = $this->page($request);
        $total = (clone $q)->count();
        $rows = $q->orderByDesc('b.id')->offset(($page - 1) * self::PER_PAGE)->limit(self::PER_PAGE)->get()
            ->map(fn ($b) => $this->bookingRow($b) + ['pill' => Ui::pill(self::BOOKING_STATE[$b->status][1] ?? 'gray'),
                'customerHref' => Ui::memberUrl($b->customer_id), 'artistHref' => Ui::memberUrl($b->artist_id)])->all();

        return view('admin.payments', [
            'kpis' => $kpis, 'rows' => $rows,
            'tabs' => Ui::tabs(array_keys(self::BOOKING_TABS), $tab),
            'pages' => Ui::pages($page, false, (int) ceil($total / self::PER_PAGE)),
            'canExport' => $this->can($request, 'export'),
        ]);
    }

    public function exportPayments(Request $request): StreamedResponse
    {
        $this->need($request, 'export');
        [$tab, $q] = $this->bookingQuery($request);
        $rows = $q->orderByDesc('b.id')->limit(50000)->get()->map(fn ($b) => array_values($this->bookingRow($b)))->all();
        $this->audit($request, '설정', '예약 목록 내보내기', $tab . ' · ' . count($rows) . '건');
        return $this->csv('starttoo-bookings-' . now()->format('Ymd-His') . '.csv',
            ['예약번호', '고객', '작가', '시술 일시', '견적', '상태', '최근 변경'], $rows);
    }

    // ================================================================ M-08 광고

    private const AD_TABS = ['광고 목록', '작가 광고 심사', '작가 광고 상품', '환불 계산', '광고 안내', '광고 운영 정책', '구글 광고', '프리미엄 구독'];

    /** 환불 계산 탭(GET, 계산만 한다): ?ad=광고번호&reason=&at=&outage= */
    private function refundCalc(Request $request): array
    {
        $reasons = \App\Services\Starttoo\ArtistAdRefunds::REASONS;
        $in = [
            'ad' => preg_replace('/\D/', '', (string) $request->query('ad', '')),
            'reason' => array_key_exists((string) $request->query('reason'), $reasons) ? (string) $request->query('reason') : 'artist',
            'at' => (string) $request->query('at', ''),
            'outage' => (string) $request->query('outage', ''),
        ];
        $out = ['refundIn' => $in, 'refundReasons' => $reasons, 'refund' => null, 'refundError' => null,
            'refundAction' => route('admin.ads'), 'refundMaxOutage' => \App\Services\Starttoo\ArtistAdRefunds::MAX_OUTAGE_DAYS];
        if ($in['ad'] === '') return $out;
        try {
            $at = null;
            if ($in['at'] !== '') {
                try {
                    $at = Carbon::parse($in['at']);
                } catch (\Throwable) {
                    throw new \InvalidArgumentException('기준 시각을 확인해주세요.');
                }
            }
            $outage = max(0, min(\App\Services\Starttoo\ArtistAdRefunds::MAX_OUTAGE_DAYS, (int) $in['outage']));
            $out['refund'] = app(\App\Services\Starttoo\ArtistAdRefunds::class)->calculate((int) $in['ad'], $in['reason'], $at, $outage);
        } catch (\InvalidArgumentException $e) {
            $out['refundError'] = $e->getMessage();
        }
        return $out;
    }

    private const SLOT_LABELS = ['community_top' => '커뮤니티 맨 위', 'feed_inline' => '홈 피드 · 게시물 사이',
        'moving_inline' => '무빙 피드 · 영상 사이 전체 화면(세로 이미지 9:16 권장)'];

    public function ads(Request $request): View
    {
        $rows = $this->db()->table('starttoo_ads as ad')->leftJoin('starttoo_users as u', 'u.id', '=', 'ad.artist_id')
            ->select('ad.*', 'u.nickname as artist_nickname')->orderByDesc('ad.id')->limit(100)->get();
        $canManage = $this->can($request, 'ads.manage');
        $slots = $rows->map(function ($ad) use ($canManage) {
            $live = $ad->active && (!$ad->starts_at || now()->gte($ad->starts_at)) && (!$ad->ends_at || now()->lt($ad->ends_at));
            // 작가 광고(product): 심사 중 · 반려 · 종료 · 게시 중지 · 작가가 멈춤. 운영자는 「수정」으로 전부 고칠 수 있고,
            // 스위치를 누르면 수정 패널의 「게시 중지(사유) · 재개」로 간다(종료하지 않는다).
            $artistAd = ($ad->product ?? null) !== null;
            $artistState = $artistAd ? app(\App\Services\Starttoo\ArtistAds::class)->state($ad) : null;
            [$state, $kind] = match (true) {
                $artistAd && $ad->status === 'reviewing' => ['심사 중', 'blue'],
                $artistAd && $ad->status === 'rejected' => ['반려', 'red'],
                $artistAd && $ad->status === 'ended' => ['종료', 'gray'],
                $artistState === 'suspended' => [$ad->pending ? '게시 중지 · 수정 심사 대기' : '게시 중지', 'red'],
                $artistAd && $ad->paused_at !== null => ['작가가 멈춤', 'gray'],
                !$ad->active => ['중지', 'gray'],
                $ad->ends_at && now()->gte($ad->ends_at) => ['기간 종료', 'gray'],
                $ad->starts_at && now()->lt($ad->starts_at) => ['노출 예정', 'blue'],
                default => ['노출 중', 'green'],
            };
            $where = $artistAd
                ? '작가 광고 · ' . (\App\Services\Starttoo\ArtistAds::PRODUCTS[$ad->product]['name'] ?? $ad->product)
                : (self::SLOT_LABELS[$ad->slot] ?? $ad->slot);
            $canSwitch = $canManage && (!$artistAd || in_array($artistState, ['running', 'scheduled', 'paused', 'suspended'], true));
            return [
                'where' => $where,
                'who' => $ad->advertiser . ($ad->artist_nickname ? ' · ' . $ad->artist_nickname : ''),
                'whoHref' => $ad->artist_id ? Ui::publicUrl('shop', $ad->artist_id) : null,
                'title' => $ad->title,
                'period' => ($ad->starts_at ? Ui::date($ad->starts_at, 'm.d') : '바로') . ' ~ ' . ($ad->ends_at ? Ui::date($ad->ends_at, 'm.d') : '계속'),
                'imp' => number_format((int) $ad->impressions), 'clk' => number_format((int) $ad->clicks),
                // 신규 노출(처음 본 회원 수) · 클릭률(클릭 ÷ 신규 노출)
                'reach' => number_format((int) ($ad->reach ?? 0)),
                'ctr' => (int) ($ad->reach ?? 0) > 0 ? number_format((int) $ad->clicks / (int) $ad->reach * 100, 1) . '%' : '-',
                // 작가 광고 스위치: 게시 중지가 아니면 켜짐(작가가 멈춘 것은 작가 몫)
                'on' => $artistAd ? $artistState !== 'suspended' : (bool) $ad->active, 'state' => $state, 'pill' => Ui::pill($kind),
                'aria' => "{$where} · {$ad->title} 노출", 'canToggle' => $canSwitch,
                'action' => route('admin.ads.toggle', $ad->id),
                // 작가 광고: 스위치 = 수정 패널의 게시 중지 · 재개 칸으로(사유를 적어야 해서)
                'switchHref' => $artistAd ? Ui::url(['sel' => $ad->id]) . '#suspend' : null,
                'editHref' => $canManage ? Ui::url(['sel' => $ad->id]) : null,
                // 작가 광고: 환불 계산 탭으로(그 광고 번호를 넣어 연다)
                'refundHref' => $artistAd && !in_array($ad->status, ['reviewing', 'rejected'], true) ? route('admin.ads', ['section' => '환불 계산', 'ad' => $ad->id]) : null,
                'rowStyle' => Ui::rowBg((string) request()->query('sel') === (string) $ad->id),
                'track' => Ui::switchTrack($artistAd ? $artistState !== 'suspended' : (bool) $ad->active, !$canSwitch),
                'knob' => 'width: 20px; height: 20px; border-radius: 50%; background: #FFFFFF; display: block',
                'live' => $live,
            ];
        })->all();

        // 등록 · 수정 패널: ?sel=광고번호 · ?sel=new
        $isNew = $request->query('sel') === 'new';
        $edit = !$isNew && ctype_digit((string) $request->query('sel', '')) ? $rows->firstWhere('id', (int) $request->query('sel')) : null;
        // 작가 광고는 따로 만든 수정 패널(상품 · 문구 · 키워드 · 기간 · 사진)
        $artistEdit = $edit && ($edit->product ?? null) !== null ? $edit : null;
        if ($artistEdit) $edit = null;
        $fmt = fn ($at) => $at ? Carbon::parse($at)->format('Y-m-d') : '';
        $fmtAt = fn ($at) => $at ? Carbon::parse($at)->format('Y-m-d\TH:i') : '';
        $admob = app(Ads::class)->admobConfig();
        $artistData = AdminArtistAdsController::viewData($canManage);
        // 설정 항목별 탭(?section=). 등록 · 수정 패널(?sel=)은 광고 목록 탭에서 연다
        [$section, $sections] = Ui::sections(self::AD_TABS, ['작가 광고 심사' => count($artistData['queue'])],
            $request->query('sel') !== null ? self::AD_TABS[0] : null);
        return view('admin.ads', [
            'section' => $section, 'sections' => $sections,
            ...($section === '환불 계산' ? $this->refundCalc($request) : []),
            'slots' => $slots,
            // 작가 광고 심사 · 상품 설정(AdminArtistAdsController)
            ...$artistData,
            // 구글 광고(AdMob): 자체 광고가 없을 때 같은 자리를 채운다
            'admob' => $admob, 'admobOn' => $admob['enabled'],
            'admobTrack' => Ui::switchTrack($admob['enabled'], !$canManage), 'knob' => Ui::KNOB,
            'admobAction' => route('admin.ads.admob'),
            'artists' => $this->db()->table('starttoo_users')->where('artist_status', 'approved')->where('status', 'active')->count(),
            'canManage' => $canManage,
            'showForm' => $canManage && ($isNew || $edit),
            'artistForm' => $canManage && $artistEdit ? [
                'title' => "작가 광고 #{$artistEdit->id} 수정",
                'action' => route('admin.ads.artist.update', $artistEdit->id),
                'product' => $artistEdit->product, 'advertiser' => $artistEdit->advertiser, 'copy' => $artistEdit->title,
                'keywords' => implode(', ', json_decode((string) $artistEdit->keywords, true) ?: []),
                'from' => $fmtAt($artistEdit->starts_at), 'until' => $fmtAt($artistEdit->ends_at),
                // 심사 전(심사 중 · 반려)은 기간이 아직 없다(승인할 때 정한다)
                'needsPeriod' => !in_array($artistEdit->status, ['reviewing', 'rejected'], true),
                'image' => $artistEdit->image_path ? app(\App\Services\Starttoo\MediaUrls::class)->url($artistEdit->image_path, 600) : null,
                'hasArtwork' => (bool) $artistEdit->artwork_id,
                'pending' => (bool) $artistEdit->pending,
                'days' => $artistEdit->days, 'amount' => (int) $artistEdit->amount,
                // 게시 중지 · 재개(광고 목록 스위치가 여기로 온다)
                'state' => $artistEditState = app(\App\Services\Starttoo\ArtistAds::class)->state($artistEdit),
                'suspendReason' => (string) ($artistEdit->suspend_reason ?? ''),
                'suspendedAt' => ($artistEdit->suspended_at ?? null) ? Ui::date($artistEdit->suspended_at, 'm.d H:i') : null,
                // 예전 스위치로 노출만 꺼 둔 광고(상태는 노출 중): 재개로 다시 켠다
                'legacyOff' => $artistEdit->status === 'running' && !$artistEdit->active && !$artistEdit->paused_at && !($artistEdit->suspended_at ?? null),
                'suspendAction' => route('admin.ads.artist.suspend', $artistEdit->id),
                'resumeAction' => route('admin.ads.artist.resume', $artistEdit->id),
                'reasonMax' => \App\Services\Starttoo\ArtistAds::SUSPEND_REASON_MAX,
            ] : null,
            'productOptions' => array_map(fn ($p) => $p['name'], \App\Services\Starttoo\ArtistAds::PRODUCTS),
            'maxCopy' => \App\Services\Starttoo\ArtistAds::MAX_COPY,
            'newHref' => Ui::url(['sel' => 'new']),
            'closeHref' => Ui::url(['sel' => null]),
            'slotOptions' => self::SLOT_LABELS,
            // 구글 광고 설정은 AdMob 을 섞는 자리만(무빙 피드는 자체 광고만)
            'admobSlotOptions' => array_intersect_key(self::SLOT_LABELS, array_flip(Ads::ADMOB_SLOTS)),
            'form' => $edit ? [
                'title' => "광고 #{$edit->id} 수정", 'action' => route('admin.ads.update', $edit->id), 'save' => '저장',
                'slot' => $edit->slot, 'advertiser' => $edit->advertiser, 'text' => $edit->title,
                'artist' => $edit->artist_id ? (string) $edit->artist_id : '', 'link' => (string) $edit->link,
                'from' => $fmt($edit->starts_at), 'until' => $fmt($edit->ends_at),
                'image' => $edit->image_path ? app(\App\Services\Starttoo\MediaUrls::class)->url($edit->image_path, 600) : null,
            ] : [
                'title' => '새 광고', 'action' => route('admin.ads.store'), 'save' => '등록',
                'slot' => array_key_first(self::SLOT_LABELS), 'advertiser' => '', 'text' => '', 'artist' => '', 'link' => '', 'from' => '', 'until' => '', 'image' => null,
            ],
        ]);
    }

    /** 구글 광고(AdMob) 설정 저장: 전체 켜기 · 자리별 켜기 · 광고 단위 ID(Android · iOS) */
    public function saveAdmob(Request $request): RedirectResponse
    {
        $this->need($request, 'ads.manage');
        $rules = ['enabled' => ['nullable', 'boolean']];
        foreach (Ads::ADMOB_SLOTS as $slot) {
            $rules["slots.$slot.on"] = ['nullable', 'boolean'];
            $rules["slots.$slot.android"] = ['nullable', 'string', 'regex:' . Ads::ADMOB_UNIT];
            $rules["slots.$slot.ios"] = ['nullable', 'string', 'regex:' . Ads::ADMOB_UNIT];
            $rules["slots.$slot.share"] = ['nullable', 'integer', 'min:0', 'max:100'];
        }
        $data = $request->validate($rules, ['*.regex' => '광고 단위 ID 모양을 확인해주세요(ca-app-pub-숫자16자리/숫자10자리).',
            'slots.*.*.regex' => '광고 단위 ID 모양을 확인해주세요(ca-app-pub-숫자16자리/숫자10자리).']);
        $before = app(Ads::class)->admobConfig();
        $value = ['enabled' => (bool) ($data['enabled'] ?? false), 'slots' => []];
        foreach (Ads::ADMOB_SLOTS as $slot) {
            $s = $data['slots'][$slot] ?? [];
            $value['slots'][$slot] = ['on' => (bool) ($s['on'] ?? false), 'android' => trim((string) ($s['android'] ?? '')), 'ios' => trim((string) ($s['ios'] ?? '')),
                'share' => (int) ($s['share'] ?? 50)];
        }
        app(\App\Services\Starttoo\Settings::class)->set('admob', $value, (int) $this->admin($request)->id);
        $this->audit($request, '설정', '구글 광고 설정 저장', '광고 · 프리미엄 · ' . ($value['enabled'] ? '켜짐' : '꺼짐'), ['before' => $before, 'after' => $value]);

        return back()->with('status', '구글 광고 설정을 저장했어요 · 앱이 다음에 광고를 받을 때부터 적용돼요');
    }

    private function adInput(Request $request): array
    {
        $data = $request->validate([
            'slot' => ['required', 'in:' . implode(',', array_keys(self::SLOT_LABELS))],
            'advertiser' => ['required', 'string', 'max:60'],
            'title' => ['required', 'string', 'max:80'],
            'artist' => ['nullable', 'string', 'regex:/^(U-?)?\d{1,12}$/i'],
            'link' => ['nullable', 'string', 'max:200'],
            'from' => ['nullable', 'date'],
            'until' => ['nullable', 'date'],
            'image' => ['nullable', 'image', 'mimes:jpg,jpeg,png,webp', 'max:8192'],
            'remove_image' => ['nullable', 'boolean'],
        ], ['artist.regex' => '작가 회원 번호를 확인해주세요(예: 12 또는 U-12).', 'image.max' => '이미지는 8MB까지 올릴 수 있어요.']);
        return [
            'slot' => $data['slot'], 'advertiser' => $data['advertiser'], 'title' => $data['title'],
            'artist' => ($data['artist'] ?? '') !== '' ? (int) preg_replace('/\D/', '', $data['artist']) : null,
            'link' => ($data['link'] ?? '') !== '' ? $data['link'] : null,
            'from' => $data['from'] ?? null, 'until' => $data['until'] ?? null,
            'image_bytes' => $request->hasFile('image') ? (string) file_get_contents($request->file('image')->getRealPath()) : '',
            'remove_image' => (bool) ($data['remove_image'] ?? false),
        ];
    }

    public function storeAd(Request $request): RedirectResponse
    {
        $this->need($request, 'ads.manage');
        $in = $this->adInput($request);
        try {
            $id = app(Ads::class)->add($in);
        } catch (\InvalidArgumentException $e) {
            return back()->withErrors(['ad' => $e->getMessage()])->withInput();
        } catch (\Throwable $e) {
            report($e);
            return back()->withErrors(['ad' => '이미지를 처리하지 못했어요. 다른 이미지로 다시 시도해주세요.'])->withInput();
        }
        $this->audit($request, '설정', '광고 등록', "광고 #{$id} · {$in['advertiser']} · {$in['title']}");

        return redirect(route('admin.ads', ['sel' => $id]))->with('status', '광고를 등록했어요 · 바로 노출돼요');
    }

    public function updateAd(Request $request, int $id): RedirectResponse
    {
        $this->need($request, 'ads.manage');
        $in = $this->adInput($request);
        $before = $this->db()->table('starttoo_ads')->where('id', $id)->first();
        abort_unless($before, 404);
        try {
            app(Ads::class)->update($id, $in);
        } catch (\InvalidArgumentException $e) {
            return back()->withErrors(['ad' => $e->getMessage()])->withInput();
        } catch (\Throwable $e) {
            report($e);
            return back()->withErrors(['ad' => '이미지를 처리하지 못했어요. 다른 이미지로 다시 시도해주세요.'])->withInput();
        }
        $this->audit($request, '설정', '광고 수정', "광고 #{$id} · {$in['advertiser']} · {$in['title']}",
            ['title' => [$before->title, $in['title']], 'artist' => [$before->artist_id, $in['artist']], 'link' => [$before->link, $in['link']]]);

        return back()->with('status', '광고를 저장했어요');
    }

    /** 작가 광고 운영자 수정(상품 · 광고주 · 문구 · 키워드 · 기간 · 사진). 심사 없이 바로 반영 */
    public function updateArtistAd(Request $request, int $id): RedirectResponse
    {
        $this->need($request, 'ads.manage');
        $data = $request->validate([
            'product' => ['required', 'in:' . implode(',', array_keys(\App\Services\Starttoo\ArtistAds::PRODUCTS))],
            'advertiser' => ['required', 'string', 'max:60'],
            'copy' => ['required', 'string', 'max:120'],
            'keywords' => ['nullable', 'string', 'max:120'],
            'from' => ['nullable', 'date'],
            'until' => ['nullable', 'date'],
            'image' => ['nullable', 'image', 'mimes:jpg,jpeg,png,webp', 'max:8192'],
            'remove_image' => ['nullable', 'boolean'],
        ], ['image.max' => '이미지는 8MB까지 올릴 수 있어요.']);
        try {
            $r = app(\App\Services\Starttoo\ArtistAds::class)->adminUpdate($id, [
                ...$data,
                'image_bytes' => $request->hasFile('image') ? (string) file_get_contents($request->file('image')->getRealPath()) : '',
                'remove_image' => (bool) ($data['remove_image'] ?? false),
            ]);
        } catch (\InvalidArgumentException $e) {
            return back()->withErrors(['ad' => $e->getMessage()])->withInput();
        } catch (\Throwable $e) {
            report($e);
            return back()->withErrors(['ad' => '이미지를 처리하지 못했어요. 다른 이미지로 다시 시도해주세요.'])->withInput();
        }
        $b = $r['before'];
        $a = $r['after'];
        $changes = [];
        foreach (['product', 'advertiser', 'title', 'keywords', 'image_path'] as $k) {
            if ((string) $b->$k !== (string) $a[$k]) $changes[$k] = [$b->$k, $a[$k]];
        }
        foreach (['starts_at', 'ends_at'] as $k) {
            $old = $b->$k ? Carbon::parse($b->$k)->format('Y-m-d H:i') : null;
            $new = $a[$k] ? Carbon::parse($a[$k])->format('Y-m-d H:i') : null;
            if ($old !== $new) $changes[$k] = [$old, $new];
        }
        $this->audit($request, '설정', '작가 광고 수정', "광고 #{$id} · {$a['advertiser']} · " . str_replace("\n", ' / ', $a['title']), $changes);

        return back()->with('status', '작가 광고를 저장했어요 · 바로 반영돼요');
    }

    /** 작가 광고 게시 중지(사유는 작가에게 보인다). 작가가 앱에서 고쳐 다시 심사를 요청하면 승인할 때 재개된다. */
    public function suspendArtistAd(Request $request, int $id): RedirectResponse
    {
        $this->need($request, 'ads.manage');
        $reason = (string) $request->input('reason', '');
        $ads = app(\App\Services\Starttoo\ArtistAds::class);
        try {
            $ad = $ads->suspend($id, $reason);
        } catch (\InvalidArgumentException $e) {
            return back()->withErrors(['ad' => $e->getMessage()])->withInput();
        }
        $name = \App\Services\Starttoo\ArtistAds::PRODUCTS[$ad->product]['name'] ?? $ad->product;
        $this->audit($request, '설정', '작가 광고 게시 중지', "광고 #{$id} · {$name} · {$ad->advertiser} · 사유: " . trim($reason), ['suspended' => [false, true]]);

        return back()->with('status', "작가 광고 #{$id}({$name})를 게시 중지했어요 · 작가에게 사유와 함께 알림이 가요");
    }

    /** 게시 재개(작가 수정 없이 운영자가 바로). 멈춘 기간만큼 종료일이 늘어난다. */
    public function resumeArtistAd(Request $request, int $id): RedirectResponse
    {
        $this->need($request, 'ads.manage');
        try {
            $ad = app(\App\Services\Starttoo\ArtistAds::class)->resume($id);
        } catch (\InvalidArgumentException $e) {
            return back()->withErrors(['ad' => $e->getMessage()]);
        }
        $name = \App\Services\Starttoo\ArtistAds::PRODUCTS[$ad->product]['name'] ?? $ad->product;
        $this->audit($request, '설정', '작가 광고 게시 재개', "광고 #{$id} · {$name} · {$ad->advertiser}", ['suspended' => [true, false]]);

        return back()->with('status', "작가 광고 #{$id}({$name})를 다시 게시했어요");
    }

    public function toggleAd(Request $request, int $id): RedirectResponse
    {
        $this->need($request, 'ads.manage');
        $ad = $this->db()->table('starttoo_ads')->where('id', $id)->first();
        abort_unless($ad, 404);
        // 작가 광고는 사유가 필요한 게시 중지 · 재개(suspendArtistAd · resumeArtistAd)로 한다
        if (($ad->product ?? null) !== null) {
            return redirect(route('admin.ads', ['sel' => $id]) . '#suspend');
        }
        $on = !$ad->active;
        app(Ads::class)->setActive($id, $on);
        $where = self::SLOT_LABELS[$ad->slot] ?? $ad->slot;
        $this->audit($request, '설정', $on ? '광고 노출 시작' : '광고 노출 중지', "광고 #{$id} · {$where} · {$ad->title}", ['active' => [(bool) $ad->active, $on]]);

        return back()->with('status', "{$where} · {$ad->title} " . ($on ? '노출을 시작했어요' : '노출을 중지했어요'));
    }

    // ================================================================ M-09 설정 · 감사 로그

    private const SETTINGS_SECTIONS = ['관리자 계정 · 권한', '운영 정책', '감사 로그'];

    private const LOG_TABS = ['전체', '접속', '심사', '제재', '콘텐츠', '설정'];

    public function settings(Request $request): View
    {
        $tab = in_array($request->query('tab'), self::LOG_TABS, true) ? $request->query('tab') : '전체';
        $accounts = app(AdminAccounts::class);
        $settings = app(Settings::class);
        $isSuper = $this->can($request, 'settings.policy');
        $kinds = ['심사' => 'amber', '제재' => 'red', '콘텐츠' => 'blue', '설정' => 'ink', '접속' => 'gray'];

        $admins = $this->db()->table('starttoo_admins')->orderBy('id')->get()->map(fn ($a) => [
            'name' => $a->name . ($a->disabled_at ? ' (사용 중지)' : ''),
            'role' => $accounts->roleLabel($a->role),
            'mfa' => $a->otp_secret ? '사용' : '미설정',
            'last' => Ui::ago($a->last_login_at),
            'rolePill' => Ui::pill($a->role === 'super' ? 'ink' : 'gray'),
            'mfaPill' => Ui::pill($a->otp_secret ? 'green' : 'red'),
        ])->all();

        $policies = [];
        foreach (Settings::POLICIES as $id => $def) {
            $on = $settings->bool($id);
            $policies[] = $def + ['id' => $id, 'on' => $on, 'canToggle' => $isSuper, 'action' => route('admin.settings.policy', $id),
                'track' => Ui::switchTrack($on, !$isSuper)];
        }

        $audit = app(AdminAudit::class);
        $page = $this->page($request);
        $q = $audit->query($tab === '전체' ? null : $tab);
        $total = (clone $q)->count();
        $logs = $q->offset(($page - 1) * self::PER_PAGE)->limit(self::PER_PAGE)->get()
            ->map(fn ($l) => $audit->row($l) + ['pill' => Ui::pill($kinds[$l->category] ?? 'gray')])->all();

        // 설정 항목별 탭(?section=). 감사 로그 분류는 그 탭 안의 ?tab=
        [$section, $sections] = Ui::sections(self::SETTINGS_SECTIONS);
        return view('admin.settings', [
            'section' => $section, 'sections' => $sections,
            'admins' => $admins, 'policies' => $policies, 'logs' => $logs,
            'tabs' => Ui::tabs(self::LOG_TABS, $tab),
            'pages' => Ui::pages($page, false, (int) ceil($total / self::PER_PAGE)),
            'canExport' => $this->can($request, 'export'),
        ]);
    }

    public function exportLogs(Request $request): StreamedResponse
    {
        $this->need($request, 'export');
        $tab = in_array($request->query('tab'), self::LOG_TABS, true) ? $request->query('tab') : '전체';
        $audit = app(AdminAudit::class);
        $rows = $audit->query($tab === '전체' ? null : $tab)->limit(50000)->get()
            ->map(fn ($l) => [Ui::date($l->created_at, 'Y-m-d H:i:s'), $l->admin_name, $l->category, $l->action, $l->target, $l->ip ?? '-'])->all();
        $this->audit($request, '설정', '감사 로그 내보내기', $tab . ' · ' . count($rows) . '건');
        return $this->csv('starttoo-audit-' . now()->format('Ymd-His') . '.csv', ['시간', '관리자', '구분', '작업', '대상', 'IP'], $rows);
    }

    public function togglePolicy(Request $request, string $id): RedirectResponse
    {
        $this->need($request, 'settings.policy');
        $def = Settings::POLICIES[$id] ?? abort(404);
        $settings = app(Settings::class);
        $before = $settings->bool($id);
        $settings->set($id, !$before, (int) $this->admin($request)->id);
        $this->audit($request, '설정', $def['label'] . (!$before ? ' 켜기' : ' 끄기'), '운영 정책', [$id => [$before, !$before]]);

        return back()->with('status', $def['label'] . (!$before ? '을(를) 켰어요' : '을(를) 껐어요'));
    }
}
