<?php

namespace App\Services\Starttoo;

use App\Services\ProjectDatabase;
use Carbon\Carbon;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;

/**
 * 작가가 직접 신청하는 광고(6-05 ~ 6-09). 저장은 운영자 광고와 같은 starttoo_ads(product 가 있으면 작가 광고).
 *
 * 흐름: 신청(reviewing) → 운영 콘솔 「광고 · 프리미엄」에서 승인(running, 바로 노출) 또는 반려(rejected, 사유).
 * 결제(PG)는 Phase 6 에서 붙인다. 지금 금액은 「예상 광고비」 안내만 한다.
 * 노출 중 광고의 문구 · 작품 변경은 pending 에 두고, 승인되면 반영한다(그동안 기존 광고는 계속 나온다).
 */
class ArtistAds
{
    /** 상품: 광고 자리(slot) · 단위(day 하루 · month 한 달) · 기본 단가(부가세 별도) · 자리 수(0 = 제한 없음) */
    public const PRODUCTS = [
        'search' => ['slot' => 'search_top', 'name' => '검색 결과 상단', 'desc' => '고른 키워드를 검색하면 맨 위에 내 작품이 떠요',
            'unit' => 'day', 'price' => 10000, 'capacity' => 0, 'keywords' => true, 'badge' => null],
        'feed' => ['slot' => 'feed_inline', 'name' => '추천 피드 카드', 'desc' => '홈 추천 피드 게시물 사이에 작품 카드로 나와요',
            'unit' => 'day', 'price' => 15000, 'capacity' => 0, 'keywords' => false, 'badge' => '추천'],
        'region' => ['slot' => 'region_top', 'name' => '지역 탐색 상단', 'desc' => '내 상점 지역을 찾는 사람에게 먼저 보여요',
            'unit' => 'day', 'price' => 6000, 'capacity' => 0, 'keywords' => false, 'badge' => null],
        'carousel' => ['slot' => 'popular_carousel', 'name' => '인기 작가 캐러셀', 'desc' => '발견 화면 인기 작가 줄 첫 자리 · 한 달 단위',
            'unit' => 'month', 'price' => 300000, 'capacity' => 3, 'keywords' => false, 'badge' => null],
    ];
    public const DAYS = [7, 14, 30];
    public const EXTEND_DAYS = [7, 14];
    public const MAX_COPY = 24;
    public const MAX_KEYWORDS = 3;
    public const VAT_RATE = 0.1;
    /** 광고를 누른 뒤 이 기간 안에 상담을 요청하면 광고 성과로 센다 */
    public const LEAD_DAYS = 7;

    private function db() { return app(ProjectDatabase::class)->database(); }

    // ── 광고 안내 페이지(앱 「광고 안내」, 운영 콘솔에서 섹션별로 고친다) ─────────

    public const GUIDE_MAX_SECTIONS = 20;
    public const GUIDE_TITLE_MAX = 60;
    public const GUIDE_BODY_MAX = 2000;

    /** 저장된 안내가 없을 때의 기본 안내(지금 서버 동작과 같은 내용) */
    private const GUIDE_DEFAULT = [
        ['title' => '광고는 어디에 나오나요?', 'body' => "· 검색 결과 상단: 고른 키워드(최대 3개)를 검색하면 결과 맨 위에 내 작품 카드가 나와요.\n· 추천 피드 카드: 홈 추천 피드 게시물 사이에 작품 사진 카드로 나와요.\n· 지역 탐색 상단: 「발견 → 지역」에서 내 상점 지역을 고른 사람에게 목록 맨 위로 나와요.\n· 인기 작가 캐러셀: 발견 화면 「이번 주 인기 작가」 줄 첫 자리에 나와요(한 달 단위).\n모든 광고에는 ‘광고’ 표시가 붙어요."],
        ['title' => '심사는 어떻게 하나요?', 'body' => "심사 요청 후 보통 영업일 하루 안에 결과를 알림으로 알려드려요.\n가격 할인, 시술 효과를 보장하는 표현(예: 통증 없는 시술), 내 작품이 아닌 사진, 외부 연락처는 반려돼요.\n반려되면 사유를 확인하고 문구를 고쳐 다시 요청할 수 있어요."],
        ['title' => '언제부터 언제까지 나오나요?', 'body' => "· 하루 단위 상품: 승인되면 바로 노출되고, 고른 기간(7 · 14 · 30일)만큼 나와요.\n· 한 달 단위 상품: 승인한 다음 달 1일부터 말일까지 나와요.\n노출 중에는 「광고 노출」 스위치로 잠시 멈출 수 있고, 멈춘 기간만큼 종료일이 뒤로 밀려요.\n하루 단위 광고는 같은 조건으로 7 · 14일 기간을 늘릴 수 있어요. 종료한 광고는 다시 켤 수 없어요."],
        ['title' => '문구 · 작품을 바꾸고 싶어요', 'body' => "광고 성과 화면의 「문구 · 작품 바꾸기」로 바꿀 수 있어요. 바꾼 내용은 다시 심사하고, 심사하는 동안에도 기존 광고는 계속 나와요."],
        ['title' => '성과는 어떻게 세나요?', 'body' => "· 노출: 광고가 화면에 나온 횟수\n· 클릭: 광고를 누른 횟수\n· 상담 요청: 광고를 누른 회원이 7일 안에 나에게 새 상담을 요청한 수(한 상담에 한 번)\n광고 관리 화면에서 최근 7일 성과를, 광고 성과 화면에서 일별 클릭을 볼 수 있어요."],
        ['title' => '광고비는 얼마인가요?', 'body' => "광고 만들기 화면에 상품별 단가와 예상 광고비(부가세 별도)를 안내해요.\n광고비 결제 방법은 결제 기능을 열 때 따로 안내해드려요."],
    ];

