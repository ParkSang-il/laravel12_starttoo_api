<?php

namespace App\Http\Controllers\Admin;

use App\Services\Starttoo\AdSales;
use App\Services\Starttoo\Advertisers;
use App\Support\AdminUi as Ui;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Storage;
use Illuminate\View\View;

/**
 * 외부 광고 영업(docs/ads.md 「외부 광고」). 광고주 웹(/ads)의 문의 → 견적(CPM) → 입금 확인 → 광고 만들기 → 성과 보고.
 * 탭: 문의 · 계약 · 광고주 · 단가표. 조회는 모든 역할, 처리는 ads.manage.
 * sel=i{문의 id} · c{계약 id} · new(문의 없이 새 견적)
 */
class AdminAdSalesController extends AdminBaseController
{
    private const TABS = ['문의', '계약', '광고주', '단가표'];
    private const INQUIRY_FILTERS = ['처리 전' => ['new'], '견적 보냄' => ['quoted'], '계약' => ['contracted'], '전체' => null];
    private const CONTRACT_FILTERS = ['할 일' => ['payment_reported', 'awaiting_payment', 'contracted'], '견적 보냄' => ['quote_sent'],
        '노출 중' => ['live'], '끝남' => ['ended', 'cancelled'], '전체' => null];

    private function sales(): AdSales { return app(AdSales::class); }

