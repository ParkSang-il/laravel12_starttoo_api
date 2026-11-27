<?php

namespace App\Services\Starttoo;

use App\Services\ProjectDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;

/**
 * 외부 광고 영업(디자인 AW-01 ~ AW-03, docs/ads.md 「외부 광고」).
 * 문의(new) → 견적(quote_sent) → 광고주 수락(awaiting_payment) → 입금 알림(payment_reported)
 * → 운영자 입금 확인(contracted) → 광고 만들기 · 시작일(live) → 종료일 · 보장 노출 다 채움(ended). 어디서든 cancelled.
 * 단가는 CPM(노출 1,000회당)이고, 계약 광고는 보장 노출(impression_cap)을 채우면 그만 나간다.
 */
class AdSales
{
    /** 외부 광고를 받을 수 있는 자리(운영자 광고 자리 Ads::ADMIN_SLOTS). 단가 · 최소 노출은 콘솔 단가표에서 바꾼다 */
    public const SLOTS = [
        'feed_inline' => ['name' => '추천 피드 카드', 'desc' => '홈 추천 피드 게시물 사이 · 세로 사진 카드', 'spec' => '세로 사진 필수 · 1080×1350 권장',
            'top' => 26, 'h' => 24, 'cpm' => 6000, 'min' => 50000],
        'community_top' => ['name' => '커뮤니티 상단', 'desc' => '커뮤니티 피드 맨 위 카드 · 실시간 인기글과 좌우로 넘김', 'spec' => '문구 중심 · 사진 선택',
            'top' => 8, 'h' => 14, 'cpm' => 4000, 'min' => 30000],
    ];
    public const MAX_LINES = 4;
    public const STATUS = [
        'quote_sent' => ['견적 보냄', 'amber'], 'awaiting_payment' => ['입금 대기', 'blue'], 'payment_reported' => ['입금 확인 필요', 'red'],
        'contracted' => ['계약 완료', 'green'], 'live' => ['노출 중', 'green'], 'ended' => ['종료', 'gray'], 'cancelled' => ['취소', 'gray'],
    ];
    public const INQUIRY_STATUS = [
        'new' => ['새 문의', 'red'], 'quoted' => ['견적 보냄', 'amber'], 'contracted' => ['계약', 'green'],
        'rejected' => ['반려', 'gray'], 'closed' => ['닫음', 'gray'],
    ];

    private function db() { return app(ProjectDatabase::class)->database(); }

    // ── 단가표 ─────────────────────────────────

    public function rates(): array
    {
        $saved = app(Settings::class)->json('ad_external_rates') ?? [];
        $out = [];
        foreach (self::SLOTS as $slot => $def) {
            $s = $saved[$slot] ?? [];
            $out[$slot] = $def + [
                'on' => (bool) ($s['on'] ?? true),
                'cpm' => max(0, (int) ($s['cpm'] ?? $def['cpm'])),
                'min' => max(1000, (int) ($s['min'] ?? $def['min'])),
            ];
        }
        return $out;
    }

    /** 문의 · 상품 소개에 보일 자리(받기 켠 것만) */
    public function openSlots(): array
    {
        return array_filter($this->rates(), fn ($r) => $r['on']);
    }

    public function saveRates(array $in, ?int $adminId): array
    {
        $value = [];
        foreach (self::SLOTS as $slot => $def) {
            $r = $in[$slot] ?? [];
            $min = (int) ($r['min'] ?? $def['min']);
            if ($min % 1000 !== 0) throw new \InvalidArgumentException('최소 노출은 1,000회 단위로 적어주세요.');
            $value[$slot] = ['on' => !empty($r['on']), 'cpm' => max(0, (int) ($r['cpm'] ?? $def['cpm'])), 'min' => max(1000, $min)];
        }
        app(Settings::class)->set('ad_external_rates', $value, $adminId);
        Cache::forget('advertiser.slot_stats');
        return $value;
    }

    /** 자리별 최근 30일 하루 평균 노출(모든 광고 합계 · 실제 기록이 있는 날만 나눔). [slot => int|null] */
    public function slotDaily(): array
    {
        return Cache::remember('advertiser.slot_stats', now()->addMinutes((int) config('advertiser.stats_cache_minutes', 60)), function () {
            $rows = $this->db()->table('starttoo_ad_daily as d')->join('starttoo_ads as a', 'a.id', '=', 'd.ad_id')
                ->where('d.day', '>=', today()->subDays(30)->toDateString())->where('d.day', '<', today()->toDateString())
                ->whereIn('a.slot', array_keys(self::SLOTS))
                ->groupBy('a.slot')->selectRaw('a.slot, SUM(d.impressions) imp, COUNT(DISTINCT d.day) days')->get();
            $out = array_fill_keys(array_keys(self::SLOTS), null);
            foreach ($rows as $r) if ((int) $r->days > 0 && (int) $r->imp > 0) $out[$r->slot] = (int) round($r->imp / $r->days);
            return $out;
        });
    }