    /** 앱 「광고 안내」 · 콘솔 편집용 섹션 목록 */
    public function guide(): array
    {
        $saved = app(Settings::class)->json('ad_guide');
        $sections = is_array($saved['sections'] ?? null) ? $saved['sections'] : self::GUIDE_DEFAULT;
        return ['sections' => array_values(array_map(fn ($s) => ['title' => (string) ($s['title'] ?? ''), 'body' => (string) ($s['body'] ?? '')], $sections)),
            'updated_at' => $saved['updated_at'] ?? null];
    }

    /** 콘솔 저장: 제목 · 본문이 모두 빈 섹션은 버리고, order(작은 수가 위) 순으로 둔다 */
    public function saveGuide(array $rows, int $adminId): array
    {
        $sections = [];
        foreach ($rows as $i => $row) {
            $title = trim((string) ($row['title'] ?? ''));
            $body = trim(str_replace("\r\n", "\n", (string) ($row['body'] ?? '')));
            if ($title === '' && $body === '') continue;
            if ($title === '' || $body === '') throw new \InvalidArgumentException('섹션마다 제목과 본문을 모두 적어주세요.');
            if (mb_strlen($title) > self::GUIDE_TITLE_MAX) throw new \InvalidArgumentException('제목은 '.self::GUIDE_TITLE_MAX.'자까지 적을 수 있어요.');
            if (mb_strlen($body) > self::GUIDE_BODY_MAX) throw new \InvalidArgumentException('본문은 '.self::GUIDE_BODY_MAX.'자까지 적을 수 있어요.');
            $sections[] = ['order' => (int) ($row['order'] ?? $i), 'i' => count($sections), 'title' => $title, 'body' => $body];
        }
        if (!$sections) throw new \InvalidArgumentException('안내 섹션을 1개 이상 적어주세요.');
        if (count($sections) > self::GUIDE_MAX_SECTIONS) throw new \InvalidArgumentException('섹션은 '.self::GUIDE_MAX_SECTIONS.'개까지 둘 수 있어요.');
        usort($sections, fn ($a, $b) => [$a['order'], $a['i']] <=> [$b['order'], $b['i']]);
        $value = ['sections' => array_map(fn ($s) => ['title' => $s['title'], 'body' => $s['body']], $sections), 'updated_at' => now()->toIso8601String()];
        app(Settings::class)->set('ad_guide', $value, $adminId);
        return $value;
    }

    // ── 상품(운영 콘솔에서 단가 · 자리 수 · 신청 받기를 바꾼다) ─────────

    /** 상품 설정: 저장값(starttoo_settings.ad_products)이 없으면 기본값 */
    public function config(): array
    {
        $saved = app(Settings::class)->json('ad_products') ?? [];
        $out = [];
        foreach (self::PRODUCTS as $id => $p) {
            $s = $saved[$id] ?? [];
            $out[$id] = $p + [
                'on' => (bool) ($s['on'] ?? true),
            ];
            $out[$id]['price'] = max(0, (int) ($s['price'] ?? $p['price']));
            $out[$id]['capacity'] = max(0, (int) ($s['capacity'] ?? $p['capacity']));
        }
        return $out;
    }

    public function saveConfig(array $in, int $adminId): array
    {
        $value = [];
        foreach (self::PRODUCTS as $id => $p) {
            $s = $in[$id] ?? [];
            $value[$id] = ['on' => (bool) ($s['on'] ?? false), 'price' => max(0, (int) ($s['price'] ?? $p['price'])),
                'capacity' => max(0, (int) ($s['capacity'] ?? $p['capacity']))];
        }
        app(Settings::class)->set('ad_products', $value, $adminId);
        return $value;
    }

