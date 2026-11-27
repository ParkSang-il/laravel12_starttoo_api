<?php

namespace App\Services\Starttoo;

use App\Services\ProjectDatabase;
use Carbon\Carbon;
use Illuminate\Http\Exceptions\HttpResponseException;

/**
 * 자체 광고. 운영자가 서버 명령(starttoo:ad)으로 등록 · 끄기 · 조회한다. 관리자 웹은 이후 단계.
 * - 자리(slot)마다 지금 노출 중인 광고 가운데 하나를 무작위로 준다.
 * - 누르면 작가 상점(artist_id) 또는 앱 안 화면(link)으로 간다.
 * - 노출 수 · 누른 수를 센다.
 */
class Ads
{
    /**
     * community_top 커뮤니티 피드 맨 위 카드 · feed_inline 홈(추천) 피드 게시물 사이(사진 카드라 이미지 필수)
     * 작가 광고 전용(ArtistAds): search_top 검색 결과 상단(키워드) · region_top 지역 탐색 상단(상점 지역)
     * · popular_carousel 발견 인기 작가 줄 첫 자리
     * moving_inline 무빙 피드(VID-06) 무빙 사이 전체 화면 한 칸(세로 이미지 필수)
     */
    public const SLOTS = ['community_top', 'feed_inline', 'search_top', 'region_top', 'popular_carousel', 'moving_inline'];
    /** 운영자가 직접 등록하는 자리(운영 콘솔 · 외부 광고 계약) */
    public const ADMIN_SLOTS = ['community_top', 'feed_inline', 'moving_inline'];
    /** 구글 광고(AdMob)를 섞는 자리 — 무빙 전체 화면용 네이티브 화면은 아직 없어 자체 광고만 쓴다 */
    public const ADMOB_SLOTS = ['community_top', 'feed_inline'];
    private const IMAGE_REQUIRED = ['feed_inline', 'search_top', 'region_top', 'moving_inline'];

    private function db() { return app(ProjectDatabase::class)->database(); }

    /**
     * 구글 광고(AdMob) 설정(운영 콘솔 「광고 · 프리미엄」): 전체 켜기 + 자리별 켜기 · 광고 단위 ID(Android · iOS)
     * 자체 광고가 없을 때 그 자리에 쓸 광고 단위를 앱에 알려 준다. 앱 ID 는 앱 빌드에 들어 있어 여기서 바꾸지 않는다.
     */
    public const ADMOB_UNIT = '/^ca-app-pub-\d{16}\/\d{10}$/';

    public function admobConfig(): array
    {
        $saved = app(Settings::class)->json('admob') ?? [];
        $slots = [];
        foreach (self::ADMOB_SLOTS as $slot) {
            $s = $saved['slots'][$slot] ?? [];
            $slots[$slot] = ['on' => (bool) ($s['on'] ?? false), 'android' => (string) ($s['android'] ?? ''), 'ios' => (string) ($s['ios'] ?? ''),
                // 구글 광고 비율(%): 자체 광고가 있어도 이 비율만큼은 구글 광고를 보여 준다(자체 광고가 없으면 항상 구글)
                'share' => max(0, min(100, (int) ($s['share'] ?? 50)))];
        }
        return ['enabled' => (bool) ($saved['enabled'] ?? false), 'slots' => $slots];
    }

    public function admobUnit(string $slot, ?string $platform): ?string
    {
        $platform = strtolower((string) $platform);
        if (!in_array($platform, ['android', 'ios'], true)) return null;
        $c = $this->admobConfig();
        $s = $c['slots'][$slot] ?? null;
        if (!$c['enabled'] || !$s || !$s['on']) return null;
        return preg_match(self::ADMOB_UNIT, $s[$platform]) ? $s[$platform] : null;
    }

