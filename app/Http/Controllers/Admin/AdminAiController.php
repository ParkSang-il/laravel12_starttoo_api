<?php

namespace App\Http\Controllers\Admin;

use App\Services\Starttoo\Fittings;
use App\Services\Starttoo\MediaUrls;
use App\Services\Starttoo\Settings;
use App\Support\AdminUi as Ui;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\View\View;

/**
 * 운영 콘솔 AI 서비스: M-10 사용량 · 비용 · M-11 작업 모니터 · M-13 가상 모델 라이브러리.
 * 숫자는 starttoo_fit_results(가상 피팅 · 도안 추출 · 필러 · 커버업 공통 작업 표)에서 센다.
 * 비용은 .env STARTTOO_AI_UNIT_COST(1회 추정 단가) × 차감 횟수로 계산한 추정치다.
 */
class AdminAiController extends AdminBaseController
{
    private const TYPES = ['blend' => '가상 피팅 합성', 'extract' => '도안 추출', 'fill' => '타투 필러 (3안)', 'coverup' => '커버업 생성'];
    /** 필러는 3안 = AI 3번 */
    private const UNIT_WEIGHT = ['fill' => 3];
    private const ERRORS = [
        'blocked' => ['SAFETY_BLOCK', '안전 정책으로 생성이 거부됐어요. 노출이 큰 신체 사진일 수 있어요. 횟수는 차감되지 않았어요.'],
        'moved' => ['DESIGN_MOVED', '합성 결과에서 도안 위치 · 크기가 어긋나 결과를 버렸어요(1번 다시 시도 후). 횟수는 차감되지 않았어요.'],
        'ai_failed' => ['AI_FAILED', 'AI 응답이 없거나 시간이 초과됐어요. 횟수는 차감되지 않았어요.'],
    ];

    private function won(int $v): string
    {
        return '₩' . number_format($v);
    }

    private function model(): string
    {
        return (string) (config('starttoo.gemini_blend_model') ?: config('starttoo.gemini_image_model'));
    }

    // ================================================================ M-10 AI 사용량 · 비용