    /** 한 달 상품이 노출되는 달: 승인한 날의 다음 달 1일 ~ 말일 */
    private function nextMonth(?Carbon $from = null): array
    {
        $start = ($from ?? now())->copy()->addMonthNoOverflow()->startOfMonth();
        return [$start, $start->copy()->endOfMonth()];
    }

    /** 자리가 없으면 그 이유(「12월 자리 없음」), 있으면 null */
    private function soldOut(string $id, array $p): ?string
    {
        if (!$p['on']) return '잠시 신청을 받지 않아요';
        if ($p['capacity'] <= 0) return null;
        $q = $this->db()->table('starttoo_ads')->where('product', $id)->where('status', 'running');
        if ($p['unit'] === 'month') {
            [$start] = $this->nextMonth();
            $taken = (clone $q)->where('starts_at', $start)->count();
            return $taken >= $p['capacity'] ? $start->month.'월 자리 없음' : null;
        }
        $taken = $q->where(fn ($w) => $w->whereNull('ends_at')->orWhere('ends_at', '>', now()))->count();
        return $taken >= $p['capacity'] ? '지금 자리 없음' : null;
    }

    /** 최근 14일 이 자리 광고 한 건의 하루 평균 노출(실제 기록이 있을 때만) */
    private function averageReach(string $slot): ?int
    {
        $row = $this->db()->table('starttoo_ad_daily as d')->join('starttoo_ads as a', 'a.id', '=', 'd.ad_id')
            ->where('a.slot', $slot)->where('d.day', '>=', now()->subDays(14)->toDateString())
            ->selectRaw('SUM(d.impressions) as imp, COUNT(*) as n')->first();
        return $row && (int) $row->n > 0 ? (int) round($row->imp / $row->n) : null;
    }

    /** 6-06 상품 목록 + 6-07 고를 작품 · 키워드 */
    public function products(int $artist): array
    {
        $items = [];
        foreach ($this->config() as $id => $p) {
            $reach = $this->averageReach($p['slot']);
            $sold = $this->soldOut($id, $p);
            [$monthStart] = $this->nextMonth();
            $items[] = [
                'id' => $id, 'name' => $p['name'], 'desc' => $p['desc'], 'unit' => $p['unit'], 'unit_price' => $p['price'],
                'uses_keywords' => $p['keywords'], 'badge' => $p['badge'], 'sold_out' => $sold,
                // 기록이 있을 때만 실제 평균을 보여 준다(예상치를 지어내지 않는다)
                'reach' => $reach !== null ? '최근 하루 평균 노출 '.number_format($reach) : null,
                'month_label' => $p['unit'] === 'month' ? $monthStart->month.'월 한 달' : null,
            ];
        }
        $reviews = app(Reviews::class)->summary($artist, $artist);
        $consult = $this->db()->table('starttoo_shops')->where('user_id', $artist)->value('consult_status');
        return ['items' => $items, 'days' => self::DAYS, 'max_copy' => self::MAX_COPY, 'max_keywords' => self::MAX_KEYWORDS,
            'vat_rate' => self::VAT_RATE, 'works' => $this->works($artist), 'keywords' => $this->keywordOptions($artist),
            // 6-08 미리보기 카드: 광고에 나갈 상점 이름 · 평점 · 상담 가능
            'shop' => ['name' => $this->advertiser($artist), 'rating' => $reviews['average'], 'review_count' => $reviews['total'],
                'avatar_url' => app(MediaUrls::class)->url($this->db()->table('starttoo_users')->where('id', $artist)->value('avatar_path'), 300),
                'consult_open' => $consult === 'open']];
    }

    /** 광고할 수 있는 작품: 공개 · 숨김 아님 · 사진 있음(최근 40개) */
    private function works(int $artist): array
    {
        $rows = $this->db()->table('starttoo_artworks')->where('user_id', $artist)->where('visibility', 'public')
            ->where('moderation_hidden', false)->orderByDesc('created_at')->limit(40)->get(['id', 'title']);
        $images = $this->firstImages($rows->pluck('id')->all());
        $out = [];
        foreach ($rows as $r) {
            if (!isset($images[$r->id])) continue;
            $out[] = ['id' => $r->id, 'title' => $r->title, 'image_url' => app(MediaUrls::class)->url($images[$r->id], 300)];
        }
        return $out;
    }

    private function firstImages(array $ids): array
    {
        if (!$ids) return [];
        $out = [];
        foreach ($this->db()->table('starttoo_artwork_images')->whereIn('artwork_id', $ids)->orderBy('position')->get(['artwork_id', 'path']) as $row) {
            $out[$row->artwork_id] ??= $row->path;
        }
        return $out;
    }