    /**
     * 앱: 이 자리에 보여 줄 자체 광고 1건(ad) 또는 구글 광고 단위(admob_unit). 둘 다 없으면 둘 다 null.
     * 구글 광고를 켠 자리는 「구글 광고 비율」만큼 무작위로 구글 광고, 나머지는 자체 광고(없으면 구글)로 섞는다.
     * $context: search_top 은 q(검색어), region_top 은 region(지역 코드)으로 맞는 광고만 고른다.
     */
    public function pick(string $slot, int $viewer, ?string $platform = null, array $context = []): array
    {
        $unit = in_array($slot, self::ADMOB_SLOTS, true) ? $this->admobUnit($slot, $platform) : null;
        // 구글 광고 차례면 자체 광고를 고르지 않는다(자체 광고 노출 수도 세지 않는다)
        if ($unit !== null && random_int(1, 100) <= $this->admobConfig()['slots'][$slot]['share']) {
            return ['ad' => null, 'admob_unit' => $unit];
        }
        $result = $this->pickOwn($slot, $viewer, $context);
        $result['admob_unit'] = $result['ad'] === null ? $unit : null;
        return $result;
    }

    /** 검색어 · 키워드 비교: 공백 · # 빼고 소문자 */
    private static function norm(string $v): string
    {
        return mb_strtolower(preg_replace('/[\s#]+/u', '', $v) ?? '', 'UTF-8');
    }

    private function pickOwn(string $slot, int $viewer, array $context): array
    {
        if (!in_array($slot, self::SLOTS, true)) $this->fail(422, 'INVALID_AD', '광고 자리를 확인해주세요.');
        $q = self::norm((string) ($context['q'] ?? ''));
        $region = (string) ($context['region'] ?? '');
        // 검색 · 지역 자리는 맞는 조건이 없으면 광고를 고르지 않는다
        if (($slot === 'search_top' && $q === '') || ($slot === 'region_top' && $region === '')) return ['ad' => null];
        // 같은 피드에서 이 회원에게 이미 보여 준 광고(앱이 보낸 exclude)는 다시 고르지 않는다
        $exclude = array_values(array_filter(array_map('intval', (array) ($context['exclude'] ?? [])), fn ($id) => $id > 0));
        $rows = $this->db()->table('starttoo_ads')->where('slot', $slot)->where('active', true)
            ->where(fn ($w) => $w->whereNull('status')->orWhere('status', 'running'))->whereNull('paused_at')
            ->where(fn ($w) => $w->whereNull('starts_at')->orWhere('starts_at', '<=', now()))
            ->where(fn ($w) => $w->whereNull('ends_at')->orWhere('ends_at', '>', now()))
            // 외부 광고 계약(CPM): 보장 노출을 채우면 그만 보여 준다
            ->where(fn ($w) => $w->whereNull('impression_cap')->orWhereColumn('impressions', '<', 'impression_cap'))
            ->when($exclude, fn ($w) => $w->whereNotIn('id', $exclude))
            ->inRandomOrder()->limit(in_array($slot, ['search_top', 'region_top'], true) ? 50 : 10)->get();
        foreach ($rows as $ad) {
            $u = null;
            if ($ad->artist_id) {
                // 광고주 작가가 탈퇴 · 숨김이거나 차단 관계면 보여 주지 않는다
                $u = $this->db()->table('starttoo_users')->where('id', $ad->artist_id)->first(['status', 'moderation_hidden', 'avatar_path']);
                if (!$u || $u->status !== 'active' || $u->moderation_hidden || app(Blocks::class)->between($viewer, (int) $ad->artist_id)) continue;
            }
            // 작가 광고: 작품 사진 · 평점 · 상담 가능 여부(6-08 카드)
            $extra = $ad->product ? app(ArtistAds::class)->serveExtras($ad, $viewer) : null;
            if ($slot === 'search_top') {
                $keywords = array_map(fn ($k) => self::norm((string) $k), json_decode((string) $ad->keywords, true) ?: []);
                $hit = false;
                foreach ($keywords as $k) if ($k !== '' && (str_contains($q, $k) || str_contains($k, $q))) $hit = true;
                if (!$hit) continue;
            }
            if ($slot === 'region_top' && ($extra['region'] ?? null) !== $region) continue;
            $image = $ad->image_path ?: ($extra['image_path'] ?? null);
            // 이미지가 꼭 필요한 자리(홈 피드 · 검색 · 지역)인데 이미지가 없으면 건너뛴다
            if (!$image && in_array($slot, self::IMAGE_REQUIRED, true)) continue;
            $this->db()->table('starttoo_ads')->where('id', $ad->id)->increment('impressions');
            app(ArtistAds::class)->count((int) $ad->id, 'impressions');
            // 신규 노출: 이 회원이 이 광고를 처음 볼 때만 +1(이미 본 회원은 세지 않는다)
            if ($this->db()->table('starttoo_ad_views')->insertOrIgnore(['ad_id' => $ad->id, 'user_id' => $viewer, 'created_at' => now()]) > 0) {
                $this->db()->table('starttoo_ads')->where('id', $ad->id)->increment('reach');
            }
            return ['ad' => [
                'id' => (int) $ad->id, 'advertiser' => $ad->advertiser, 'title' => $ad->title,
                'image_url' => app(MediaUrls::class)->url($image, 1200),
                // 홈 피드 카드의 동그란 프로필(작가 광고만)
                'avatar_url' => $u ? app(MediaUrls::class)->url($u->avatar_path, 300) : null,
                'artist_id' => $ad->artist_id === null ? null : (int) $ad->artist_id,
                'link' => $ad->link,
                'product' => $ad->product,
                'artwork_id' => $extra['artwork_id'] ?? null,
                'rating' => $extra['rating'] ?? null,
                'review_count' => $extra['review_count'] ?? null,
                'consult_open' => $extra['consult_open'] ?? null,
                // 무빙 피드 작가 광고: 광고 칸에서 재생할 무빙(hls_url · poster_url · duration_ms · muted)
                'video' => $extra['video'] ?? null,
            ]];
        }
        return ['ad' => null];
    }

