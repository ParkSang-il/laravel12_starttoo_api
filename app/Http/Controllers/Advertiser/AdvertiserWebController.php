<?php

namespace App\Http\Controllers\Advertiser;

use App\Http\Controllers\Controller;
use App\Http\Middleware\AdvertiserWeb;
use App\Services\Starttoo\AdSales;
use App\Services\Starttoo\Advertisers;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Carbon;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

/**
 * 외부 광고주 웹(디자인 AW-01 ~ AW-03). 스타투 DB 는 advertiser.web 미들웨어가 고른다.
 *  상품 소개 GET /ads/products
 *  AW-01     GET/POST /ads/inquiry       공개 문의
 *  AW-02     GET /ads/q/{token}          견적서(비밀 링크) · 수락 · 수정 요청 · 입금 알림
 *  AW-03     GET /ads/r/{token}          성과 보고(비밀 링크)
 */
class AdvertiserWebController extends Controller
{
    private function sales(): AdSales { return app(AdSales::class); }

    // ── 상품 소개 ──────────────────────────────

    public function products(): View
    {
        // 실제 앱 광고 카드 캡처(public/advertiser/screens/{slot}.jpg|png|webp)가 있으면 모형 화면 안의 광고 카드를 그 캡처로 보여 준다
        $shots = [];
        foreach (array_keys(AdSales::SLOTS) as $slot) {
            $shots[$slot] = null;
            foreach (['jpg', 'png', 'webp'] as $ext) {
                $file = "advertiser/screens/$slot.$ext";
                if (is_file(public_path($file))) {
                    $shots[$slot] = asset($file) . '?v=' . filemtime(public_path($file));
                    break;
                }
            }
        }
        return view('advertiser.products', [
            'slots' => $this->sales()->openSlots(),
            'daily' => $this->sales()->slotDaily(),
            'stats' => $this->sales()->siteStats(),
            'shots' => $shots,
        ]);
    }

    // ── AW-01 ──────────────────────────────────

    public function inquiry(Request $request): View
    {
        return view('advertiser.inquiry', [
            'stats' => $this->sales()->siteStats(),
            'slots' => $this->sales()->openSlots(),
            'durations' => config('advertiser.durations'),
            'budgets' => config('advertiser.budgets'),
            'industries' => config('advertiser.industries'),
            'retention' => config('advertiser.retention'),
            'me' => $request->attributes->get('advertiser'),
        ]);
    }