    public function index(Request $request): View
    {
        $tab = in_array($request->query('tab'), self::TABS, true) ? $request->query('tab') : '문의';
        $page = $this->page($request);
        $sel = (string) $request->query('sel', '');
        $data = ['tab' => $tab, 'tabs' => Ui::tabs(self::TABS, $tab, 'tab', ['sel', 'page', 'f', 'q']),
            'canManage' => $this->can($request, 'ads.manage'), 'rates' => $this->sales()->rates(), 'slotDaily' => $this->sales()->slotDaily(),
            'list' => [], 'filters' => [], 'pages' => null, 'inquiry' => null, 'contract' => null, 'newQuote' => null];

        if ($tab === '문의') {
            $f = array_key_exists((string) $request->query('f'), self::INQUIRY_FILTERS) ? (string) $request->query('f') : '처리 전';
            $q = $this->db()->table('starttoo_ad_inquiries');
            if (self::INQUIRY_FILTERS[$f]) $q->whereIn('status', self::INQUIRY_FILTERS[$f]);
            $total = (clone $q)->count();
            $rows = $q->orderByDesc('id')->offset(($page - 1) * self::PER_PAGE)->limit(self::PER_PAGE)->get();
            $data['filters'] = Ui::chips(array_keys(self::INQUIRY_FILTERS), $f, 'f', ['sel', 'page']);
            $data['list'] = $rows->map(function ($r) use ($sel) {
                $r = $this->sales()->hydrateInquiry($r);
                $st = AdSales::INQUIRY_STATUS[$r->status] ?? [$r->status, 'gray'];
                return ['code' => $r->code, 'company' => $r->company, 'who' => $r->contact_name . ' · ' . $r->email,
                    'slots' => implode(' · ', $this->sales()->slotNames($r->slots)), 'budget' => $r->budget ?? '-',
                    'when' => Ui::ago($r->created_at), 'state' => $st[0], 'pill' => Ui::pill($st[1]),
                    'href' => Ui::url(['sel' => 'i' . $r->id]), 'rowStyle' => Ui::rowBg($sel === 'i' . $r->id)];
            })->all();
            $data['pages'] = Ui::pages($page, false, (int) ceil($total / self::PER_PAGE));
        } elseif ($tab === '계약') {
            $f = array_key_exists((string) $request->query('f'), self::CONTRACT_FILTERS) ? (string) $request->query('f') : '할 일';
            $q = $this->db()->table('starttoo_ad_contracts');
            if (self::CONTRACT_FILTERS[$f]) $q->whereIn('status', self::CONTRACT_FILTERS[$f]);
            $total = (clone $q)->count();
            // 입금 확인이 필요한 계약을 맨 위로
            $rows = $q->orderByRaw("CASE status WHEN 'payment_reported' THEN 0 WHEN 'contracted' THEN 1 ELSE 2 END")->orderByDesc('updated_at')
                ->offset(($page - 1) * self::PER_PAGE)->limit(self::PER_PAGE)->get();
            $data['filters'] = Ui::chips(array_keys(self::CONTRACT_FILTERS), $f, 'f', ['sel', 'page']);
            $data['list'] = $rows->map(function ($r) use ($sel) {
                $c = $this->sales()->hydrate($r);
                $st = $c->expired ? ['기한 지남', 'gray'] : AdSales::STATUS[$c->status];
                return ['code' => $c->quote_no, 'company' => $c->company, 'who' => $c->title,
                    'slots' => implode(' · ', $this->sales()->slotNames(array_column($c->lines, 'slot'))),
                    'budget' => Ui::won($c->total), 'when' => $c->starts_at->format('m.d') . ' ~ ' . $c->ends_at->format('m.d'),
                    'state' => $st[0] . ($c->change_requested_at && $c->status === 'quote_sent' ? ' · 수정 요청' : ''),
                    'pill' => Ui::pill($c->change_requested_at && $c->status === 'quote_sent' ? 'red' : $st[1]),
                    'href' => Ui::url(['sel' => 'c' . $c->id]), 'rowStyle' => Ui::rowBg($sel === 'c' . $c->id)];
            })->all();
            $data['pages'] = Ui::pages($page, false, (int) ceil($total / self::PER_PAGE));
        } elseif ($tab === '광고주') {
            $search = trim((string) $request->query('q', ''));
            [$rows, $total] = app(Advertisers::class)->page($page, self::PER_PAGE, $search);
            $data['search'] = $search;
            $data['advertisers'] = array_map(fn ($a) => [
                'id' => $a->id, 'company' => $a->company, 'email' => $a->email, 'contact' => $a->contact_name ?: '-',
                'account' => $a->password ? '계정 있음' : '비밀번호 없음', 'accountPill' => Ui::pill($a->password ? 'green' : 'gray'),
                'login' => $a->last_login_at ? Ui::ago($a->last_login_at) : '-',
                'contracts' => $a->contracts, 'paid' => $a->paid_total ? Ui::won($a->paid_total) : '-',
                'linkAction' => route('admin.ad-sales.advertiser.link', $a->id),
            ], $rows);
            $data['pages'] = Ui::pages($page, false, (int) ceil($total / self::PER_PAGE));
        }

        if (str_starts_with($sel, 'i') && ($inq = $this->sales()->inquiry((int) substr($sel, 1)))) {
            $data['inquiry'] = $inq;
            $data['quoteDefaults'] = $this->quoteDefaults($inq);
            $data['inquiryContracts'] = array_map(fn ($r) => $this->sales()->hydrate($r),
                $this->db()->table('starttoo_ad_contracts')->where('inquiry_id', $inq->id)->orderByDesc('id')->get()->all());
        } elseif (str_starts_with($sel, 'c') && ($c = $this->sales()->contract((int) substr($sel, 1)))) {
            $data['contract'] = $c;
            $data['contractLines'] = $this->sales()->quoteLines($c);
            $data['contractAds'] = array_map(function ($a) {
                return ['id' => $a->id, 'slot' => AdSales::SLOTS[$a->slot]['name'] ?? $a->slot, 'title' => $a->title,
                    'imp' => (int) $a->impressions, 'cap' => (int) $a->impression_cap, 'clicks' => (int) $a->clicks,
                    'active' => (bool) $a->active, 'href' => route('admin.ads', ['sel' => $a->id])];
            }, $this->sales()->contractAds((int) $c->id));
            // 줄마다 아직 광고에 나누지 않은 보장 노출
            $data['lineLeft'] = array_map(function ($l) use ($c) {
                $used = (int) $this->db()->table('starttoo_ads')->where('contract_id', $c->id)->where('slot', $l['slot'])->sum('impression_cap');
                return max(0, (int) $l['imps'] - $used);
            }, $c->lines);
            $data['quoteUrl'] = $this->sales()->quoteUrl($c);
            $data['reportUrl'] = $c->report_token ? AdSales::link('advertiser.report', $c->report_token) : null;
            $data['owner'] = $c->advertiser_id ? app(Advertisers::class)->find((int) $c->advertiser_id) : null;
        } elseif ($sel === 'new') {
            $data['newQuote'] = true;
            $data['quoteDefaults'] = $this->quoteDefaults(null);
        }
        return view('admin.ad-sales', $data);
    }