    public function usage(Request $request): View
    {
        $db = $this->db();
        $unit = (int) config('starttoo.ai_unit_cost', 0);
        $budget = (int) config('starttoo.ai_monthly_budget', 0);
        $settings = app(Settings::class);
        $ai = fn () => $db->table('starttoo_fit_results')->where('cost_units', '>', 0); // 기기에서 만든 결과(0) 제외
        $units = "SUM(CASE WHEN type = 'fill' THEN cost_units * 3 ELSE cost_units END)";
        $today = today();
        $yesterday = today()->subDay();

        $reqToday = $ai()->where('created_at', '>=', $today)->count();
        $reqYesterday = $ai()->whereBetween('created_at', [$yesterday, $today])->count();
        $unitsToday = (int) $ai()->where('created_at', '>=', $today)->selectRaw("$units as u")->value('u');

        // 한도 도달 회원: 오늘 차감된 횟수 ≥ 그 회원의 하루 한도
        $memberLimit = $settings->int('fit_daily_limit');
        $artistLimit = $settings->int('fit_artist_daily_limit');
        $usedRows = $db->table('starttoo_fit_results as f')->join('starttoo_users as u', 'u.id', '=', 'f.user_id')
            ->where('f.created_at', '>=', $today)->where('f.status', '<>', 'failed')
            ->groupBy('f.user_id', 'u.artist_status')->selectRaw('f.user_id, u.artist_status, SUM(f.cost_units) as used')->get();
        $reached = $usedRows->filter(function ($r) use ($memberLimit, $artistLimit) {
            $limit = $r->artist_status === 'approved' ? $artistLimit : $memberLimit;
            return $limit > 0 && (int) $r->used >= $limit;
        })->count();

        $avg = fn ($from, $to) => $db->table('starttoo_fit_results')->where('status', 'ready')->whereBetween('created_at', [$from, $to])
            ->selectRaw('AVG(TIMESTAMPDIFF(SECOND, created_at, updated_at)) as s')->value('s');
        $avgToday = $avg($today, now());
        $avgWeek = $avg(today()->subDays(7), $today);

        $pct = fn (int $now, int $prev) => $prev === 0 ? ($now > 0 ? '어제 0건' : '어제와 같음') : sprintf('어제 대비 %+.1f%%', ($now - $prev) / $prev * 100);
        $kpis = [
            ['label' => '오늘 생성 요청', 'value' => number_format($reqToday), 'sub' => $pct($reqToday, $reqYesterday),
                'subStyle' => Ui::sub($reqToday >= $reqYesterday ? 'up' : 'muted')],
            ['label' => '오늘 비용 (추정)', 'value' => $unit > 0 ? $this->won($unitsToday * $unit) : '-',
                'sub' => $unit > 0 ? 'AI 이미지 1회 ' . $this->won($unit) . ' 기준 · 실패 포함' : '1회 단가 미설정 (.env STARTTOO_AI_UNIT_COST)', 'subStyle' => Ui::sub('muted')],
            ['label' => '한도 도달 회원', 'value' => number_format($reached) . '명', 'sub' => '오늘 사용한 회원 ' . number_format($usedRows->count()) . '명 중',
                'subStyle' => Ui::sub($reached > 0 ? 'down' : 'muted')],
            ['label' => '평균 생성 시간', 'value' => $avgToday === null ? '-' : number_format((float) $avgToday, 1) . '초',
                'sub' => $avgToday === null || $avgWeek === null ? '지난 7일 비교 없음' : sprintf('지난 7일 대비 %+.1f초', $avgToday - $avgWeek),
                'subStyle' => Ui::sub($avgToday !== null && $avgWeek !== null && $avgToday <= $avgWeek ? 'up' : 'muted')],
        ];

        $byType = $ai()->where('created_at', '>=', $today)->groupBy('type', 'status')->selectRaw('type, status, COUNT(*) as n')->get();
        $features = [];
        foreach (self::TYPES as $type => $name) {
            $rows = $byType->where('type', $type);
            $req = (int) $rows->sum('n');
            $ok = (int) $rows->where('status', 'ready')->sum('n');
            $failed = (int) $rows->where('status', 'failed')->sum('n');
            $weight = self::UNIT_WEIGHT[$type] ?? 1;
            $features[] = [
                'name' => $name, 'req' => number_format($req),
                'ok' => $ok + $failed > 0 ? number_format($ok / ($ok + $failed) * 100, 1) . '%' : '-',
                'unit' => $unit > 0 ? $this->won($unit * $weight) : '-',
                'cost' => $unit > 0 ? $this->won($req * $unit * $weight) : '-',
                'quota' => $type === 'fill' ? '1회 차감 · 하루 ' . (int) config('starttoo.fit_fill_daily_limit', 1) . '번' : '1회 차감',
                'pill' => Ui::pill('ink'),
            ];
        }

        // 최근 7일(비용 단가가 없으면 요청 수)
        $days = [];
        for ($i = 6; $i >= 0; $i--) $days[today()->subDays($i)->format('Y-m-d')] = 0;
        $daily = $ai()->where('created_at', '>=', today()->subDays(6))->groupByRaw('DATE(created_at)')
            ->selectRaw("DATE(created_at) as d, $units as u, COUNT(*) as n")->get();
        foreach ($daily as $r) if (isset($days[$r->d])) $days[$r->d] = $unit > 0 ? (int) $r->u * $unit : (int) $r->n;
        $max = max(1, max($days));
        $last = array_key_last($days);
        $bars = [];
        foreach ($days as $d => $v) {
            $bars[] = ['v' => $unit > 0 ? number_format($v / 1000, 1) . '천' : number_format($v),
                'd' => Carbon::parse($d)->format('m.d'),
                'style' => 'display: block; width: 100%; max-width: 40px; border-radius: 6px 6px 2px 2px; height: ' . max(2, (int) round($v / $max * 100)) . 'px; background: ' . ($d === $last ? '#FF5A36' : '#141413')];
        }

        $month = app(Fittings::class)->monthCost();
        $budgetPct = $budget > 0 ? min(100, (int) round($month / $budget * 100)) : 0;
        $forecast = $budget > 0 ? (int) round($month / max(1, now()->day) * now()->daysInMonth) : 0;

        $canManage = $this->can($request, 'ai.manage');
        $limits = [];
        foreach ([['member', 'fit_daily_limit', '일반 회원 · 하루 한도', '자정(KST)에 초기화 · 실패 건은 횟수에서 빠져요', '일반 회원'],
                  ['artist', 'fit_artist_daily_limit', '아티스트 · 하루 한도', '승인된 작가 · 상담 중 고객 시안 작업용', '아티스트']] as [$key, $setting, $label, $note, $who]) {
            $limits[] = ['label' => $label, 'note' => $note, 'value' => $settings->int($setting) > 0 ? $settings->int($setting) . '회' : '제한 없음',
                'decAria' => "{$who} 한도 줄이기", 'incAria' => "{$who} 한도 늘리기",
                'decAction' => route('admin.ai-usage.limit', [$key, 'down']), 'incAction' => route('admin.ai-usage.limit', [$key, 'up'])];
        }
        $blockOn = $settings->bool('ai_budget_block');
        $guards = [
            ['label' => '예산 초과 시 신규 생성 중단', 'note' => $budget > 0 ? '진행 중 작업은 끝까지 처리' : '월 예산 미설정 (.env STARTTOO_AI_MONTHLY_BUDGET)',
                'on' => $blockOn, 'locked' => !$canManage || $budget <= 0, 'track' => Ui::switchTrack($blockOn, !$canManage || $budget <= 0),
                'action' => route('admin.ai-usage.guard', 'block'), 'aria' => '예산 초과 시 신규 생성 중단'],
            ['label' => '실패 · 안전 차단 시 한도 복구', 'note' => '항상 적용 · 실패 건은 횟수에서 빠져요',
                'on' => true, 'locked' => true, 'track' => Ui::switchTrack(true, true), 'action' => null, 'aria' => '실패 · 안전 차단 시 한도 복구'],
        ];

        return view('admin.ai-usage', [
            'today' => now()->format('Y.m.d') . ' (' . ['일', '월', '화', '수', '목', '금', '토'][now()->dayOfWeek] . ') 기준' . ($unit > 0 ? ' · 비용은 추정치' : ''),
            'kpis' => $kpis, 'features' => $features, 'bars' => $bars, 'barsTitle' => $unit > 0 ? '일별 비용' : '일별 AI 요청',
            'limits' => $limits, 'guards' => $guards, 'canManage' => $canManage, 'knob' => Ui::KNOB,
            'modelCode' => $this->model(),
            'hasBudget' => $budget > 0, 'budgetPct' => $budgetPct . '%', 'monthCost' => $this->won($month), 'budget' => $this->won($budget),
            'budgetNote' => $budget > 0 ? '이 추세면 월말 약 ' . $this->won($forecast) : '',
            'budgetBar' => "display: block; height: 100%; width: {$budgetPct}%; background: #FF5A36",
        ]);
    }