    /** 검색 키워드 후보: 내 작품의 해시태그 · 스타일 + 상점 지역 */
    private function keywordOptions(int $artist): array
    {
        $count = [];
        $styles = collect(app(Profiles::class)->options()['styles'])->pluck('label', 'id');
        foreach ($this->db()->table('starttoo_artworks')->where('user_id', $artist)->where('visibility', 'public')
            ->orderByDesc('created_at')->limit(100)->get(['tags', 'styles']) as $r) {
            foreach (json_decode((string) $r->tags, true) ?: [] as $t) $count[$t] = ($count[$t] ?? 0) + 2;
            foreach (json_decode((string) $r->styles, true) ?: [] as $s) if (isset($styles[$s])) $count[$styles[$s]] = ($count[$styles[$s]] ?? 0) + 1;
        }
        arsort($count);
        $out = array_slice(array_keys($count), 0, 8);
        $region = $this->db()->table('starttoo_shops')->where('user_id', $artist)->value('region');
        $label = $region ? (collect(app(Profiles::class)->options()['regions'])->pluck('label', 'id')[$region] ?? null) : null;
        if ($label) $out[] = $label.' 타투';
        return array_values(array_unique(array_map(fn ($k) => mb_substr((string) $k, 0, 20), $out)));
    }

    // ── 작가: 목록 · 상세 ─────────────────────────────

    /** 화면에 보일 상태: reviewing · rejected · scheduled 노출 예정 · running · paused · ended */
    public function state(object $ad): string
    {
        if ($ad->status === 'reviewing' || $ad->status === 'rejected') return $ad->status;
        if ($ad->status === 'ended' || ($ad->ends_at && now()->gte($ad->ends_at))) return 'ended';
        if ($ad->paused_at) return 'paused';
        if ($ad->starts_at && now()->lt($ad->starts_at)) return 'scheduled';
        return 'running';
    }

    /** 6-05 광고 관리: 내 광고 + 최근 7일 성과 */
    public function list(int $artist): array
    {
        $rows = $this->db()->table('starttoo_ads')->where('artist_id', $artist)->whereNotNull('product')->orderByDesc('id')->limit(100)->get();
        $since = now()->subDays(6)->toDateString();
        $week = $rows->isEmpty() ? null : $this->db()->table('starttoo_ad_daily')->whereIn('ad_id', $rows->pluck('id'))->where('day', '>=', $since)
            ->selectRaw('COALESCE(SUM(impressions),0) as imp, COALESCE(SUM(clicks),0) as clk, COALESCE(SUM(leads),0) as leads')->first();
        $images = $this->firstImages($rows->pluck('artwork_id')->filter()->unique()->all());
        return [
            'items' => $rows->map(fn ($ad) => $this->card($ad, $images))->values()->all(),
            'week' => ['impressions' => (int) ($week->imp ?? 0), 'clicks' => (int) ($week->clk ?? 0), 'leads' => (int) ($week->leads ?? 0)],
            // 2-14 대시보드 광고 칸 주홍 점
            'running' => $rows->contains(fn ($ad) => $this->state($ad) === 'running'),
        ];
    }

    private function card(object $ad, array $images): array
    {
        $p = self::PRODUCTS[$ad->product] ?? null;
        $state = $this->state($ad);
        $keywords = json_decode((string) $ad->keywords, true) ?: [];
        $start = $ad->starts_at ? Carbon::parse($ad->starts_at) : null;
        $end = $ad->ends_at ? Carbon::parse($ad->ends_at) : null;
        $total = $start && $end ? max(1, (int) ceil($start->diffInDays($end, true))) : null;
        $elapsed = $start && $end ? min($total, max(0, (int) ceil($start->diffInDays(min(now(), $end), true)))) : 0;
        return [
            'id' => (int) $ad->id, 'product' => $ad->product, 'product_name' => $p['name'] ?? $ad->product, 'state' => $state,
            'title' => $ad->title, 'keywords' => $keywords, 'days' => $ad->days === null ? null : (int) $ad->days,
            'amount' => $ad->amount === null ? null : (int) $ad->amount, 'unit' => $p['unit'] ?? 'day', 'artwork_id' => $ad->artwork_id,
            'image_url' => $ad->artwork_id && isset($images[$ad->artwork_id]) ? app(MediaUrls::class)->url($images[$ad->artwork_id], 300) : null,
            'starts_at' => $start?->toIso8601String(), 'ends_at' => $end?->toIso8601String(),
            'requested_at' => $ad->requested_at ? Carbon::parse($ad->requested_at)->toIso8601String() : null,
            'total_days' => $total, 'elapsed_days' => $elapsed,
            'impressions' => (int) $ad->impressions, 'clicks' => (int) $ad->clicks, 'leads' => (int) $ad->leads,
            'reject_reason' => $state === 'rejected' ? $ad->reject_reason : null,
            'pending' => $ad->pending ? true : false, 'pending_reject_reason' => $ad->pending_reject_reason,
        ];
    }