    public function storeInquiry(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'company' => ['required', 'string', 'max:100'],
            'contact_name' => ['required', 'string', 'max:60'],
            'email' => ['required', 'email:rfc', 'max:150'],
            'phone' => ['required', 'string', 'max:30', 'regex:/^[0-9+\-\s()]{8,}$/'],
            'industry' => ['nullable', Rule::in(config('advertiser.industries'))],
            'slots' => ['required', 'array', 'min:1'],
            'slots.*' => [Rule::in(array_keys($this->sales()->openSlots()))],
            'wish_start' => ['nullable', 'date', 'after_or_equal:today'],
            'duration' => ['nullable', Rule::in(config('advertiser.durations'))],
            'budget' => ['nullable', Rule::in(config('advertiser.budgets'))],
            'landing_url' => ['nullable', 'url:https', 'max:500'],
            'files' => ['nullable', 'array', 'max:5'],
            // 영상(MP4) 50MB · 이미지 20MB
            'files.*' => ['file', 'max:51200', 'mimes:jpg,jpeg,png,webp,gif,mp4',
                function (string $attr, $file, \Closure $fail) {
                    if ($file instanceof \Illuminate\Http\UploadedFile && $file->getClientOriginalExtension() !== ''
                        && strtolower($file->getClientOriginalExtension()) !== 'mp4' && $file->getSize() > 20 * 1024 * 1024) {
                        $fail('이미지는 20MB까지 올릴 수 있어요.');
                    }
                }],
            'memo' => ['nullable', 'string', 'max:2000'],
            'privacy' => ['accepted'],
            'website' => ['prohibited'], // 숨은 칸(봇 방지)
        ], [
            'slots.required' => '원하는 자리를 하나 이상 골라주세요.',
            'privacy.accepted' => '개인정보 수집 · 이용에 동의해야 문의를 보낼 수 있어요.',
            'phone.regex' => '연락처를 숫자로 적어주세요.',
            'landing_url.url' => '연결 주소는 https:// 로 시작하는 주소로 적어주세요.',
            'files.*.max' => '영상은 50MB, 이미지는 20MB까지 올릴 수 있어요.',
            // PHP 가 파일을 받기 전에 거절(서버 업로드 한도 초과 · 전송 중 끊김)
            'files.*.uploaded' => '파일을 올리지 못했어요. 영상 50MB · 이미지 20MB 이하인지 확인하고 다시 보내주세요. 더 큰 영상은 나중에 메일로 보내도 돼요.',
            'files.*.file' => '파일을 올리지 못했어요. 다시 골라 보내주세요.',
            'files.*.mimes' => 'JPG · PNG · WEBP · GIF · MP4 파일만 올릴 수 있어요.',
            'wish_start.after_or_equal' => '시작일은 오늘 이후로 골라주세요.',
        ], ['company' => '회사명', 'contact_name' => '담당자', 'email' => '이메일', 'phone' => '연락처', 'landing_url' => '연결 주소']);

        $me = $request->attributes->get('advertiser');
        $inq = $this->sales()->createInquiry($data, $request->file('files', []), $request->ip(), $me?->id ? (int) $me->id : null);

        return redirect()->route('advertiser.inquiry.done', $inq->code)
            ->with('advertiser.inquiry_code', $inq->code); // 완료 화면은 방금 보낸 사람만
    }

    public function inquiryDone(Request $request, string $code): View|RedirectResponse
    {
        if (session('advertiser.inquiry_code') !== $code) return redirect()->route('advertiser.inquiry');
        $inq = $this->sales()->inquiryByCode($code);
        abort_unless($inq, 404);
        session()->reflash();
        return view('advertiser.inquiry-done', ['inq' => $inq, 'slotNames' => $this->sales()->slotNames($inq->slots),
            'me' => $request->attributes->get('advertiser')]);
    }

    // ── AW-02 ──────────────────────────────────

    /**
     * 비밀 링크(견적서 q · 성과 보고 r) 앞 단계. 메일 링크의 비밀 토큰 = 받은 이메일 주인 인증.
     *  계정(비밀번호) 없음 → 계정 만들기 화면 → 만들면 로그인되고 그 화면이 열린다
     *  계정 있음 · 로그인 안 함 → 로그인 화면(로그인하면 이 링크로 돌아옴)
     *  계약 주인으로 로그인 → null(통과)
     */
    private function gate(Request $request, object $c, string $route, string $token): View|RedirectResponse|null
    {
        if (AdvertiserWeb::ownsContract($request, $c)) return null;
        $owner = app(Advertisers::class)->find((int) $c->advertiser_id);
        abort_unless($owner, 404);
        $subject = $route === 'advertiser.report' ? '성과 보고' : '견적서';
        if ($request->attributes->get('advertiser')) {
            abort(403, "다른 광고주 계정으로 로그인돼 있어요. 로그아웃한 뒤 {$subject} 메일을 받은 이메일로 로그인해주세요.");
        }
        if (!$owner->password) {
            return view('advertiser.link-account', ['action' => route($route . '.account', $token),
                'email' => $owner->email, 'company' => $c->company, 'subject' => $subject]);
        }
        $request->session()->put('url.intended', route($route, $token));
        return redirect()->route('advertiser.login')->withInput(['email' => $owner->email])
            ->with('status', "광고주 계정으로 로그인하면 {$subject}를 볼 수 있어요.");
    }

    public function quote(Request $request, string $token): View|RedirectResponse
    {
        $c = $this->sales()->byQuoteToken($token);
        abort_unless($c && $c->advertiser_id, 404);
        if ($gate = $this->gate($request, $c, 'advertiser.quote', $token)) return $gate;
        $owner = app(Advertisers::class)->find((int) $c->advertiser_id);
        return view('advertiser.quote', [
            'c' => $c, 'stage' => $c->stage, 'expired' => $c->expired,
            'lines' => $this->sales()->quoteLines($c),
            'expected' => $this->sales()->expected($c),
            'bank' => config('advertiser.bank'),
            'company' => config('advertiser.company'),
            'taxPrefill' => $owner,
        ]);
    }

    /** 견적 주인으로 로그인하지 않은 세션의 수락 · 수정 요청 · 입금 알림은 견적서 주소(계정 만들기 · 로그인)로 돌려보낸다 */
    private function locked(Request $request, object $c, string $token): ?RedirectResponse
    {
        return AdvertiserWeb::ownsContract($request, $c) ? null : redirect()->route('advertiser.quote', $token);
    }

    public function acceptQuote(Request $request, string $token): RedirectResponse
    {
        $c = $this->sales()->byQuoteToken($token);
        abort_unless($c, 404);
        if ($r = $this->locked($request, $c, $token)) return $r;
        try {
            $this->sales()->accept($c);
        } catch (\InvalidArgumentException $e) {
            return back()->withErrors(['quote' => $e->getMessage()]);
        }
        return back()->with('status', '견적을 수락했어요. 아래 계좌로 입금해주세요.');
    }

    public function requestChange(Request $request, string $token): RedirectResponse
    {
        $c = $this->sales()->byQuoteToken($token);
        abort_unless($c, 404);
        if ($r = $this->locked($request, $c, $token)) return $r;
        $data = $request->validate(['change_request' => ['required', 'string', 'max:2000']], [
            'change_request.required' => '바꾸고 싶은 내용을 적어주세요.',
        ]);
        try {
            $this->sales()->requestChange($c, $data['change_request']);
        } catch (\InvalidArgumentException $e) {
            return back()->withErrors(['change_request' => $e->getMessage()]);
        }
        return back()->with('status', '담당자에게 수정 요청을 보냈어요. 새 견적을 메일로 보내드릴게요.');
    }

    public function reportPayment(Request $request, string $token): RedirectResponse
    {
        $c = $this->sales()->byQuoteToken($token);
        abort_unless($c, 404);
        if ($r = $this->locked($request, $c, $token)) return $r;
        $data = $request->validate([
            'biz_no' => ['required', 'regex:/^\d{3}-?\d{2}-?\d{5}$/'],
            'biz_name' => ['required', 'string', 'max:120'],
            'tax_email' => ['required', 'email:rfc', 'max:150'],
            'biz_cert' => ['nullable', 'file', 'max:10240', 'mimes:pdf,jpg,jpeg,png'],
        ], ['biz_no.regex' => '사업자등록번호 10자리를 확인해주세요.'], ['biz_name' => '상호 · 대표자', 'tax_email' => '받을 이메일']);
        try {
            $this->sales()->reportPayment($c, $data, $request->file('biz_cert'));
        } catch (\InvalidArgumentException $e) {
            return back()->withErrors(['quote' => $e->getMessage()]);
        }
        return back()->with('status', '입금 알림을 보냈어요. 확인되면 계약 완료 메일을 드려요.');
    }

    // ── AW-03 ──────────────────────────────────

    public function report(Request $request, string $token): View|RedirectResponse
    {
        $c = $this->sales()->byReportToken($token);
        abort_unless($c && $c->advertiser_id, 404);
        if ($gate = $this->gate($request, $c, 'advertiser.report', $token)) return $gate;
        $metric = in_array($request->query('metric'), ['imp', 'clk', 'ctr'], true) ? $request->query('metric') : 'imp';
        $r = $this->sales()->report($c);
        $final = $c->status === 'ended' || $c->ends_at->lt(today());

        $series = [];
        foreach ($r['byDay'] as $d => [$imp, $clk]) {
            $series[$d] = match ($metric) { 'clk' => $clk, 'ctr' => $imp ? $clk / $imp * 100 : 0, default => $imp };
        }
        $passed = array_slice(array_keys($series), 0, $r['shown']);
        $max = max(1, ...array_map(fn ($d) => $series[$d], $passed ?: [array_key_first($series)]));
        $live = $r['liveDay'];
        $lastShown = $passed ? end($passed) : null;
        $fmt = fn ($v) => $metric === 'ctr' ? number_format($v, 1) . '%' : AdSales::short((int) $v);
        $bars = [];
        foreach ($series as $d => $v) {
            $has = in_array($d, $passed, true);
            $bars[] = [
                'd' => $d === $live ? '오늘' : Carbon::parse($d)->format('d'),
                'v' => $has ? $fmt($v) : '',
                // 지난 날 = 검정(마지막 날 주홍) · 오늘 집계 중 = 연한 주홍 · 아직 안 온 날 = 회색
                'style' => 'display: block; width: 100%; border-radius: 6px 6px 2px 2px; height: ' . ($has ? max(4, round($v / $max * 150)) : 4) . 'px; background: '
                    . (!$has ? '#ECEAE5' : ($d === $live ? '#FFAD9B' : ($d === $lastShown ? '#FF5A36' : '#141413'))),
            ];
        }
        // 하루 평균은 다 끝난 날 기준(오늘 집계 중인 날은 빼고, 첫날이면 오늘 값)
        $fullDays = $live && $r['shown'] > 1 ? $r['shown'] - 1 : $r['shown'];
        $todayImp = $live ? $r['byDay'][$live][0] : 0;
        $todayClk = $live ? $r['byDay'][$live][1] : 0;
        $avgImp = $fullDays ? ($live && $r['shown'] > 1 ? $r['totImp'] - $todayImp : $r['totImp']) / $fullDays : 0;
        $avgClk = $fullDays ? ($live && $r['shown'] > 1 ? $r['totClk'] - $todayClk : $r['totClk']) / $fullDays : 0;
        $liveSub = $live ? ' · 오늘 집계 중 포함' : '';
        $label = ['imp' => '노출', 'clk' => '클릭', 'ctr' => '클릭률'][$metric];
        $avgCtr = $this->sales()->avgCtr();
        $goalPct = $r['goal'] ? (int) min(100, floor($r['totImp'] / $r['goal'] * 100)) : 0;

        return view('advertiser.report', [
            'c' => $c, 'final' => $final, 'metric' => $metric, 'metricLabel' => $label, 'bars' => $bars,
            'chartAria' => '일별 ' . $label . ', ' . implode(', ', array_map(fn ($d) => Carbon::parse($d)->format('m.d') . ' ' . $fmt($series[$d]), $passed)),
            'kpis' => [
                ['label' => '노출', 'v' => number_format($r['totImp']), 'sub' => '하루 평균 ' . number_format($avgImp) . $liveSub],
                ['label' => '클릭', 'v' => number_format($r['totClk']), 'sub' => '하루 평균 ' . number_format($avgClk) . $liveSub],
                ['label' => '클릭률', 'v' => ($r['totImp'] ? number_format($r['totClk'] / $r['totImp'] * 100, 2) : '0') . '%', 'sub' => ($avgCtr !== null ? '앱 광고 평균 ' . number_format($avgCtr, 1) . '%' : '') . ($live ? ($avgCtr !== null ? ' · ' : '') . '오늘 집계 중 포함' : '')],
                ['label' => '보장 노출 달성', 'v' => $goalPct . '%', 'sub' => number_format($r['totImp']) . ' / ' . number_format($r['goal']) . '회', 'bar' => $goalPct],
            ],
            'placements' => $r['placements'],
            'creatives' => $r['perAd'],
            'shown' => $r['shown'], 'totalDays' => count($r['days']), 'liveDay' => $live,
            'totImp' => $r['totImp'], 'totClk' => $r['totClk'],
            'slots' => AdSales::SLOTS,
        ]);
    }
}