    public function stepLimit(Request $request, string $key, string $dir): RedirectResponse
    {
        $this->need($request, 'ai.manage');
        abort_unless(in_array($key, ['member', 'artist'], true) && in_array($dir, ['up', 'down'], true), 404);
        $setting = $key === 'member' ? 'fit_daily_limit' : 'fit_artist_daily_limit';
        $settings = app(Settings::class);
        $before = $settings->int($setting);
        $after = max(1, min($key === 'member' ? 20 : 50, ($before > 0 ? $before : 0) + ($dir === 'up' ? 1 : -1)));
        if ($after === $before) return back();
        $settings->set($setting, $after, (int) $this->admin($request)->id);
        $who = $key === 'member' ? '일반 회원' : '아티스트';
        $this->audit($request, '설정', "AI 하루 한도 변경 ({$who} {$before}회 → {$after}회)", 'AI 사용 한도', [$setting => [$before, $after]]);

        return back()->with('status', "{$who} 하루 한도를 {$after}회로 바꿨어요");
    }

    public function toggleGuard(Request $request, string $id): RedirectResponse
    {
        $this->need($request, 'ai.manage');
        abort_unless($id === 'block', 404);
        if ((int) config('starttoo.ai_monthly_budget', 0) <= 0) return back()->withErrors(['guard' => '월 예산(.env STARTTOO_AI_MONTHLY_BUDGET)을 먼저 정해주세요.']);
        $settings = app(Settings::class);
        $on = !$settings->bool('ai_budget_block');
        $settings->set('ai_budget_block', $on, (int) $this->admin($request)->id);
        $this->audit($request, '설정', '예산 초과 시 신규 생성 중단 ' . ($on ? '켜기' : '끄기'), 'AI 사용 한도', ['ai_budget_block' => [!$on, $on]]);

        return back()->with('status', '예산 초과 시 신규 생성 중단을 ' . ($on ? '켰어요' : '껐어요'));
    }