    /** 6-09 광고 성과: 카드 + 최근 7일 일별 노출 · 클릭 · 상담 요청 */
    public function detail(int $artist, int $id): array
    {
        $ad = $this->mine($artist, $id);
        $images = $this->firstImages(array_filter([$ad->artwork_id]));
        $daily = $this->db()->table('starttoo_ad_daily')->where('ad_id', $id)->where('day', '>=', now()->subDays(6)->toDateString())
            ->get()->keyBy(fn ($r) => Carbon::parse($r->day)->toDateString());
        $days = [];
        for ($i = 6; $i >= 0; $i--) {
            $d = now()->subDays($i)->toDateString();
            $r = $daily[$d] ?? null;
            $days[] = ['day' => $d, 'impressions' => (int) ($r->impressions ?? 0), 'clicks' => (int) ($r->clicks ?? 0), 'leads' => (int) ($r->leads ?? 0)];
        }
        $p = $this->config()[$ad->product] ?? null;
        return ['ad' => $this->card($ad, $images), 'daily' => $days, 'extend_days' => ($p['unit'] ?? '') === 'day' ? self::EXTEND_DAYS : [],
            'unit_price' => $p['price'] ?? 0];
    }

    private function mine(int $artist, int $id): object
    {
        $ad = $this->db()->table('starttoo_ads')->where('id', $id)->where('artist_id', $artist)->whereNotNull('product')->first();
        if (!$ad) $this->fail(404, 'AD_NOT_FOUND', '광고를 찾을 수 없어요.');
        return $ad;
    }

    // ── 작가: 신청 · 변경 ─────────────────────────────

    /** 6-08 심사 요청. 같은 상품의 심사 중 · 노출 중 광고가 있으면 받지 않는다. */
    public function create(int $artist, array $input): array
    {
        $config = $this->config();
        $id = (string) ($input['product'] ?? '');
        $p = $config[$id] ?? null;
        if (!$p) $this->fail(422, 'INVALID_AD', '광고 위치를 골라주세요.');
        if ($sold = $this->soldOut($id, $p)) $this->fail(409, 'AD_SOLD_OUT', $sold === '잠시 신청을 받지 않아요' ? '지금은 이 위치 광고를 신청할 수 없어요.' : "{$sold}이에요. 다른 위치를 골라주세요.");
        $values = $this->content($artist, $p, $input);
        $exists = $this->db()->table('starttoo_ads')->where('artist_id', $artist)->where('product', $id)
            ->where(fn ($q) => $q->where('status', 'reviewing')->orWhere(fn ($r) => $r->where('status', 'running')
                ->where(fn ($e) => $e->whereNull('ends_at')->orWhere('ends_at', '>', now()))))->exists();
        if ($exists) $this->fail(409, 'AD_EXISTS', '이 위치에 심사 중이거나 노출 중인 광고가 있어요.');
        $days = $p['unit'] === 'day' ? $this->days($input['days'] ?? null) : null;
        $adId = $this->db()->table('starttoo_ads')->insertGetId($values + [
            'slot' => $p['slot'], 'product' => $id, 'status' => 'reviewing', 'advertiser' => $this->advertiser($artist),
            'artist_id' => $artist, 'link' => null, 'image_path' => null, 'active' => false,
            'days' => $days, 'amount' => $p['unit'] === 'day' ? $p['price'] * $days : $p['price'],
            'requested_at' => now(), 'created_at' => now(), 'updated_at' => now(),
        ]);
        return $this->detail($artist, $adId);
    }

    /** 반려된 광고를 고쳐 다시 요청 · 노출 중 광고의 문구 · 작품 바꾸기(다시 심사) */
    public function edit(int $artist, int $id, array $input): array
    {
        $ad = $this->mine($artist, $id);
        $p = $this->config()[$ad->product];
        $values = $this->content($artist, $p, $input);
        $state = $this->state($ad);
        if ($state === 'rejected') {
            $days = $p['unit'] === 'day' ? $this->days($input['days'] ?? $ad->days) : null;
            $this->db()->table('starttoo_ads')->where('id', $id)->update($values + ['status' => 'reviewing', 'reject_reason' => null,
                'days' => $days, 'amount' => $p['unit'] === 'day' ? $p['price'] * $days : $p['price'],
                'requested_at' => now(), 'updated_at' => now()]);
        } elseif (in_array($state, ['running', 'paused', 'scheduled'], true)) {
            $this->db()->table('starttoo_ads')->where('id', $id)->update(['pending' => json_encode([
                'title' => $values['title'], 'artwork_id' => $values['artwork_id'], 'keywords' => json_decode((string) $values['keywords'], true),
            ], JSON_UNESCAPED_UNICODE), 'pending_reject_reason' => null, 'requested_at' => now(), 'updated_at' => now()]);
        } else {
            $this->fail(409, 'AD_STATE', $state === 'reviewing' ? '심사 중인 광고는 결과가 나온 뒤 고칠 수 있어요.' : '종료된 광고는 고칠 수 없어요.');
        }
        return $this->detail($artist, $id);
    }