    /** 견적 칸 기본값: 문의한 자리마다 최소 보장 노출 · 단가표 CPM, 기간은 문의한 기간 */
    private function quoteDefaults(?object $inq): array
    {
        $rates = $this->sales()->rates();
        $start = $inq?->wish_start && Carbon::parse($inq->wish_start)->gte(today()) ? Carbon::parse($inq->wish_start) : today()->addDays(7);
        $days = match ($inq?->duration) { '1주' => 7, '4주' => 28, default => 14 };
        $lines = array_map(fn ($slot) => ['slot' => $slot, 'imps' => $rates[$slot]['min'] ?? 0, 'cpm' => $rates[$slot]['cpm'] ?? 0],
            array_values(array_filter($inq?->slots ?? [], fn ($s) => isset($rates[$s]))));
        return [
            'title' => $inq ? $inq->company . ' 광고' : '',
            'company' => $inq?->company ?? '', 'contact_name' => $inq?->contact_name ?? '', 'email' => $inq?->email ?? '',
            'landing_url' => $inq?->landing_url ?? '',
            'lines' => $lines ?: [['slot' => array_key_first($rates), 'imps' => reset($rates)['min'], 'cpm' => reset($rates)['cpm']]],
            'valid_until' => today()->addDays((int) config('advertiser.quote_valid_days', 7))->toDateString(),
            'starts_at' => $start->toDateString(), 'ends_at' => $start->copy()->addDays($days - 1)->toDateString(),
            'terms' => implode("\n", [
                '입금 기한: 견적 유효 기한까지 · 입금 확인 후 계약 완료 메일을 보내드려요.',
                '보장 노출을 채우면 그날 광고가 끝나요. 노출 · 클릭은 앱 광고 기록 기준이에요.',
                '소재는 노출 전 심사해요. 앱 광고 정책에 맞지 않으면 수정을 요청할 수 있어요.',
            ]),
        ];
    }

    // ── 문의 ───────────────────────────────────

    public function quote(Request $request, ?int $id = null): RedirectResponse
    {
        $this->need($request, 'ads.manage');
        $data = $request->validate([
            'title' => ['required', 'string', 'max:120'],
            'company' => ['required', 'string', 'max:100'],
            'contact_name' => ['required', 'string', 'max:60'],
            'email' => ['required', 'email:rfc', 'max:150'],
            'manager' => ['nullable', 'string', 'max:60'],
            'landing_url' => ['nullable', 'string', 'max:500'],
            'lines' => ['required', 'array', 'max:' . AdSales::MAX_LINES],
            'lines.*.slot' => ['nullable', 'string'],
            'lines.*.imps' => ['nullable', 'integer', 'min:0', 'max:100000000'],
            'lines.*.cpm' => ['nullable', 'integer', 'min:0', 'max:1000000'],
            'discount_label' => ['nullable', 'string', 'max:60'],
            'discount' => ['nullable', 'integer', 'min:0', 'max:1000000000'],
            'valid_until' => ['required', 'date', 'after_or_equal:today'],
            'starts_at' => ['required', 'date'],
            'ends_at' => ['required', 'date', 'after_or_equal:starts_at'],
            'terms' => ['nullable', 'string', 'max:3000'],
            'memo' => ['nullable', 'string', 'max:2000'],
        ], ['ends_at.after_or_equal' => '종료일이 시작일보다 빨라요.', 'valid_until.after_or_equal' => '유효 기한은 오늘 이후로 정해주세요.'],
            ['title' => '견적 제목', 'company' => '회사명', 'contact_name' => '담당자', 'email' => '이메일']);
        try {
            $c = $this->sales()->createQuote($id, $data, (int) $this->admin($request)->id);
        } catch (\InvalidArgumentException $e) {
            return back()->withErrors(['quote' => $e->getMessage()])->withInput();
        }
        $this->audit($request, '설정', '광고 견적 발송', "{$c->quote_no} · {$c->company} · " . Ui::won($c->total));
        Ui::forgetBadges();
        return redirect()->route('admin.ad-sales', ['tab' => '계약', 'f' => '견적 보냄', 'sel' => 'c' . $c->id])
            ->with('status', $c->mailed ? "견적 {$c->quote_no}를 {$c->email} 로 보냈어요" : "견적 {$c->quote_no}를 만들었지만 메일을 못 보냈어요 · 견적서 링크를 복사해 직접 보내주세요");
    }

    public function reject(Request $request, int $id): RedirectResponse
    {
        $this->need($request, 'ads.manage');
        $data = $request->validate(['reason' => ['required', 'string', 'max:500']], ['reason.required' => '반려 사유를 적어주세요.']);
        try {
            $inq = $this->sales()->rejectInquiry($id, $data['reason']);
        } catch (\InvalidArgumentException $e) {
            return back()->withErrors(['inquiry' => $e->getMessage()]);
        }
        $this->audit($request, '설정', '광고 문의 반려', "{$inq->code} · {$inq->company}", ['reason' => $data['reason']]);
        Ui::forgetBadges();
        return back()->with('status', "문의 {$inq->code}를 반려하고 사유를 메일로 보냈어요");
    }