    // ================================================================ M-11 AI 작업 모니터

    private const JOB_TABS = ['전체' => null, '처리 중' => 'processing', '완료' => 'ready', '실패' => 'failed'];
    private const JOB_STATE = ['processing' => ['처리 중', 'blue'], 'ready' => ['완료', 'green'], 'failed' => ['실패', 'red']];

    public function jobs(Request $request): View
    {
        $db = $this->db();
        $tab = array_key_exists((string) $request->query('tab'), self::JOB_TABS) ? (string) $request->query('tab') : '전체';
        $page = $this->page($request);

        $q = $db->table('starttoo_fit_results as f')->leftJoin('starttoo_users as u', 'u.id', '=', 'f.user_id')
            ->where(fn ($w) => $w->where('f.cost_units', '>', 0)->orWhere('f.error', 'refunded'))->select('f.*', 'u.nickname', 'u.artist_status');
        if (self::JOB_TABS[$tab]) $q->where('f.status', self::JOB_TABS[$tab]);
        $total = (clone $q)->count();
        $rows = $q->orderByDesc('f.created_at')->offset(($page - 1) * self::PER_PAGE)->limit(self::PER_PAGE)->get();

        $selId = (string) $request->query('sel', '');
        $sel = $selId !== '' && \Illuminate\Support\Str::isUuid($selId)
            ? $db->table('starttoo_fit_results as f')->leftJoin('starttoo_users as u', 'u.id', '=', 'f.user_id')->where('f.id', $selId)->select('f.*', 'u.nickname', 'u.artist_status')->first()
            : null;
        $sel ??= $rows->first();

        $who = fn ($r) => ($r->nickname ?? '-') . ($r->artist_status === 'approved' ? ' (아티스트)' : '');
        $took = fn ($r) => $r->status === 'processing' ? '-' : number_format(abs(Carbon::parse($r->updated_at)->diffInSeconds(Carbon::parse($r->created_at))), 1) . '초';
        $code = fn ($r) => 'job_' . substr(str_replace('-', '', $r->id), 0, 6);
        $jobs = $rows->map(fn ($r) => [
            'id' => $code($r), 'kind' => self::TYPES[$r->type ?? 'blend'] ?? $r->type, 'who' => $who($r), 'whoHref' => Ui::memberUrl($r->user_id),
            'at' => Ui::date($r->created_at, 'm.d H:i:s'), 'took' => $took($r),
            'state' => self::JOB_STATE[$r->status][0] ?? $r->status, 'pill' => Ui::pill(self::JOB_STATE[$r->status][1] ?? 'gray'),
            'delivery' => $r->status === 'processing' ? '-' : '소켓 · 푸시', 'dPill' => Ui::pill($r->status === 'processing' ? 'gray' : 'green'),
            'rowStyle' => Ui::rowBg($sel && $r->id === $sel->id), 'href' => Ui::url(['sel' => $r->id]),
        ])->all();

        // 큐: 미디어 큐(chat-media) 대기 · 가장 오래 기다린 작업
        try {
            $queueConn = DB::connection(config('queue.connections.database.connection') ?: config('database.default'));
            $queueTable = config('queue.connections.database.table', 'jobs');
            $waiting = $queueConn->table($queueTable)->where('queue', 'chat-media')->whereNull('reserved_at')->count();
            $oldest = $queueConn->table($queueTable)->where('queue', 'chat-media')->whereNull('reserved_at')->min('created_at');
        } catch (\Throwable $e) {
            report($e);
            [$waiting, $oldest] = [0, null];
        }
        $delayed = $oldest && time() - (int) $oldest > 300;

        $todayQ = fn () => $db->table('starttoo_fit_results')->where('cost_units', '>', 0)->where('created_at', '>=', today());
        $todayAll = $todayQ()->count();
        $failedToday = $todayQ()->where('status', 'failed')->count();
        $blockedToday = $todayQ()->where('status', 'failed')->where('error', 'blocked')->count();
        $readyToday = $todayQ()->where('status', 'ready')->count();
        $avg = $todayQ()->where('status', 'ready')->selectRaw('AVG(TIMESTAMPDIFF(SECOND, created_at, updated_at)) as s')->value('s');
        $kpis = [
            ['label' => '대기 중', 'value' => number_format($waiting), 'sub' => $oldest ? '가장 오래 기다린 작업 ' . (int) ceil((time() - (int) $oldest) / 60) . '분' : '큐 비어 있음', 'subStyle' => Ui::sub($delayed ? 'down' : 'muted')],
            ['label' => '처리 중', 'value' => number_format($db->table('starttoo_fit_results')->where('status', 'processing')->count()), 'sub' => '미디어 큐(chat-media)', 'subStyle' => Ui::sub('muted')],
            ['label' => '오늘 실패', 'value' => number_format($failedToday),
                'sub' => ($todayAll ? '전체의 ' . number_format($failedToday / $todayAll * 100, 1) . '%' : '오늘 요청 없음') . ' · 안전 차단 ' . $blockedToday, 'subStyle' => Ui::sub($failedToday > 0 ? 'down' : 'muted')],
            ['label' => '오늘 완료', 'value' => number_format($readyToday), 'sub' => $avg === null ? '평균 생성 시간 -' : '평균 ' . number_format((float) $avg, 1) . '초', 'subStyle' => Ui::sub('muted')],
        ];

        $steps = [];
        $selView = null;
        if ($sel) {
            $isFail = $sel->status === 'failed';
            $done = $sel->status === 'ready';
            $end = Ui::date($sel->updated_at, 'H:i:s');
            $labels = [
                ['요청 접수 · 한도 확인', Ui::date($sel->created_at, 'H:i:s'), true],
                ['큐 등록 (chat-media)', Ui::date($sel->created_at, 'H:i:s'), true],
                ['AI 생성', $isFail ? '실패' : ($done ? $end : '진행 중'), $done || $isFail],
                ['결과 저장', $done ? $end : '-', $done],
                ['결과 알림 (소켓 · 푸시)', $done || $isFail ? $end : '-', $done || $isFail],
            ];
            foreach ($labels as $i => [$label, $t, $reached]) {
                $failHere = $isFail && $i === 2;
                $c = $failHere ? '#A3301A' : ($reached ? '#141413' : '#D9D4CB');
                $next = $labels[$i + 1][2] ?? false;
                $steps[] = ['label' => $label, 't' => $t, 'weight' => $reached || $failHere ? 700 : 500,
                    'dot' => 'width: 12px; height: 12px; border-radius: 50%; flex-shrink: 0; margin-top: 4px; background: ' . $c,
                    'line' => $i === count($labels) - 1 ? 'display: none' : 'flex: 1; width: 2px; background: ' . ($reached && $next && !$failHere ? '#141413' : '#E4E0D8')];
            }
            [$errCode, $errText] = self::ERRORS[$sel->error ?? ''] ?? [strtoupper((string) $sel->error), '처리하지 못했어요. 횟수는 차감되지 않았어요.'];
            $refunded = $done && $sel->error === 'refunded';
            $selView = [
                'id' => $code($sel), 'kind' => self::TYPES[$sel->type ?? 'blend'] ?? $sel->type, 'who' => $who($sel), 'whoHref' => Ui::memberUrl($sel->user_id),
                'state' => self::JOB_STATE[$sel->status][0] ?? $sel->status, 'pill' => Ui::pill(self::JOB_STATE[$sel->status][1] ?? 'gray'),
                'code' => $errCode, 'reason' => $errText, 'isFail' => $isFail,
                'source' => $sel->source === 'virtual' ? '가상 모델' : '내 사진',
                'quota' => $isFail ? '차감 안 됨 (실패 건은 자동 제외)' : ($refunded ? '복구됨 (오늘 남은 횟수 +1)' : ($sel->status === 'processing' ? '처리 중 · 1회 차감 예정' : '1회 차감')),
                'canRestore' => $done && (int) $sel->cost_units > 0 && Carbon::parse($sel->created_at)->gte(today()) && $this->can($request, 'ai.manage'),
                'restoreAction' => route('admin.ai-jobs.restore', $sel->id),
            ];
        }

        return view('admin.ai-jobs', [
            'kpis' => $kpis, 'jobs' => $jobs, 'steps' => $steps, 'sel' => $selView, 'model' => $this->model(),
            'queueLabel' => $delayed ? '미디어 큐 지연 · 대기 ' . number_format($waiting) . '건' : '미디어 큐 정상 · 대기 ' . number_format($waiting) . '건',
            'queueStyle' => $delayed ? 'background: #FFF1D6; color: #7A4F00' : 'background: #E3F4E9; color: #1D6B3A',
            'queueDot' => $delayed ? '#7A4F00' : '#1D6B3A',
            'tabs' => Ui::tabs(array_keys(self::JOB_TABS), $tab),
            'pages' => Ui::pages($page, false, (int) ceil($total / self::PER_PAGE)),
        ]);
    }