    /** 문구 · 작품 · 키워드 검사 → 저장 값 */
    private function content(int $artist, array $p, array $input): array
    {
        $v = Validator::make($input, [
            'artwork_id' => ['required', 'uuid'],
            'copy' => ['required', 'string', 'max:'.self::MAX_COPY],
            'keywords' => ['array', 'max:'.self::MAX_KEYWORDS],
            'keywords.*' => ['string', 'min:1', 'max:20'],
        ]);
        if ($v->fails()) $this->fail(422, 'INVALID_AD', '광고 내용을 확인해주세요.');
        $copy = trim((string) $input['copy']);
        if ($copy === '') $this->fail(422, 'INVALID_AD', '한 줄 문구를 적어주세요.');
        $keywords = array_values(array_unique(array_filter(array_map(fn ($k) => trim((string) $k), $input['keywords'] ?? []))));
        if ($p['keywords'] && !$keywords) $this->fail(422, 'INVALID_AD', '검색 키워드를 1개 이상 골라주세요.');
        $art = $this->db()->table('starttoo_artworks')->where('id', $input['artwork_id'])->where('user_id', $artist)
            ->where('visibility', 'public')->where('moderation_hidden', false)->exists();
        if (!$art || !$this->firstImages([$input['artwork_id']])) $this->fail(422, 'INVALID_AD', '공개된 내 작품을 골라주세요.');
        return ['title' => $copy, 'artwork_id' => $input['artwork_id'],
            'keywords' => $p['keywords'] ? json_encode($keywords, JSON_UNESCAPED_UNICODE) : null];
    }

    private function days(mixed $days): int
    {
        if (!in_array((int) $days, self::DAYS, true)) $this->fail(422, 'INVALID_AD', '기간을 골라주세요.');
        return (int) $days;
    }

    private function advertiser(int $artist): string
    {
        $shop = $this->db()->table('starttoo_shops')->where('user_id', $artist)->value('studio_name');
        $name = $shop ?: $this->db()->table('starttoo_users')->where('id', $artist)->value('nickname');
        return mb_substr((string) $name, 0, 60) ?: '작가';
    }

    /** 노출 잠시 멈춤 · 다시 켜기. 멈춘 기간만큼 종료일을 뒤로 민다. */
    public function pause(int $artist, int $id, bool $paused): array
    {
        $ad = $this->mine($artist, $id);
        $state = $this->state($ad);
        if (!in_array($state, ['running', 'paused', 'scheduled'], true)) $this->fail(409, 'AD_STATE', '노출 중인 광고만 멈추거나 다시 켤 수 있어요.');
        if ($paused && !$ad->paused_at) {
            $this->db()->table('starttoo_ads')->where('id', $id)->update(['paused_at' => now(), 'active' => false, 'updated_at' => now()]);
        } elseif (!$paused && $ad->paused_at) {
            $gap = (int) Carbon::parse($ad->paused_at)->diffInSeconds(now(), true);
            $this->db()->table('starttoo_ads')->where('id', $id)->update(['paused_at' => null, 'active' => true,
                'ends_at' => $ad->ends_at ? Carbon::parse($ad->ends_at)->addSeconds($gap) : null, 'updated_at' => now()]);
        }
        return $this->detail($artist, $id);
    }

    /** 기간 늘리기(하루 단위 상품, 같은 조건). 예상 광고비도 늘어난다. */
    public function extend(int $artist, int $id, mixed $days): array
    {
        $ad = $this->mine($artist, $id);
        $p = $this->config()[$ad->product];
        if ($p['unit'] !== 'day' || !in_array($this->state($ad), ['running', 'paused', 'scheduled'], true)) {
            $this->fail(409, 'AD_STATE', '노출 중인 하루 단위 광고만 기간을 늘릴 수 있어요.');
        }
        if (!in_array((int) $days, self::EXTEND_DAYS, true)) $this->fail(422, 'INVALID_AD', '늘릴 기간을 골라주세요.');
        $this->db()->table('starttoo_ads')->where('id', $id)->update([
            'ends_at' => Carbon::parse($ad->ends_at)->addDays((int) $days), 'days' => (int) $ad->days + (int) $days,
            'amount' => (int) $ad->amount + $p['price'] * (int) $days, 'updated_at' => now(),
        ]);
        return $this->detail($artist, $id);
    }