    /** AW-01 왼쪽 수치 3개(최근 30일 실제 광고 기록. 기록이 없으면 빈 배열 → 화면에서 숨김) */
    public function siteStats(): array
    {
        return Cache::remember('advertiser.site_stats', now()->addMinutes((int) config('advertiser.stats_cache_minutes', 60)), function () {
            $row = $this->db()->table('starttoo_ad_daily')->where('day', '>=', today()->subDays(30)->toDateString())
                ->selectRaw('SUM(impressions) imp, SUM(clicks) clk, COUNT(DISTINCT day) days')->first();
            $imp = (int) ($row->imp ?? 0);
            $clk = (int) ($row->clk ?? 0);
            $days = max(1, (int) ($row->days ?? 0));
            if ($imp === 0) return [];
            return [
                ['v' => self::short($imp), 'label' => '한 달 광고 자리 노출'],
                ['v' => number_format($clk / $imp * 100, 1) . '%', 'label' => '광고 평균 클릭률', 'raw' => $clk / $imp * 100],
                ['v' => self::short((int) round($imp / $days)), 'label' => '하루 평균 광고 노출'],
            ];
        });
    }

    public function avgCtr(): ?float
    {
        $s = $this->siteStats();
        return isset($s[1]['raw']) ? (float) $s[1]['raw'] : null;
    }

    /** 12,345 → 1.2만 · 1,820,000 → 182만 */
    public static function short(int $n): string
    {
        if ($n >= 1000000) return number_format($n / 10000) . '만';
        if ($n >= 10000) return rtrim(rtrim(number_format($n / 10000, 1), '0'), '.') . '만';
        return number_format($n);
    }

    // ── 문의 ───────────────────────────────────

    /** AW-01 문의 저장. $advertiserId = 로그인한 광고주(아니면 null — 견적을 만들 때 이메일로 잇는다) */
    public function createInquiry(array $d, array $files, ?string $ip, ?int $advertiserId): object
    {
        $id = null;
        for ($try = 0; $try < 5 && !$id; $try++) {
            $day = now()->format('Ymd');
            $n = $this->db()->table('starttoo_ad_inquiries')->where('code', 'like', "AD-$day-%")->count() + 1 + $try;
            $code = sprintf('AD-%s-%03d', $day, $n);
            $stored = [];
            foreach ($files as $f) {
                /** @var UploadedFile $f */
                $stored[] = ['path' => $f->store("ad-inquiries/$code", 'local'), 'name' => mb_substr($f->getClientOriginalName(), 0, 120), 'size' => $f->getSize()];
            }
            try {
                $id = $this->db()->table('starttoo_ad_inquiries')->insertGetId([
                    'code' => $code, 'advertiser_id' => $advertiserId,
                    'company' => $d['company'], 'contact_name' => $d['contact_name'],
                    'email' => Advertisers::normEmail($d['email']), 'phone' => $d['phone'],
                    'industry' => $d['industry'] ?? null,
                    'slots' => json_encode(array_values(array_unique($d['slots']))),
                    'wish_start' => $d['wish_start'] ?? null, 'duration' => $d['duration'] ?? null, 'budget' => $d['budget'] ?? null,
                    'landing_url' => $d['landing_url'] ?? null, 'files' => json_encode($stored), 'memo' => $d['memo'] ?? null,
                    'privacy_agreed_at' => now(), 'ip' => $ip, 'created_at' => now(), 'updated_at' => now(),
                ]);
            } catch (\Illuminate\Database\UniqueConstraintViolationException) {
                foreach ($stored as $s) \Illuminate\Support\Facades\Storage::disk('local')->delete($s['path']);
            }
        }
        if (!$id) throw new \RuntimeException('문의 번호를 만들지 못했어요.');
        $inq = $this->inquiry($id);
        $this->mailOps("[광고 문의] {$inq->company} · {$inq->code}", [
            "회사: {$inq->company} / 담당: {$inq->contact_name} / {$inq->email} / {$inq->phone}",
            '자리: ' . implode(', ', $this->slotNames($inq->slots)),
            "기간: {$inq->duration} / 시작: " . ($inq->wish_start ?? '-') . " / 예산: {$inq->budget}",
            "연결 주소: {$inq->landing_url}",
            '첨부: ' . count($inq->files) . '개',
            '', (string) $inq->memo, '',
            '운영 콘솔: ' . self::link('admin.ad-sales', ['sel' => 'i' . $inq->id]),
        ]);
        return $inq;
    }

    public function inquiry(int $id): ?object
    {
        $r = $this->db()->table('starttoo_ad_inquiries')->where('id', $id)->first();
        return $r ? $this->hydrateInquiry($r) : null;
    }

    public function inquiryByCode(string $code): ?object
    {
        $r = $this->db()->table('starttoo_ad_inquiries')->where('code', $code)->first();
        return $r ? $this->hydrateInquiry($r) : null;
    }

    public function hydrateInquiry(object $r): object
    {
        $r->slots = json_decode((string) $r->slots, true) ?: [];
        $r->files = json_decode((string) $r->files, true) ?: [];
        return $r;
    }