    /** 한도 복구: 오늘 완료된 작업의 차감을 되돌린다(결과가 잘못됐을 때 등). 실패 건은 원래 세지 않는다. */
    public function restoreQuota(Request $request, string $id): RedirectResponse
    {
        $this->need($request, 'ai.manage');
        abort_unless(\Illuminate\Support\Str::isUuid($id), 404);
        $r = $this->db()->table('starttoo_fit_results as f')->leftJoin('starttoo_users as u', 'u.id', '=', 'f.user_id')
            ->where('f.id', $id)->first(['f.id', 'f.user_id', 'f.status', 'f.cost_units', 'f.created_at', 'u.nickname']);
        abort_unless($r, 404);
        if ($r->status !== 'ready' || (int) $r->cost_units === 0 || Carbon::parse($r->created_at)->lt(today())) {
            return back()->withErrors(['restore' => '오늘 완료된 작업만 복구할 수 있어요(실패 건은 원래 횟수에서 빠져요).']);
        }
        // error = refunded: 차감만 되돌렸다는 표시(작업 목록에 계속 보이게, 기기에서 만든 결과와 구분)
        $this->db()->table('starttoo_fit_results')->where('id', $id)->where('cost_units', '>', 0)->update(['cost_units' => 0, 'error' => 'refunded']);
        $this->audit($request, '설정', 'AI 사용 횟수 복구', ($r->nickname ?? '-') . " (U-{$r->user_id}) · job_" . substr(str_replace('-', '', $id), 0, 6), ['cost_units' => [(int) $r->cost_units, 0]]);

        return back()->with('status', ($r->nickname ?? '회원') . '님의 오늘 사용 횟수를 1회 복구했어요');
    }