    /** 광고 종료(다시 켤 수 없다). 심사 중이면 신청 취소. */
    public function end(int $artist, int $id): array
    {
        $ad = $this->mine($artist, $id);
        if ($this->state($ad) === 'ended') $this->fail(409, 'AD_STATE', '이미 종료된 광고예요.');
        $this->db()->table('starttoo_ads')->where('id', $id)->update(['status' => 'ended', 'active' => false, 'pending' => null,
            'ends_at' => $ad->starts_at && now()->gte($ad->starts_at) ? now() : $ad->ends_at, 'paused_at' => null, 'updated_at' => now()]);
        return $this->detail($artist, $id);
    }

    // ── 운영 콘솔: 심사 ─────────────────────────────

    /** 승인: 하루 단위는 지금부터 N일, 한 달 단위는 다음 달 1일 ~ 말일. 노출 중 광고의 변경 요청이면 내용만 반영한다. */
    public function approve(int $id, int $adminId): object
    {
        $ad = $this->db()->table('starttoo_ads')->where('id', $id)->whereNotNull('product')->first();
        if (!$ad) throw new \InvalidArgumentException('작가 광고를 찾을 수 없어요.');
        if ($ad->pending) {
            $p = json_decode((string) $ad->pending, true) ?: [];
            $this->db()->table('starttoo_ads')->where('id', $id)->update(['title' => $p['title'] ?? $ad->title,
                'artwork_id' => $p['artwork_id'] ?? $ad->artwork_id,
                'keywords' => array_key_exists('keywords', $p) && $p['keywords'] !== null ? json_encode($p['keywords'], JSON_UNESCAPED_UNICODE) : $ad->keywords,
                'pending' => null, 'pending_reject_reason' => null, 'reviewed_at' => now(), 'reviewed_by' => $adminId, 'updated_at' => now()]);
            $this->notify($ad, 'changed');
            return $this->db()->table('starttoo_ads')->where('id', $id)->first();
        }
        if ($ad->status !== 'reviewing') throw new \InvalidArgumentException('심사 중인 광고가 아니에요.');
        $p = $this->config()[$ad->product];
        if ($p['unit'] === 'month') {
            [$start, $end] = $this->nextMonth();
            $taken = $this->db()->table('starttoo_ads')->where('product', $ad->product)->where('status', 'running')->where('starts_at', $start)->count();
            if ($p['capacity'] > 0 && $taken >= $p['capacity']) throw new \InvalidArgumentException($start->month.'월 자리가 다 찼어요. 반려하거나 자리 수를 늘려주세요.');
        } else {
            $start = now();
            $end = now()->addDays((int) $ad->days);
        }
        $this->db()->table('starttoo_ads')->where('id', $id)->update(['status' => 'running', 'active' => true,
            'starts_at' => $start, 'ends_at' => $end, 'reject_reason' => null, 'reviewed_at' => now(), 'reviewed_by' => $adminId, 'updated_at' => now()]);
        $this->notify($ad, 'approved');
        return $this->db()->table('starttoo_ads')->where('id', $id)->first();
    }

    public function reject(int $id, string $reason, int $adminId): object
    {
        $reason = trim($reason);
        if ($reason === '' || mb_strlen($reason) > 300) throw new \InvalidArgumentException('반려 사유를 1~300자로 적어주세요.');
        $ad = $this->db()->table('starttoo_ads')->where('id', $id)->whereNotNull('product')->first();
        if (!$ad) throw new \InvalidArgumentException('작가 광고를 찾을 수 없어요.');
        if ($ad->pending) {
            $this->db()->table('starttoo_ads')->where('id', $id)->update(['pending' => null, 'pending_reject_reason' => $reason,
                'reviewed_at' => now(), 'reviewed_by' => $adminId, 'updated_at' => now()]);
        } elseif ($ad->status === 'reviewing') {
            $this->db()->table('starttoo_ads')->where('id', $id)->update(['status' => 'rejected', 'active' => false, 'reject_reason' => $reason,
                'reviewed_at' => now(), 'reviewed_by' => $adminId, 'updated_at' => now()]);
        } else {
            throw new \InvalidArgumentException('심사할 내용이 없어요.');
        }
        $this->notify($ad, 'rejected');
        return $this->db()->table('starttoo_ads')->where('id', $id)->first();
    }

    /** 심사 결과 안내 알림(variant = approved · rejected · changed, 대상 = 광고 번호) */
    private function notify(object $ad, string $variant): void
    {
        try {
            app(Notifications::class)->notice((int) $ad->artist_id, 'ad_review', 'ad', (string) $ad->id, $variant);
        } catch (\Throwable $e) {
            report($e);
        }
    }