    public function close(Request $request, int $id): RedirectResponse
    {
        $this->need($request, 'ads.manage');
        $this->sales()->closeInquiry($id);
        $this->audit($request, '설정', '광고 문의 닫기', "문의 #{$id}");
        Ui::forgetBadges();
        return back()->with('status', '문의를 닫았어요 · 메일은 보내지 않았어요');
    }

    /** 문의 첨부(비공개 디스크) */
    public function file(Request $request, int $id, int $n)
    {
        $f = $this->sales()->inquiryFile($id, $n);
        abort_unless($f && Storage::disk('local')->exists($f['path']), 404);
        $this->audit($request, '설정', '광고 문의 첨부 내려받기', "문의 #{$id} · {$f['name']}");
        return Storage::disk('local')->download($f['path'], $f['name']);
    }

    // ── 계약 ───────────────────────────────────

    private function act(Request $request, int $id, string $label, callable $fn, string $done): RedirectResponse
    {
        $this->need($request, 'ads.manage');
        try {
            $result = $fn();
        } catch (\InvalidArgumentException $e) {
            return back()->withErrors(['contract' => $e->getMessage()])->withInput();
        }
        $c = $this->sales()->contract($id);
        $this->audit($request, '설정', $label, ($c ? "{$c->quote_no} · {$c->company}" : "계약 #{$id}"));
        Ui::forgetBadges();
        return back()->with('status', is_string($result) ? $result : $done);
    }

    public function resend(Request $request, int $id): RedirectResponse
    {
        return $this->act($request, $id, '광고 견적 다시 보내기', function () use ($id) {
            $c = $this->sales()->contract($id) ?? throw new \InvalidArgumentException('계약을 찾을 수 없어요.');
            return $this->sales()->mailQuote($c) ? "견적서를 {$c->email} 로 다시 보냈어요" : '메일을 못 보냈어요 · 링크를 복사해 직접 보내주세요';
        }, '');
    }

    public function paid(Request $request, int $id): RedirectResponse
    {
        return $this->act($request, $id, '광고 입금 확인', fn () => $this->sales()->confirmPayment($id),
            '입금을 확인했어요 · 광고주에게 계약 완료 메일을 보냈어요. 이제 광고를 만들어주세요');
    }

    public function tax(Request $request, int $id): RedirectResponse
    {
        $on = $request->boolean('issued');
        return $this->act($request, $id, $on ? '세금계산서 발행 표시' : '세금계산서 발행 표시 취소',
            fn () => $this->sales()->markTaxInvoice($id, $on), $on ? '세금계산서 발행으로 표시했어요' : '세금계산서 발행 표시를 지웠어요');
    }

    public function rotate(Request $request, int $id, string $kind): RedirectResponse
    {
        abort_unless(in_array($kind, ['quote', 'report'], true), 404);
        return $this->act($request, $id, $kind === 'report' ? '성과 보고 링크 다시 발급' : '견적서 링크 다시 발급',
            fn () => $this->sales()->rotate($id, $kind), '새 링크를 만들었어요 · 이전 링크는 이제 열리지 않아요. 새 링크를 광고주에게 보내주세요');
    }

    public function reportMail(Request $request, int $id): RedirectResponse
    {
        return $this->act($request, $id, '성과 보고 메일 보내기', function () use ($id) {
            $c = $this->sales()->contract($id) ?? throw new \InvalidArgumentException('계약을 찾을 수 없어요.');
            if (!in_array($c->status, ['contracted', 'live', 'ended'], true)) throw new \InvalidArgumentException('입금을 확인한 계약만 보낼 수 있어요.');
            return $this->sales()->mailReport($c) ? "성과 보고 링크를 {$c->email} 로 보냈어요" : '메일을 못 보냈어요 · 링크를 복사해 직접 보내주세요';
        }, '');
    }

    public function end(Request $request, int $id): RedirectResponse
    {
        return $this->act($request, $id, '광고 계약 종료', fn () => $this->sales()->end($id), '종료했어요 · 연결된 광고를 끄고 최종 보고 메일을 보냈어요');
    }

    public function cancel(Request $request, int $id): RedirectResponse
    {
        $data = $request->validate(['reason' => ['required', 'string', 'max:300']], ['reason.required' => '취소 사유를 적어주세요(광고주에게는 안 보여요).']);
        return $this->act($request, $id, '광고 계약 취소', fn () => $this->sales()->cancel($id, $data['reason']),
            '취소했어요 · 연결된 광고를 껐어요. 환불이 필요하면 따로 처리해주세요');
    }