    /** 앱: 광고를 눌렀다(작가 광고는 상담 요청 집계를 위해 누른 회원을 남긴다) */
    public function click(int $id, ?int $viewer = null): array
    {
        $ad = $this->db()->table('starttoo_ads')->where('id', $id)->first();
        if (!$ad) return ['ok' => true];
        $this->db()->table('starttoo_ads')->where('id', $id)->increment('clicks');
        app(ArtistAds::class)->count($id, 'clicks');
        if ($viewer) app(ArtistAds::class)->clicked($ad, $viewer);
        return ['ok' => true];
    }

    // ── 운영 명령 ────────────────────────────────

    /**
     * 등록(서버 명령 · 운영 콘솔).
     * image = 서버에 올려 둔 이미지 파일 경로(명령) · image_bytes = 올린 이미지 내용(콘솔), 둘 다 선택
     */
    public function add(array $in): int
    {
        return (int) $this->db()->table('starttoo_ads')->insertGetId($this->values($in, null) + [
            'active' => true, 'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    /** 수정(운영 콘솔). 이미지를 새로 올리지 않으면 지금 이미지를 그대로 둔다(remove_image 면 지운다) */
    public function update(int $id, array $in): bool
    {
        $ad = $this->db()->table('starttoo_ads')->where('id', $id)->first();
        if (!$ad) throw new \InvalidArgumentException('광고를 찾을 수 없어요.');
        $values = $this->values($in, $ad);
        $this->db()->table('starttoo_ads')->where('id', $id)->update($values + ['updated_at' => now()]);
        // 바뀐 이미지의 예전 파일은 지운다(실패해도 수정은 그대로)
        if ($ad->image_path && $ad->image_path !== $values['image_path']) {
            try { app(ArtworkPhotos::class)->delete([$ad->image_path]); } catch (\Throwable $e) { report($e); }
        }
        return true;
    }

    /** 입력 검사 → 저장할 값. $current = 수정할 광고(이미지 유지용) */
    private function values(array $in, ?object $current): array
    {
        $slot = (string) ($in['slot'] ?? '');
        $advertiser = trim((string) ($in['advertiser'] ?? ''));
        $title = trim((string) ($in['title'] ?? ''));
        if (!in_array($slot, self::ADMIN_SLOTS, true)) throw new \InvalidArgumentException('자리는 '.implode(', ', self::ADMIN_SLOTS).' 중 하나예요.');
        if ($advertiser === '' || mb_strlen($advertiser) > 60) throw new \InvalidArgumentException('광고주 이름은 1~60자로 적어주세요.');
        if ($title === '' || mb_strlen($title) > 80) throw new \InvalidArgumentException('광고 문구는 1~80자로 적어주세요.');
        $artist = ($in['artist'] ?? null) ? (int) $in['artist'] : null;
        if ($artist && !$this->db()->table('starttoo_users')->where('id', $artist)->where('artist_status', 'approved')->exists()) {
            throw new \InvalidArgumentException('승인된 작가 회원 번호가 아니에요.');
        }
        $link = ($in['link'] ?? null) ? trim((string) $in['link']) : null;
        // 앱 안 주소(/로 시작) 또는 앱 밖 웹 주소(https://)
        $inApp = $link !== null && str_starts_with($link, '/') && !str_starts_with($link, '//');
        $web = $link !== null && str_starts_with($link, 'https://') && filter_var($link, FILTER_VALIDATE_URL);
        if ($link !== null && ((!$inApp && !$web) || mb_strlen($link) > 200)) {
            throw new \InvalidArgumentException('link는 앱 안 주소(/community/post?id=3)나 https:// 웹 주소여야 해요(200자까지).');
        }
        if (!$artist && $link === null) throw new \InvalidArgumentException('이동할 작가 회원 번호나 링크 중 하나는 꼭 넣어주세요.');
        $from = ($in['from'] ?? null) ? Carbon::parse($in['from'])->startOfDay() : null;
        $until = ($in['until'] ?? null) ? Carbon::parse($in['until'])->endOfDay() : null;
        if ($from && $until && $until->lt($from)) throw new \InvalidArgumentException('종료일이 시작일보다 빨라요.');
        $path = $current?->image_path;
        if (!empty($in['remove_image'])) $path = null;
        if ($in['image'] ?? null) {
            if (!is_file($in['image'])) throw new \InvalidArgumentException('이미지 파일이 없어요: '.$in['image']);
            $path = app(ArtworkPhotos::class)->storeBytes((string) file_get_contents($in['image']));
        } elseif (($in['image_bytes'] ?? '') !== '') {
            $path = app(ArtworkPhotos::class)->storeBytes((string) $in['image_bytes']);
        }
        if ($path === null && in_array($slot, self::IMAGE_REQUIRED, true)) {
            throw new \InvalidArgumentException('이 자리 광고는 사진이 꼭 필요해요. 광고 문의 첨부를 고르거나 사진을 올려주세요.');
        }
        return [
            'slot' => $slot, 'advertiser' => $advertiser, 'title' => $title, 'image_path' => $path,
            'artist_id' => $artist, 'link' => $artist ? null : $link,
            'starts_at' => $from, 'ends_at' => $until,
        ];
    }

    public function setActive(int $id, bool $active): bool
    {
        return $this->db()->table('starttoo_ads')->where('id', $id)->update(['active' => $active, 'updated_at' => now()]) > 0;
    }

    public function all(): array
    {
        return $this->db()->table('starttoo_ads')->orderByDesc('id')->limit(100)->get()->all();
    }

    private function fail(int $status, string $code, string $message): never
    {
        throw new HttpResponseException(response()->json(compact('code', 'message'), $status));
    }
}