    public function slotNames(array $slots): array
    {
        return array_values(array_filter(array_map(fn ($k) => self::SLOTS[$k]['name'] ?? null, $slots)));
    }

    public function rejectInquiry(int $id, string $reason): object
    {
        $inq = $this->inquiry($id);
        if (!$inq) throw new \InvalidArgumentException('문의를 찾을 수 없어요.');
        if (!in_array($inq->status, ['new', 'quoted'], true)) throw new \InvalidArgumentException('이미 처리한 문의예요.');
        $this->db()->table('starttoo_ad_inquiries')->where('id', $id)->update(['status' => 'rejected', 'reject_reason' => $reason, 'updated_at' => now()]);
        $this->mail($inq->email, "[STARTTOO 광고] 문의 {$inq->code} 답변", [
            "{$inq->company} {$inq->contact_name}님, 광고 문의 감사합니다.",
            '', '검토해 보았지만 이번 광고는 진행하기 어려워요.', '', "사유: {$reason}", '',
            '궁금한 점은 이 메일에 답장하거나 ' . config('advertiser.ops_email') . ' 로 보내주세요.',
        ]);
        return $inq;
    }

    public function closeInquiry(int $id): void
    {
        $this->db()->table('starttoo_ad_inquiries')->where('id', $id)->whereIn('status', ['new', 'quoted'])->update(['status' => 'closed', 'updated_at' => now()]);
    }

    /** 문의 첨부(비공개 디스크) 내려받기 경로 */
    public function inquiryFile(int $id, int $index): ?array
    {
        $inq = $this->inquiry($id);
        return $inq->files[$index] ?? null;
    }

    // ── 견적 · 계약 ────────────────────────────

    public function hydrate(object $r): object
    {
        $c = clone $r;
        $c->lines = json_decode((string) $r->lines, true) ?: [];
        $c->terms = json_decode((string) $r->terms, true) ?: [];
        foreach (['valid_until', 'starts_at', 'ends_at'] as $k) $c->$k = $r->$k ? Carbon::parse($r->$k)->startOfDay() : null;
        foreach (['created_at', 'accepted_at', 'payment_reported_at', 'paid_at', 'tax_invoice_issued_at', 'change_requested_at', 'report_mailed_at', 'final_mailed_at'] as $k) {
            $c->$k = $r->$k ? Carbon::parse($r->$k) : null;
        }
        foreach (['discount', 'supply', 'vat', 'total'] as $k) $c->$k = (int) $r->$k;
        $c->expired = $c->status === 'quote_sent' && $c->valid_until && $c->valid_until->copy()->endOfDay()->isPast();
        $c->stage = match ($c->status) { 'quote_sent' => 0, 'awaiting_payment' => 1, 'payment_reported' => 2, default => 3 };
        return $c;
    }

    public function contract(int $id): ?object
    {
        $r = $this->db()->table('starttoo_ad_contracts')->where('id', $id)->first();
        return $r ? $this->hydrate($r) : null;
    }

    public function byQuoteToken(string $token): ?object
    {
        $r = $this->db()->table('starttoo_ad_contracts')->where('quote_token', $token)->first();
        return $r ? $this->hydrate($r) : null;
    }

    public function byReportToken(string $token): ?object
    {
        $r = $this->db()->table('starttoo_ad_contracts')->where('report_token', $token)->first();
        return $r ? $this->hydrate($r) : null;
    }

    /** 광고주 · 콘솔 링크(config advertiser.url 기준 — 서버 APP_URL 과 상관없이 www.starttoo.com) */
    public static function link(string $name, mixed $params = []): string
    {
        return config('advertiser.url') . route($name, $params, false);
    }

    public function quoteUrl(object $c): string { return self::link('advertiser.quote', $c->quote_token); }

    public function reportUrl(object $c): string
    {
        if (!$c->report_token) {
            $c->report_token = Str::random(48);
            $this->db()->table('starttoo_ad_contracts')->where('id', $c->id)->update(['report_token' => $c->report_token, 'updated_at' => now()]);
        }
        return self::link('advertiser.report', $c->report_token);
    }

