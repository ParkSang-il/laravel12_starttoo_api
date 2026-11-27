<?php

namespace App\Http\Controllers\Advertiser;

use App\Http\Controllers\Controller;
use App\Services\Starttoo\AdSales;
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
        return view('advertiser.products', [
            'slots' => $this->sales()->openSlots(),
            'daily' => $this->sales()->slotDaily(),
            'stats' => $this->sales()->siteStats(),
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
            'files.*' => ['file', 'max:20480', 'mimes:jpg,jpeg,png,webp,gif,mp4'],
            'memo' => ['nullable', 'string', 'max:2000'],
            'privacy' => ['accepted'],
            'website' => ['prohibited'], // 숨은 칸(봇 방지)
        ], [
            'slots.required' => '원하는 자리를 하나 이상 골라주세요.',
            'privacy.accepted' => '개인정보 수집 · 이용에 동의해야 문의를 보낼 수 있어요.',
            'phone.regex' => '연락처를 숫자로 적어주세요.',
            'landing_url.url' => '연결 주소는 https:// 로 시작하는 주소로 적어주세요.',
            'files.*.max' => '파일은 20MB까지 올릴 수 있어요.',
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

    public function quote(Request $request, string $token): View
    {
        $c = $this->sales()->byQuoteToken($token);
        abort_unless($c, 404);
        $owner = $c->advertiser_id ? app(\App\Services\Starttoo\Advertisers::class)->find((int) $c->advertiser_id) : null;
        $me = $request->attributes->get('advertiser');
        return view('advertiser.quote', [
            'c' => $c, 'stage' => $c->stage, 'expired' => $c->expired,
            'lines' => $this->sales()->quoteLines($c),
            'expected' => $this->sales()->expected($c),
            'bank' => config('advertiser.bank'),
            'company' => config('advertiser.company'),
            // 견적 · 성과 보고를 한 곳에서: 계정 만들기(비밀번호 없음) · 로그인 · 내 광고
            'account' => match (true) {
                !$owner => null,
                $me && (int) $me->id === (int) $owner->id => 'mine',
                !$owner->password => 'create',
                default => 'login',
            },
            'ownerEmail' => $owner?->email,
            'taxPrefill' => $owner,
        ]);
    }

    public function acceptQuote(string $token): RedirectResponse
    {
        $c = $this->sales()->byQuoteToken($token);
        abort_unless($c, 404);
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

    public function report(Request $request, string $token): View
    {
        $c = $this->sales()->byReportToken($token);
        abort_unless($c, 404);
        $metric = in_array($request->query('metric'), ['imp', 'clk', 'ctr'], true) ? $request->query('metric') : 'imp';
        $r = $this->sales()->report($c);
        $final = $c->status === 'ended' || $c->ends_at->lt(today());

        $series = [];
        foreach ($r['byDay'] as $d => [$imp, $clk]) {
            $series[$d] = match ($metric) { 'clk' => $clk, 'ctr' => $imp ? $clk / $imp * 100 : 0, default => $imp };
        }
        $passed = array_slice(array_keys($series), 0, $r['shown']);
        $max = max(1, ...array_map(fn ($d) => $series[$d], $passed ?: [array_key_first($series)]));
        $lastShown = $passed ? end($passed) : null;
        $fmt = fn ($v) => $metric === 'ctr' ? number_format($v, 1) . '%' : AdSales::short((int) $v);
        $bars = [];
        foreach ($series as $d => $v) {
            $has = in_array($d, $passed, true);
            $bars[] = [
                'd' => Carbon::parse($d)->format('d'),
                'v' => $has ? $fmt($v) : '',
                'style' => 'display: block; width: 100%; border-radius: 6px 6px 2px 2px; height: ' . ($has ? max(4, round($v / $max * 150)) : 4) . 'px; background: '
                    . (!$has ? '#ECEAE5' : ($d === $lastShown ? '#FF5A36' : '#141413')),
            ];
        }
        $label = ['imp' => '노출', 'clk' => '클릭', 'ctr' => '클릭률'][$metric];
        $avgCtr = $this->sales()->avgCtr();
        $goalPct = $r['goal'] ? (int) min(100, floor($r['totImp'] / $r['goal'] * 100)) : 0;

        return view('advertiser.report', [
            'c' => $c, 'final' => $final, 'metric' => $metric, 'metricLabel' => $label, 'bars' => $bars,
            'chartAria' => '일별 ' . $label . ', ' . implode(', ', array_map(fn ($d) => Carbon::parse($d)->format('m.d') . ' ' . $fmt($series[$d]), $passed)),
            'kpis' => [
                ['label' => '노출', 'v' => number_format($r['totImp']), 'sub' => '하루 평균 ' . number_format($r['shown'] ? $r['totImp'] / $r['shown'] : 0)],
                ['label' => '클릭', 'v' => number_format($r['totClk']), 'sub' => '하루 평균 ' . number_format($r['shown'] ? $r['totClk'] / $r['shown'] : 0)],
                ['label' => '클릭률', 'v' => ($r['totImp'] ? number_format($r['totClk'] / $r['totImp'] * 100, 2) : '0') . '%', 'sub' => $avgCtr !== null ? '앱 광고 평균 ' . number_format($avgCtr, 1) . '%' : ''],
                ['label' => '보장 노출 달성', 'v' => $goalPct . '%', 'sub' => number_format($r['totImp']) . ' / ' . number_format($r['goal']) . '회', 'bar' => $goalPct],
            ],
            'placements' => $r['placements'],
            'creatives' => $r['perAd'],
            'shown' => $r['shown'], 'totalDays' => count($r['days']),
            'totImp' => $r['totImp'], 'totClk' => $r['totClk'],
            'slots' => AdSales::SLOTS,
        ]);
    }
}