    // ================================================================ M-13 가상 모델 라이브러리

    private const TONE_LABEL = ['밝은 톤', '밝은 중간 톤', '중간 톤', '중간 갈색 톤', '어두운 톤'];
    private const TONE_BG = ['#E6CDB6', '#D8B596', '#C99E7C', '#A87B5B', '#8A5E43'];
    private const GENDER_LABEL = ['female' => '여성', 'male' => '남성'];

    public function virtualModels(Request $request): View
    {
        $db = $this->db();
        $parts = array_keys(Fittings::PARTS);
        $part = in_array($request->query('part'), $parts, true) ? $request->query('part') : $parts[0];
        $tone = ctype_digit((string) $request->query('tone')) && (int) $request->query('tone') < 5 ? (string) (int) $request->query('tone') : '전체';
        $gender = array_key_exists((string) $request->query('gender'), self::GENDER_LABEL) ? (string) $request->query('gender') : '전체';
        $urls = app(MediaUrls::class);

        $counts = $db->table('starttoo_fit_models')->groupBy('part')->selectRaw('part, COUNT(*) as n')->pluck('n', 'part');
        $q = $db->table('starttoo_fit_models')->where('part', $part);
        if ($tone !== '전체') $q->where('tone', (int) $tone);
        if ($gender !== '전체') $q->where('gender', $gender);
        $list = $q->orderBy('face')->orderBy('gender')->orderBy('tone')->orderBy('sort')->orderBy('id')->get();
        $uses = $list->isEmpty() ? collect() : $db->table('starttoo_fit_results')->whereIn('model_id', $list->pluck('id')->all())
            ->groupBy('model_id')->selectRaw('model_id, COUNT(*) as n')->pluck('n', 'model_id');

        $selId = (int) $request->query('sel', 0);
        $sel = $selId ? $db->table('starttoo_fit_models')->where('id', $selId)->first() : null;
        $sel ??= $list->first();
        $canManage = $this->can($request, 'ai.manage');

        $models = $list->map(function ($m) use ($sel, $uses, $urls, $canManage) {
            $on = (bool) $m->is_active;
            return [
                'code' => 'VM-' . $m->id, 'uses' => number_format((int) ($uses[$m->id] ?? 0)),
                'on' => $on, 'aria' => 'VM-' . $m->id . ' 노출', 'track' => Ui::switchTrack($on, !$canManage), 'canToggle' => $canManage,
                'action' => route('admin.virtual-models.toggle', $m->id),
                'lm' => $m->status !== 'ready' ? ($m->status === 'failed' ? '생성 실패' : '생성 대기') : ($m->skin_path ? '피부 영역 있음' : '피부 영역 없음'),
                'lmPill' => Ui::pill($m->status !== 'ready' ? ($m->status === 'failed' ? 'red' : 'blue') : ($m->skin_path ? 'green' : 'amber')),
                'image' => $m->status === 'ready' ? $urls->url($m->image_path, 500) : null,
                'face' => $m->face . ' · ' . (self::GENDER_LABEL[$m->gender] ?? $m->gender),
                'thumb' => 'position: relative; aspect-ratio: 3 / 4; overflow: hidden; background: linear-gradient(160deg, ' . (self::TONE_BG[(int) $m->tone] ?? '#C99E7C') . ', #3A3632)',
                'cardStyle' => 'border-radius: 12px; overflow: hidden; background: #FFFFFF; border: ' . ($sel && $m->id === $sel->id ? '2px solid #141413' : '1px solid #E4E0D8') . ($on ? '' : '; opacity: 0.6'),
                'href' => Ui::url(['sel' => $m->id]),
            ];
        })->all();

        $selView = null;
        if ($sel) {
            $on = (bool) $sel->is_active;
            $state = $sel->status !== 'ready' ? ($sel->status === 'failed' ? '생성 실패' : '생성 대기') : ($on ? '노출 중' : '숨김');
            $order = $db->table('starttoo_fit_models')->where(['part' => $sel->part, 'face' => $sel->face, 'gender' => $sel->gender])
                ->where('is_active', true)->where(fn ($w) => $w->where('sort', '<', $sel->sort)->orWhere(fn ($x) => $x->where('sort', $sel->sort)->where('id', '<', $sel->id)))->count() + 1;
            $selView = [
                'code' => 'VM-' . $sel->id,
                'partLabel' => $sel->part . ' · ' . $sel->face, 'toneLabel' => (self::TONE_LABEL[(int) $sel->tone] ?? $sel->tone) . ' · ' . (self::GENDER_LABEL[$sel->gender] ?? $sel->gender),
                'state' => $state, 'pill' => Ui::pill($sel->status !== 'ready' ? ($sel->status === 'failed' ? 'red' : 'blue') : ($on ? 'green' : 'gray')),
                'image' => $sel->status === 'ready' ? $urls->url($sel->image_path, 1200) : null,
                'skin' => $sel->skin_path ? '만들어짐 · 배치 화면에서 도안을 피부 안으로 자름' : '없음 · 처음 열 때 만들어요(starttoo:fit-model-skins 로 미리 만들기)',
                'source' => $sel->source_hash ? '운영자가 올린 사진' . ($sel->source_name ? ' · ' . basename((string) $sel->source_name) : '') : 'AI 생성(나노바나나)',
                'shot' => "{$sel->kind} · {$sel->light} · 체형 {$sel->build}",
                'order' => $on ? $order . '번째' : '노출 안 함',
                'big' => 'position: relative; display: block; aspect-ratio: 4 / 3; overflow: hidden; border-radius: 12px; background: linear-gradient(160deg, ' . (self::TONE_BG[(int) $sel->tone] ?? '#C99E7C') . ', #3A3632)',
                'canRedo' => $canManage && !$sel->source_hash && $sel->status !== 'pending',
                'redoAction' => route('admin.virtual-models.redo', $sel->id),
            ];
        }

        $partTabs = array_map(fn ($p) => ['label' => $p . ' ' . (int) ($counts[$p] ?? 0), 'style' => Ui::tab($p === $part), 'href' => Ui::url(['part' => $p, 'sel' => null])], $parts);
        return view('admin.virtual-models', [
            'models' => $models, 'knob' => Ui::KNOB, 'sel' => $selView,
            'parts' => $partTabs,
            'tones' => Ui::chips(['전체' => '전체'] + array_combine(['0', '1', '2', '3', '4'], self::TONE_LABEL), $tone, 'tone', ['sel']),
            'genders' => Ui::chips(['전체' => '전체', 'female' => '여성', 'male' => '남성'], $gender, 'gender', ['sel']),
            'countLabel' => $list->count() . '장 · 노출 ' . $list->where('is_active', true)->count() . '장',
        ]);
    }