    /**
     * 견적 만들기(콘솔). lines = [{slot, imps, cpm}] — 금액 = imps / 1000 × cpm.
     * 견적 이메일로 광고주 계정을 찾거나 만들고, 견적 메일을 보낸다.
     */
    public function createQuote(?int $inquiryId, array $in, int $adminId): object
    {
        $inq = $inquiryId ? $this->inquiry($inquiryId) : null;
        if ($inquiryId && !$inq) throw new \InvalidArgumentException('문의를 찾을 수 없어요.');
        $rates = $this->rates();
        $lines = [];
        foreach ($in['lines'] ?? [] as $l) {
            $slot = (string) ($l['slot'] ?? '');
            $imps = (int) ($l['imps'] ?? 0);
            if ($slot === '' && $imps === 0) continue;
            if (!isset($rates[$slot])) throw new \InvalidArgumentException('광고 자리를 골라주세요.');
            if ($imps <= 0 || $imps % 1000 !== 0) throw new \InvalidArgumentException('보장 노출은 1,000회 단위로 적어주세요.');
            if ($imps < $rates[$slot]['min']) throw new \InvalidArgumentException($rates[$slot]['name'] . '은 최소 ' . number_format($rates[$slot]['min']) . '회부터예요.');
            $cpm = isset($l['cpm']) && $l['cpm'] !== '' ? (int) $l['cpm'] : $rates[$slot]['cpm'];
            if ($cpm < 0) throw new \InvalidArgumentException('CPM 단가를 확인해주세요.');
            $daily = $this->slotDaily()[$slot] ?? null;
            $lines[] = ['slot' => $slot, 'imps' => $imps, 'cpm' => $cpm, 'amount' => intdiv($imps * $cpm, 1000),
                // 견적 때의 예상 소진 일수(최근 30일 하루 평균 노출 기준, 기록이 없으면 null)
                'est_days' => $daily ? (int) ceil($imps / $daily) : null];
        }
        if (!$lines) throw new \InvalidArgumentException('광고 자리와 보장 노출을 하나 이상 적어주세요.');
        if (count($lines) > self::MAX_LINES) throw new \InvalidArgumentException('자리는 ' . self::MAX_LINES . '줄까지 넣을 수 있어요.');
        $sum = array_sum(array_column($lines, 'amount'));
        $discount = max(0, (int) ($in['discount'] ?? 0));
        if ($discount > $sum) throw new \InvalidArgumentException('할인이 금액보다 커요.');
        $supply = $sum - $discount;
        $vat = (int) round($supply * 0.1);
        $starts = Carbon::parse($in['starts_at'])->startOfDay();
        $ends = Carbon::parse($in['ends_at'])->startOfDay();
        if ($ends->lt($starts)) throw new \InvalidArgumentException('종료일이 시작일보다 빨라요.');
        $email = Advertisers::normEmail($in['email'] ?? $inq?->email ?? '');
        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) throw new \InvalidArgumentException('받는 분 이메일을 확인해주세요.');
        $company = trim((string) ($in['company'] ?? $inq?->company ?? ''));
        $contact = trim((string) ($in['contact_name'] ?? $inq?->contact_name ?? ''));
        if ($company === '' || $contact === '') throw new \InvalidArgumentException('회사명과 담당자를 적어주세요.');
        $landing = trim((string) ($in['landing_url'] ?? $inq?->landing_url ?? '')) ?: null;
        if ($landing !== null && !preg_match('#^https://#i', $landing)) throw new \InvalidArgumentException('연결 주소는 https:// 로 시작해야 앱에서 열 수 있어요.');
        // u 플래그 필수: 없으면 \R 이 바이트 0x85(NEL)도 줄바꿈으로 보고 한글 글자 중간을 잘라 json_encode 가 실패한다
        $terms = array_values(array_filter(array_map('trim', preg_split('/\R/u', (string) ($in['terms'] ?? '')) ?: [])));

