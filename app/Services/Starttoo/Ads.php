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
    public const SLOTS = ['community_top'];

    private function db() { return app(ProjectDatabase::class)->database(); }

    /** 앱: 이 자리에 보여 줄 광고 1건(없으면 null) */
    public function pick(string $slot, int $viewer): array
    {
        if (!in_array($slot, self::SLOTS, true)) $this->fail(422, 'INVALID_AD', '광고 자리를 확인해주세요.');
        $rows = $this->db()->table('starttoo_ads')->where('slot', $slot)->where('active', true)
            ->where(fn ($q) => $q->whereNull('starts_at')->orWhere('starts_at', '<=', now()))
            ->where(fn ($q) => $q->whereNull('ends_at')->orWhere('ends_at', '>', now()))
            ->inRandomOrder()->limit(10)->get();
        foreach ($rows as $ad) {
            if ($ad->artist_id) {
                // 광고주 작가가 탈퇴 · 숨김이거나 차단 관계면 보여 주지 않는다
                $u = $this->db()->table('starttoo_users')->where('id', $ad->artist_id)->first(['status', 'moderation_hidden']);
                if (!$u || $u->status !== 'active' || $u->moderation_hidden || app(Blocks::class)->between($viewer, (int) $ad->artist_id)) continue;
            }
            $this->db()->table('starttoo_ads')->where('id', $ad->id)->increment('impressions');
            return ['ad' => [
                'id' => (int) $ad->id, 'advertiser' => $ad->advertiser, 'title' => $ad->title,
                'image_url' => app(MediaUrls::class)->url($ad->image_path, 1200),
                'artist_id' => $ad->artist_id === null ? null : (int) $ad->artist_id,
                'link' => $ad->link,
            ]];
        }
        return ['ad' => null];
    }

    /** 앱: 광고를 눌렀다 */
    public function click(int $id): array
    {
        $this->db()->table('starttoo_ads')->where('id', $id)->increment('clicks');
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
        if (!in_array($slot, self::SLOTS, true)) throw new \InvalidArgumentException('자리는 '.implode(', ', self::SLOTS).' 중 하나예요.');
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