    /** 운영 콘솔 심사 대기 목록(신청 · 변경 요청) */
    public function queue(): array
    {
        $rows = $this->db()->table('starttoo_ads as ad')->leftJoin('starttoo_users as u', 'u.id', '=', 'ad.artist_id')
            ->whereNotNull('ad.product')->where(fn ($q) => $q->where('ad.status', 'reviewing')->orWhereNotNull('ad.pending'))
            ->orderBy('ad.requested_at')->limit(100)->get(['ad.*', 'u.nickname']);
        $artworks = [];
        foreach ($rows as $r) {
            $pending = $r->pending ? json_decode((string) $r->pending, true) : null;
            $artworks[] = $pending['artwork_id'] ?? $r->artwork_id;
        }
        $images = $this->firstImages(array_values(array_filter(array_unique($artworks))));
        return $rows->map(function ($r) use ($images) {
            $pending = $r->pending ? json_decode((string) $r->pending, true) : null;
            $art = $pending['artwork_id'] ?? $r->artwork_id;
            $p = self::PRODUCTS[$r->product] ?? ['name' => $r->product, 'unit' => 'day'];
            return [
                'id' => (int) $r->id, 'artist_id' => (int) $r->artist_id, 'artist' => $r->nickname, 'advertiser' => $r->advertiser,
                'product' => $p['name'], 'kind' => $pending ? 'change' : 'new',
                'title' => $pending['title'] ?? $r->title, 'before' => $pending ? $r->title : null,
                'keywords' => $pending['keywords'] ?? (json_decode((string) $r->keywords, true) ?: []),
                'period' => $p['unit'] === 'month' ? $this->nextMonth()[0]->month.'월 한 달' : ((int) $r->days).'일',
                'amount' => (int) $r->amount, 'artwork_id' => $art,
                'image' => $art && isset($images[$art]) ? app(MediaUrls::class)->url($images[$art], 300) : null,
                'requested_at' => $r->requested_at,
            ];
        })->all();
    }

    // ── 노출 · 성과 기록(Ads 에서 부른다) ─────────────────

    public function count(int $adId, string $field, int $by = 1): void
    {
        $day = now()->toDateString();
        $this->db()->table('starttoo_ad_daily')->insertOrIgnore(['ad_id' => $adId, 'day' => $day]);
        $this->db()->table('starttoo_ad_daily')->where('ad_id', $adId)->where('day', $day)->increment($field, $by);
    }

    /** 작가 광고를 누른 회원 기록(상담 요청 집계용) */
    public function clicked(object $ad, int $viewer): void
    {
        if (!$ad->product || !$ad->artist_id || $viewer === (int) $ad->artist_id) return;
        $this->db()->table('starttoo_ad_clicks')->upsert(
            [['ad_id' => $ad->id, 'user_id' => $viewer, 'artist_id' => $ad->artist_id, 'clicked_at' => now()]],
            ['ad_id', 'user_id'], ['clicked_at']);
    }

    /** 새 상담 건이 생겼다: 7일 안에 그 작가 광고를 눌렀으면 그 광고의 상담 요청으로 한 번 센다. */
    public function lead(int $customer, int $artist, int $caseId): void
    {
        $click = $this->db()->table('starttoo_ad_clicks')->where('user_id', $customer)->where('artist_id', $artist)
            ->whereNull('lead_case_id')->where('clicked_at', '>=', now()->subDays(self::LEAD_DAYS))->orderByDesc('clicked_at')->first();
        if (!$click) return;
        $done = $this->db()->table('starttoo_ad_clicks')->where('ad_id', $click->ad_id)->where('user_id', $customer)->whereNull('lead_case_id')
            ->update(['lead_case_id' => $caseId]);
        if (!$done) return;
        $this->db()->table('starttoo_ads')->where('id', $click->ad_id)->increment('leads');
        $this->count((int) $click->ad_id, 'leads');
    }

    /** 노출에 쓸 작가 광고 정보(작품 사진 · 상점 평점 · 상담 가능) */
    public function serveExtras(object $ad, ?int $viewer): array
    {
        $image = $ad->artwork_id ? ($this->firstImages([$ad->artwork_id])[$ad->artwork_id] ?? null) : null;
        $shop = $this->db()->table('starttoo_shops')->where('user_id', $ad->artist_id)->first(['consult_status', 'region']);
        $reviews = app(Reviews::class)->summary((int) $ad->artist_id, $viewer);
        return ['image_path' => $image, 'artwork_id' => $ad->artwork_id, 'rating' => $reviews['average'], 'review_count' => $reviews['total'],
            'consult_open' => ($shop->consult_status ?? null) === 'open', 'region' => $shop->region ?? null];
    }

    private function fail(int $status, string $code, string $message): never
    {
        throw new HttpResponseException(response()->json(compact('code', 'message'), $status));
    }
}