        $adv = app(Advertisers::class)->findOrCreate($email, ['company' => $company, 'contact_name' => $contact, 'phone' => $inq?->phone]);
        $id = null;
        for ($try = 0; $try < 5 && !$id; $try++) {
            $year = now()->year;
            $n = $this->db()->table('starttoo_ad_contracts')->where('quote_no', 'like', "Q-$year-%")->count() + 1 + $try;
            try {
                $id = $this->db()->table('starttoo_ad_contracts')->insertGetId([
                    'inquiry_id' => $inq?->id, 'advertiser_id' => $adv->id,
                    'quote_no' => sprintf('Q-%d-%04d', $year, $n),
                    'title' => mb_substr(trim((string) $in['title']), 0, 120),
                    'company' => mb_substr($company, 0, 100), 'contact_name' => mb_substr($contact, 0, 60), 'email' => $email,
                    'manager' => ($in['manager'] ?? null) ?: null, 'landing_url' => $landing,
                    'lines' => json_encode($lines), 'discount_label' => ($in['discount_label'] ?? null) ?: null, 'discount' => $discount,
                    'supply' => $supply, 'vat' => $vat, 'total' => $supply + $vat,
                    'terms' => json_encode($terms, JSON_UNESCAPED_UNICODE),
                    'valid_until' => Carbon::parse($in['valid_until'])->toDateString(),
                    'starts_at' => $starts->toDateString(), 'ends_at' => $ends->toDateString(),
                    'status' => 'quote_sent', 'quote_token' => Str::random(48),
                    'memo' => ($in['memo'] ?? null) ?: null, 'created_by' => $adminId,
                    'created_at' => now(), 'updated_at' => now(),
                ]);
            } catch (\Illuminate\Database\UniqueConstraintViolationException) {
            }
        }
        if (!$id) throw new \RuntimeException('견적 번호를 만들지 못했어요.');
        if ($inq) {
            $this->db()->table('starttoo_ad_inquiries')->where('id', $inq->id)->update(['status' => 'quoted', 'advertiser_id' => $adv->id, 'updated_at' => now()]);
        }
        $c = $this->contract($id);
        $c->mailed = $this->mailQuote($c);
        return $c;
    }

    public function mailQuote(object $c): bool
    {
        $adv = $c->advertiser_id ? app(Advertisers::class)->find((int) $c->advertiser_id) : null;
        return $this->mail($c->email, "[STARTTOO 광고] 견적서 {$c->quote_no} · {$c->title}", array_filter([
            "{$c->company} {$c->contact_name}님, 스타투 광고 견적서를 보내드려요.",
            '',
            "견적: {$c->title}",
            '합계: ₩' . number_format($c->total) . ' (부가세 포함)',
            '유효 기한: ' . $c->valid_until->format('Y.m.d') . '까지',
            '',
            '견적서 보기 · 수락: ' . $this->quoteUrl($c),
            '',
            $adv && !$adv->password ? '견적서 화면에서 광고주 계정을 만들면 견적 · 성과 보고를 한 곳에서 볼 수 있어요.' : null,
            '링크를 열면 광고 문의 때 적은 이메일(이 메일을 받은 주소)을 한 번 입력해주세요.',
        ], fn ($l) => $l !== null));
    }

    public function accept(object $c): void
    {
        if ($c->status !== 'quote_sent') return;
        if ($c->expired) throw new \InvalidArgumentException('유효 기한이 지난 견적이에요. 담당자에게 새 견적을 요청해주세요.');
        $this->db()->table('starttoo_ad_contracts')->where('id', $c->id)->where('status', 'quote_sent')
            ->update(['status' => 'awaiting_payment', 'accepted_at' => now(), 'updated_at' => now()]);
        $this->mailOps("[견적 수락] {$c->company} · {$c->quote_no}", [$c->title, '합계 ₩' . number_format($c->total), '',
            '운영 콘솔: ' . self::link('admin.ad-sales', ['tab' => '계약', 'sel' => 'c' . $c->id])]);
    }

    public function requestChange(object $c, string $text): void
    {
        if ($c->status !== 'quote_sent') throw new \InvalidArgumentException('이미 수락한 견적은 담당자에게 메일로 요청해주세요.');
        $this->db()->table('starttoo_ad_contracts')->where('id', $c->id)
            ->update(['change_request' => $text, 'change_requested_at' => now(), 'updated_at' => now()]);
        $this->mailOps("[견적 수정 요청] {$c->company} · {$c->quote_no}", [$text, '',
            '운영 콘솔: ' . self::link('admin.ad-sales', ['tab' => '계약', 'sel' => 'c' . $c->id])]);
    }

    public function reportPayment(object $c, array $d, ?UploadedFile $cert): void
    {
        if ($c->status !== 'awaiting_payment') throw new \InvalidArgumentException('입금을 알릴 수 있는 단계가 아니에요.');
        $path = $cert?->store("ad-contracts/{$c->id}", 'local');
        $biz = preg_replace('/^(\d{3})-?(\d{2})-?(\d{5})$/', '$1-$2-$3', $d['biz_no']);
        $this->db()->table('starttoo_ad_contracts')->where('id', $c->id)->update([
            'biz_no' => $biz, 'biz_name' => $d['biz_name'], 'tax_email' => $d['tax_email'],
            'biz_cert_path' => $path ?? $c->biz_cert_path,
            'status' => 'payment_reported', 'payment_reported_at' => now(), 'updated_at' => now(),
        ]);
        if ($c->advertiser_id) app(Advertisers::class)->rememberTax((int) $c->advertiser_id, ['biz_no' => $biz] + $d);
        $this->mailOps("[입금 알림] {$c->company} · {$c->quote_no}", ['입금 확인 후 콘솔에서 [입금 확인]을 눌러주세요.', '합계 ₩' . number_format($c->total), '',
            '운영 콘솔: ' . self::link('admin.ad-sales', ['tab' => '계약', 'sel' => 'c' . $c->id])]);
    }

    /** 콘솔: 입금 확인 → 계약 완료(광고주가 입금 알림을 안 눌렀어도 확인할 수 있다) */
    public function confirmPayment(int $id): object
    {
        $c = $this->mustContract($id);
        if (!in_array($c->status, ['awaiting_payment', 'payment_reported', 'quote_sent'], true)) throw new \InvalidArgumentException('입금을 확인할 단계가 아니에요.');
        $this->db()->table('starttoo_ad_contracts')->where('id', $id)->update([
            'status' => 'contracted', 'paid_at' => now(), 'accepted_at' => $c->accepted_at ?? now(), 'updated_at' => now(),
        ]);
        if ($c->inquiry_id) $this->db()->table('starttoo_ad_inquiries')->where('id', $c->inquiry_id)->update(['status' => 'contracted', 'updated_at' => now()]);
        $c = $this->contract($id);
        $this->mail($c->email, "[STARTTOO 광고] 계약 완료 · {$c->title}", [
            "{$c->company} {$c->contact_name}님, 입금을 확인했어요. 계약이 완료됐어요.",
            '',
            '노출 기간: ' . $c->starts_at->format('Y.m.d') . ' ~ ' . $c->ends_at->format('Y.m.d') . ' (보장 노출을 채우면 먼저 끝나요)',
            '노출이 시작되는 날 성과 보고 링크를 보내드려요.',
            '',
            '견적서 · 계약 내용: ' . $this->quoteUrl($c),
        ]);
        return $c;
    }

    public function markTaxInvoice(int $id, bool $issued): void
    {
        $c = $this->mustContract($id);
        if (!$c->paid_at) throw new \InvalidArgumentException('입금을 확인한 뒤에 표시할 수 있어요.');
        $this->db()->table('starttoo_ad_contracts')->where('id', $id)->update(['tax_invoice_issued_at' => $issued ? now() : null, 'updated_at' => now()]);
    }

    public function cancel(int $id, string $reason): object
    {
        $c = $this->mustContract($id);
        if (in_array($c->status, ['ended', 'cancelled'], true)) throw new \InvalidArgumentException('이미 끝난 계약이에요.');
        $this->db()->table('starttoo_ad_contracts')->where('id', $id)->update([
            'status' => 'cancelled', 'memo' => trim(($c->memo ? $c->memo . "\n" : '') . '[취소] ' . $reason), 'updated_at' => now(),
        ]);
        // 연결된 광고는 끈다
        $this->db()->table('starttoo_ads')->where('contract_id', $id)->update(['active' => false, 'updated_at' => now()]);
        if ($c->inquiry_id && !$this->db()->table('starttoo_ad_contracts')->where('inquiry_id', $c->inquiry_id)->whereNotIn('status', ['cancelled'])->exists()) {
            $this->db()->table('starttoo_ad_inquiries')->where('id', $c->inquiry_id)->whereIn('status', ['quoted', 'contracted'])->update(['status' => 'new', 'updated_at' => now()]);
        }
        return $c;
    }

    /** 콘솔 [링크 다시 발급] — 이전 링크는 바로 404 */
    public function rotate(int $id, string $kind): object
    {
        $c = $this->mustContract($id);
        $col = $kind === 'report' ? 'report_token' : 'quote_token';
        $this->db()->table('starttoo_ad_contracts')->where('id', $id)->update([$col => Str::random(48), 'updated_at' => now()]);
        return $this->contract($id);
    }

    /**
     * 콘솔 「광고로 만들기」: 계약의 한 줄(자리 · 보장 노출)로 운영자 광고를 만든다.
     * 노출 기간 = 계약 기간, 보장 노출을 채우면 그만 나간다(impression_cap).
     */
    public function createAd(int $id, int $line, array $in): int
    {
        $c = $this->mustContract($id);
        if (!in_array($c->status, ['contracted', 'live'], true)) throw new \InvalidArgumentException('입금을 확인한 계약만 광고를 만들 수 있어요.');
        $l = $c->lines[$line] ?? null;
        if (!$l) throw new \InvalidArgumentException('견적 줄을 찾을 수 없어요.');
        // 같은 줄로 이미 만든 광고 보장 노출을 뺀 나머지만 새 광고에 준다(소재를 나눠 여러 개 만들 수 있다)
        $used = (int) $this->db()->table('starttoo_ads')->where('contract_id', $id)->where('slot', $l['slot'])->sum('impression_cap');
        $cap = isset($in['cap']) && $in['cap'] !== '' ? (int) $in['cap'] : (int) $l['imps'] - $used;
        if ($cap <= 0) throw new \InvalidArgumentException('이 자리의 보장 노출을 이미 다른 광고에 모두 나눴어요.');
        if ($used + $cap > (int) $l['imps']) throw new \InvalidArgumentException('보장 노출 합계가 견적(' . number_format($l['imps']) . '회)보다 많아요.');
        $adId = app(Ads::class)->add([
            'slot' => $l['slot'], 'advertiser' => mb_substr($c->company, 0, 60), 'title' => $in['title'],
            'link' => $in['link'] ?: $c->landing_url, 'image_bytes' => $in['image_bytes'] ?? '',
            'from' => $c->starts_at->toDateString(), 'until' => $c->ends_at->toDateString(),
        ]);
        $this->db()->table('starttoo_ads')->where('id', $adId)->update(['contract_id' => $id, 'impression_cap' => $cap]);
        return $adId;
    }

    /** 계약에 연결된 광고 */
    public function contractAds(int $id): array
    {
        return $this->db()->table('starttoo_ads')->where('contract_id', $id)->orderBy('id')->get()->all();
    }

    /** 콘솔 · 스케줄러: 종료 처리 + 최종 보고 메일 */
    public function end(int $id): object
    {
        $c = $this->mustContract($id);
        if (!in_array($c->status, ['contracted', 'live'], true)) throw new \InvalidArgumentException('노출 중인 계약만 종료할 수 있어요.');
        $this->db()->table('starttoo_ad_contracts')->where('id', $id)->update(['status' => 'ended', 'updated_at' => now()]);
        $this->db()->table('starttoo_ads')->where('contract_id', $id)->update(['active' => false, 'updated_at' => now()]);
        $c = $this->contract($id);
        if (!$c->final_mailed_at && $this->contractAds($id)) {
            $r = $this->report($c);
            $sent = $this->mail($c->email, "[STARTTOO 광고] 최종 성과 보고 · {$c->title}", [
                "{$c->company} {$c->contact_name}님, 광고가 끝났어요.",
                '', '노출 ' . number_format($r['totImp']) . '회 · 클릭 ' . number_format($r['totClk']) . '회', '',
                '최종 성과 보고: ' . $this->reportUrl($c), '', '다시 집행을 원하시면 ' . self::link('advertiser.inquiry') . ' 에서 문의해주세요.',
            ]);
            if ($sent) $this->db()->table('starttoo_ad_contracts')->where('id', $id)->update(['final_mailed_at' => now()]);
        }
        return $c;
    }

    /** 성과 보고 링크 메일(노출 시작 · 콘솔에서 다시 보내기) */
    public function mailReport(object $c): bool
    {
        $sent = $this->mail($c->email, "[STARTTOO 광고] 노출 시작 · 성과 보고 링크 · {$c->title}", [
            "{$c->company} {$c->contact_name}님, 광고 노출이 시작됐어요.",
            '', '일별 노출 · 클릭은 아래 링크에서 볼 수 있어요(매일 전날까지 반영).', '',
            $this->reportUrl($c), '',
            '이 링크는 로그인 없이 열리는 전용 링크예요. 링크가 퍼졌다면 담당자에게 다시 발급을 요청해주세요.',
        ]);
        if ($sent) $this->db()->table('starttoo_ad_contracts')->where('id', $c->id)->update(['report_mailed_at' => now()]);
        return $sent;
    }

    /**
     * 스케줄러(매시): 계약 완료 + 광고 있음 + 시작일 → 노출 중(성과 보고 메일)
     * 노출 중 + (종료일 지남 또는 보장 노출을 모두 채움) → 종료(최종 보고 메일)
     */
    public function sweep(): array
    {
        $started = 0;
        $ended = 0;
        $ready = $this->db()->table('starttoo_ad_contracts')->where('status', 'contracted')->where('starts_at', '<=', today()->toDateString())
            ->whereExists(fn ($q) => $q->from('starttoo_ads')->whereColumn('starttoo_ads.contract_id', 'starttoo_ad_contracts.id'))->pluck('id');
        foreach ($ready as $id) {
            $this->db()->table('starttoo_ad_contracts')->where('id', $id)->update(['status' => 'live', 'updated_at' => now()]);
            $c = $this->contract((int) $id);
            if (!$c->report_mailed_at) $this->mailReport($c);
            $started++;
        }
        foreach ($this->db()->table('starttoo_ad_contracts')->where('status', 'live')->get() as $row) {
            $done = Carbon::parse($row->ends_at)->lt(today());
            if (!$done) {
                $ads = $this->contractAds((int) $row->id);
                $done = $ads && collect($ads)->every(fn ($a) => $a->impression_cap && (int) $a->impressions >= (int) $a->impression_cap);
            }
            if ($done) { $this->end((int) $row->id); $ended++; }
        }
        return compact('started', 'ended');
    }

    /** 보관 기간(동의 문구 1년)이 지난 문의 지우기 — 계약으로 이어진 문의는 남긴다 */
    public function purgeOldInquiries(): int
    {
        $days = (int) config('advertiser.retention.inquiry_days', 365);
        $rows = $this->db()->table('starttoo_ad_inquiries as i')->where('i.created_at', '<', now()->subDays($days))
            ->whereNotExists(fn ($q) => $q->from('starttoo_ad_contracts as c')->whereColumn('c.inquiry_id', 'i.id')->where('c.status', '!=', 'cancelled'))
            ->limit(200)->get(['i.id', 'i.files']);
        foreach ($rows as $r) {
            foreach (json_decode((string) $r->files, true) ?: [] as $f) \Illuminate\Support\Facades\Storage::disk('local')->delete($f['path']);
            $this->db()->table('starttoo_ad_contracts')->where('inquiry_id', $r->id)->update(['inquiry_id' => null]);
            $this->db()->table('starttoo_ad_inquiries')->where('id', $r->id)->delete();
        }
        return $rows->count();
    }

    private function mustContract(int $id): object
    {
        $c = $this->contract($id);
        if (!$c) throw new \InvalidArgumentException('계약을 찾을 수 없어요.');
        return $c;
    }

    /** 견적서 표의 줄(자리 이름 · 수량 · 단가 기준) */
    public function quoteLines(object $c): array
    {
        return array_map(function ($l) use ($c) {
            $def = self::SLOTS[$l['slot']] ?? ['name' => $l['slot'], 'desc' => '', 'top' => 20, 'h' => 16];
            return [
                'slot' => $l['slot'], 'name' => $def['name'], 'desc' => $def['desc'], 'top' => $def['top'], 'h' => $def['h'],
                'qty' => '보장 노출 ' . number_format($l['imps']) . '회',
                'period' => $c->starts_at->format('m.d') . '부터 채울 때까지 (최대 ' . $c->ends_at->format('m.d') . ')',
                'unit' => '노출 1,000회당 ₩' . number_format($l['cpm']),
                'unit_note' => 'CPM · ' . number_format($l['imps'] / 1000) . ' × ₩' . number_format($l['cpm']),
                'amount' => (int) $l['amount'],
            ];
        }, $c->lines);
    }

    /** 견적서 「예상 성과」(견적 때 계산해 둔 예상 소진 일수 · 보장 노출 합계) */
    public function expected(object $c): array
    {
        $out = [['value' => number_format(array_sum(array_column($c->lines, 'imps'))), 'label' => '보장 노출 합계']];
        foreach ($c->lines as $l) {
            if (!empty($l['est_days'])) $out[] = ['value' => '약 ' . $l['est_days'] . '일', 'label' => (self::SLOTS[$l['slot']]['name'] ?? $l['slot']) . ' 예상 소진'];
        }
        return $out;
    }

    // ── 성과 보고(AW-03) ───────────────────────

    public function report(object $c): array
    {
        $ads = collect($this->contractAds((int) $c->id));
        $from = $c->starts_at;
        $to = $c->ends_at;
        $last = Carbon::yesterday()->lt($to) ? Carbon::yesterday() : $to->copy(); // 전날까지 반영
        $daily = [];
        if ($ads->isNotEmpty() && $last->gte($from)) {
            foreach ($this->db()->table('starttoo_ad_daily')->whereIn('ad_id', $ads->pluck('id'))
                ->whereBetween('day', [$from->toDateString(), $last->toDateString()])->get() as $r) {
                $daily[(int) $r->ad_id][Carbon::parse($r->day)->toDateString()] = [(int) $r->impressions, (int) $r->clicks];
            }
        }
        $days = [];
        for ($d = $from->copy(); $d->lte($to); $d->addDay()) $days[] = $d->toDateString();
        $byDay = array_fill_keys($days, [0, 0]);
        foreach ($daily as $perDay) foreach ($perDay as $d => [$i, $k]) if (isset($byDay[$d])) { $byDay[$d][0] += $i; $byDay[$d][1] += $k; }
        $shown = count(array_filter($days, fn ($d) => $d <= $last->toDateString()));
        $totImp = array_sum(array_column($byDay, 0));
        $totClk = array_sum(array_column($byDay, 1));
        $perAd = $ads->map(fn ($a) => [
            'ad_id' => (int) $a->id, 'slot' => $a->slot, 'creative' => $a->title,
            'thumb' => app(MediaUrls::class)->url($a->image_path, 300),
            'imp' => array_sum(array_column($daily[$a->id] ?? [], 0)),
            'clk' => array_sum(array_column($daily[$a->id] ?? [], 1)),
        ])->values();
        // 자리별: 견적 줄 기준(보장 노출 대비)
        $placements = collect($c->lines)->groupBy('slot')->map(function ($g, $slot) use ($perAd) {
            $mine = $perAd->where('slot', $slot);
            $imp = $mine->sum('imp');
            $clk = $mine->sum('clk');
            $goal = (int) $g->sum('imps');
            return [
                'name' => self::SLOTS[$slot]['name'] ?? $slot, 'unit' => 'CPM · 보장 ' . number_format($goal) . '회',
                'imp' => $imp, 'clk' => $clk, 'ctr' => $imp ? round($clk / $imp * 100, 2) : 0,
                'guaranteed' => $goal, 'goal' => number_format($imp) . ' / ' . number_format($goal) . '회',
                'goalPct' => $goal ? (int) min(100, floor($imp / $goal * 100)) : 0,
            ];
        })->values();
        $goal = (int) array_sum(array_column($c->lines, 'imps'));
        return compact('days', 'byDay', 'shown', 'totImp', 'totClk', 'perAd', 'placements', 'goal');
    }

    // ── 메일 ───────────────────────────────────

    /** 글자 메일. 실패하면 false(로그에 남김) — 콘솔에서 링크를 복사해 직접 보낼 수 있다 */
    public function mail(string $to, string $subject, array $lines): bool
    {
        try {
            Mail::raw(implode("\n", $lines) . "\n\n— STARTTOO 광고 · " . config('advertiser.ops_email'),
                fn ($m) => $m->to($to)->replyTo(config('advertiser.ops_email'))->subject($subject));
            return true;
        } catch (\Throwable $e) {
            Log::warning('advertiser mail failed', ['subject' => $subject, 'error' => $e->getMessage()]);
            return false;
        }
    }

    public function mailOps(string $subject, array $lines): void
    {
        $this->mail((string) config('advertiser.ops_email'), $subject, $lines);
    }
}