    public function memo(Request $request, int $id): RedirectResponse
    {
        $this->need($request, 'ads.manage');
        $data = $request->validate(['memo' => ['nullable', 'string', 'max:2000']]);
        $this->db()->table('starttoo_ad_contracts')->where('id', $id)->update(['memo' => $data['memo'] ?: null, 'updated_at' => now()]);
        return back()->with('status', '메모를 저장했어요');
    }

    public function createAd(Request $request, int $id): RedirectResponse
    {
        $this->need($request, 'ads.manage');
        $data = $request->validate([
            'line' => ['required', 'integer', 'min:0'],
            'title' => ['required', 'string', 'max:80'],
            'link' => ['nullable', 'string', 'max:200'],
            'cap' => ['nullable', 'integer', 'min:1000', 'max:100000000'],
            'image' => ['nullable', 'image', 'max:10240'],
        ], ['title.required' => '광고 문구를 적어주세요.'], ['title' => '광고 문구', 'cap' => '보장 노출']);
        try {
            $adId = $this->sales()->createAd($id, (int) $data['line'], [
                'title' => $data['title'], 'link' => $data['link'] ?? null, 'cap' => $data['cap'] ?? null,
                'image_bytes' => $request->file('image') ? (string) file_get_contents($request->file('image')->getRealPath()) : '',
            ]);
        } catch (\InvalidArgumentException $e) {
            return back()->withErrors(['contract' => $e->getMessage()])->withInput();
        }
        $c = $this->sales()->contract($id);
        $this->audit($request, '설정', '계약 광고 만들기', "광고 #{$adId} · {$c->quote_no} · {$c->company}");
        $when = $c->starts_at->isFuture() ? $c->starts_at->format('m.d') . '부터' : '지금부터';
        return back()->with('status', "광고 #{$adId}를 만들었어요 · {$when} 앱에 나가고, 시작일에 광고주에게 성과 보고 링크가 가요");
    }

    // ── 광고주 · 단가표 ────────────────────────

    public function advertiserLink(Request $request, int $id): RedirectResponse
    {
        $this->need($request, 'ads.manage');
        $a = app(Advertisers::class)->find($id);
        abort_unless($a, 404);
        $sent = app(Advertisers::class)->sendPasswordLink($a->email);
        $this->audit($request, '설정', '광고주 비밀번호 설정 링크 보내기', "{$a->company} · 광고주 #{$id}");
        return back()->with('status', $sent ? "{$a->email} 로 비밀번호 설정 링크를 보냈어요" : '방금 보낸 링크가 있거나 메일을 못 보냈어요 · 1분 뒤에 다시 해주세요');
    }

    public function rates(Request $request): RedirectResponse
    {
        $this->need($request, 'ads.manage');
        $rules = [];
        foreach (array_keys(AdSales::SLOTS) as $slot) {
            $rules["rates.$slot.on"] = ['nullable', 'boolean'];
            $rules["rates.$slot.cpm"] = ['required', 'integer', 'min:0', 'max:1000000'];
            $rules["rates.$slot.min"] = ['required', 'integer', 'min:1000', 'max:100000000'];
        }
        $data = $request->validate($rules, ['*.required' => 'CPM 단가와 최소 노출을 모두 적어주세요.']);
        $before = $this->sales()->rates();
        try {
            $after = $this->sales()->saveRates($data['rates'] ?? [], (int) $this->admin($request)->id);
        } catch (\InvalidArgumentException $e) {
            return back()->withErrors(['rates' => $e->getMessage()])->withInput();
        }
        $this->audit($request, '설정', '외부 광고 단가표 저장', '광고 문의 · 계약', [
            'before' => array_map(fn ($r) => ['on' => $r['on'], 'cpm' => $r['cpm'], 'min' => $r['min']], $before), 'after' => $after]);
        return back()->with('status', '단가표를 저장했어요 · 상품 소개 · 문의 화면과 새 견적에 바로 반영돼요');
    }

    public function cert(Request $request, int $id)
    {
        $c = $this->sales()->contract($id);
        abort_unless($c && $c->biz_cert_path && Storage::disk('local')->exists($c->biz_cert_path), 404);
        $this->audit($request, '설정', '사업자등록증 내려받기', "{$c->quote_no} · {$c->company}");
        return Storage::disk('local')->download($c->biz_cert_path, '사업자등록증-' . $c->quote_no . '.' . pathinfo($c->biz_cert_path, PATHINFO_EXTENSION));
    }
}