    public function toggleModel(Request $request, int $id): RedirectResponse
    {
        $this->need($request, 'ai.manage');
        $m = $this->db()->table('starttoo_fit_models')->where('id', $id)->first();
        abort_unless($m, 404);
        $on = !$m->is_active;
        $this->db()->table('starttoo_fit_models')->where('id', $id)->update(['is_active' => $on, 'updated_at' => now()]);
        $this->audit($request, '콘텐츠', $on ? '가상 모델 노출' : '가상 모델 숨김', "VM-{$id} · {$m->part} · {$m->face}", ['is_active' => [(bool) $m->is_active, $on]]);

        return back()->with('status', "VM-{$id} " . ($on ? '노출했어요' : '숨겼어요'));
    }

    /** 사진 교체: AI 로 만든 모델 사진을 같은 조건으로 다시 만든다(starttoo:fit-models --redo 와 같은 처리) */
    public function regenerateModel(Request $request, int $id): RedirectResponse
    {
        $this->need($request, 'ai.manage');
        $m = $this->db()->table('starttoo_fit_models')->where('id', $id)->first();
        abort_unless($m, 404);
        if ($m->source_hash) return back()->withErrors(['redo' => '운영자가 올린 사진은 여기서 바꿀 수 없어요(서버 명령 starttoo:fit-models-import).']);
        if ($m->status === 'pending') return back()->withErrors(['redo' => '이미 다시 만드는 중이에요.']);
        $this->db()->table('starttoo_fit_models')->where('id', $id)->update(['status' => 'pending', 'updated_at' => now()]);
        app(Fittings::class)->dispatchModel($id);
        $this->audit($request, '콘텐츠', '가상 모델 사진 다시 만들기', "VM-{$id} · {$m->part} · {$m->face}");

        return back()->with('status', "VM-{$id} 사진을 다시 만들고 있어요(1~2분)");
    }
}
