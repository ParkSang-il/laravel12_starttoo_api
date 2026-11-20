<?php

namespace App\Services\Starttoo;

use App\Services\ProjectDatabase;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;

/**
 * 2-14·2-15·2-16 아티스트 스튜디오(작가용).
 * 승인된 아티스트만 사용한다. 상점은 승인 시 신청서 내용으로 만들어 두고 여기서 고친다.
 */
class Studios
{
    public const CONSULT = ['open', 'closed', 'holiday'];
    public const DESIGN_STATUS = ['available', 'consulting', 'done'];
    private const MAX_PRICE = 100_000_000;

    private function db() { return app(ProjectDatabase::class)->database(); }

    /** 승인 시 신청서 내용으로 상점을 만든다(이미 있으면 그대로). */
    public function open(int $userId, object $application): void
    {
        $this->db()->table('starttoo_shops')->insertOrIgnore([
            'user_id' => $userId, 'activity_name' => $application->activity_name, 'region' => $application->region,
            'bio' => $application->bio, 'consult_status' => 'open', 'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    public function dashboard(int $userId): array
    {
        $shop = $this->shop($userId);
        $counts = $this->db()->table('starttoo_artworks')->where('user_id', $userId)
            ->selectRaw("SUM(kind = 'work') as works, SUM(kind = 'design') as designs, SUM(kind = 'design' AND design_status = 'available' AND visibility = 'public') as open_designs")
            ->first();
        $social = app(Follows::class)->state($userId, $userId);
        return ['shop' => $this->view($shop), 'counts' => [
            'works' => (int) ($counts->works ?? 0), 'designs' => (int) ($counts->designs ?? 0),
            'open_designs' => (int) ($counts->open_designs ?? 0),
        ], 'social' => $social];
    }

    public function update(int $userId, array $input): array
    {
        foreach (['activity_name', 'studio_name', 'bio', 'notice', 'business_hours', 'guide'] as $field) {
            if (is_string($input[$field] ?? null)) $input[$field] = trim($input[$field]);
        }
        $regions = array_column(app(Profiles::class)->options()['regions'], 'id');
        $validator = Validator::make($input, [
            'version' => ['required', 'integer', 'min:1'],
            'activity_name' => ['required', 'string', 'min:2', 'max:20'],
            'studio_name' => ['nullable', 'string', 'max:30'],
            'region' => ['required', 'string', Rule::in($regions)],
            'bio' => ['nullable', 'string', 'max:300'],
            'price_min' => ['nullable', 'integer', 'min:0', 'max:'.self::MAX_PRICE],
            'price_max' => ['nullable', 'integer', 'min:0', 'max:'.self::MAX_PRICE],
            'consult_status' => ['required', Rule::in(self::CONSULT)],
            'notice' => ['nullable', 'string', 'max:60'],
            'business_hours' => ['nullable', 'string', 'max:60'],
            'guide' => ['nullable', 'string', 'max:300'],
            'cover_media_id' => ['nullable', 'uuid'],
            'remove_cover' => ['sometimes', 'boolean'],
        ]);
        if ($validator->fails()) $this->fail(422, 'INVALID_SHOP', '상점 정보를 확인해주세요.');
        $input = $validator->validated();
        if (isset($input['price_min'], $input['price_max']) && $input['price_min'] > $input['price_max']) {
            $this->fail(422, 'INVALID_SHOP_PRICE', '최소 가격이 최대 가격보다 클 수 없어요.');
        }
        $oldCover = null;
        $result = $this->db()->transaction(function () use ($userId, $input, &$oldCover) {
            $shop = $this->db()->table('starttoo_shops')->where('user_id', $userId)->lockForUpdate()->first();
            if (!$shop) $this->fail(404, 'SHOP_NOT_FOUND', '상점을 찾을 수 없어요.');
            if ((int) $shop->version !== (int) $input['version']) {
                $this->fail(409, 'SHOP_EDIT_CONFLICT', '다른 기기에서 상점 정보가 바뀌었어요. 다시 불러온 뒤 수정해주세요.');
            }
            $cover = $shop->cover_path;
            if (!empty($input['cover_media_id'])) {
                $cover = app(ArtworkUploads::class)->claim($userId, [$input['cover_media_id']])[$input['cover_media_id']];
            } elseif (!empty($input['remove_cover'])) {
                $cover = null;
            }
            if ($cover !== $shop->cover_path) $oldCover = $shop->cover_path;
            $this->db()->table('starttoo_shops')->where('user_id', $userId)->update([
                'activity_name' => $input['activity_name'], 'studio_name' => $input['studio_name'] ?? null,
                'region' => $input['region'], 'bio' => $input['bio'] ?? '',
                'price_min' => $input['price_min'] ?? null, 'price_max' => $input['price_max'] ?? null,
                'consult_status' => $input['consult_status'], 'notice' => $input['notice'] ?? null,
                'business_hours' => ($input['business_hours'] ?? '') ?: null, 'guide' => ($input['guide'] ?? '') ?: null,
                'cover_path' => $cover, 'version' => (int) $shop->version + 1, 'updated_at' => now(),
            ]);
            return $this->view($this->db()->table('starttoo_shops')->where('user_id', $userId)->first());
        });
        if ($oldCover) {
            app(ArtworkUploads::class)->forget([$oldCover]);
            app(ArtworkPhotos::class)->delete([$oldCover]);
        }
        return $result;
    }

    /** 2-16 내 작품·도안 목록(숨김 포함). */
    public function artworks(int $userId, array $input): array
    {
        if (Validator::make($input, ['kind' => ['required', Rule::in(['work', 'design'])], 'cursor' => ['nullable', 'string', 'max:100']])->fails()) {
            $this->fail(422, 'INVALID_STUDIO_QUERY', '목록 조건을 확인해주세요.');
        }
        $q = $this->db()->table('starttoo_artworks')->where('user_id', $userId)->where('kind', $input['kind']);
        if (!empty($input['cursor'])) {
            [$at, $id] = array_pad(explode('|', base64_decode($input['cursor'], true) ?: ''), 2, null);
            if (!$at || !$id) $this->fail(422, 'INVALID_STUDIO_QUERY', '목록을 다시 불러와주세요.');
            $q->where(fn ($w) => $w->where('created_at', '<', $at)->orWhere(fn ($s) => $s->where('created_at', $at)->where('id', '<', $id)));
        }
        $rows = $q->orderByDesc('created_at')->orderByDesc('id')->limit(31)->get();
        $page = $rows->take(30);
        $ids = $page->pluck('id')->all();
        $images = $ids ? $this->db()->table('starttoo_artwork_images')->whereIn('artwork_id', $ids)->orderBy('position')
            ->get(['artwork_id', 'path'])->groupBy('artwork_id') : collect();
        $likes = $ids ? $this->db()->table('starttoo_artwork_interactions')->whereIn('artwork_id', $ids)->where('kind', 'like')
            ->groupBy('artwork_id')->selectRaw('artwork_id, COUNT(*) as total')->pluck('total', 'artwork_id') : collect();
        $last = $page->last();
        return ['items' => $page->map(fn ($r) => $this->artwork($r, $images, $likes))->values()->all(),
            'next_cursor' => $rows->count() > 30 ? base64_encode($last->created_at.'|'.$last->id) : null];
    }

    /** 2-16 공개·상점 노출·도안 상태 변경. */
    public function updateArtwork(int $userId, string $id, array $input): array
    {
        if (Validator::make($input, [
            'visibility' => ['sometimes', Rule::in(['public', 'hidden'])],
            'show_in_shop' => ['sometimes', 'boolean'],
            'design_status' => ['sometimes', Rule::in(self::DESIGN_STATUS)],
        ])->fails()) $this->fail(422, 'INVALID_STUDIO_ARTWORK', '변경 내용을 확인해주세요.');
        $row = $this->db()->table('starttoo_artworks')->where('id', $id)->where('user_id', $userId)->first();
        if (!$row) $this->fail(404, 'ARTWORK_NOT_FOUND', '작품을 찾을 수 없어요.');
        if (isset($input['design_status']) && $row->kind !== 'design') {
            $this->fail(422, 'INVALID_STUDIO_ARTWORK', '도안만 예약 상태를 바꿀 수 있어요.');
        }
        $changes = array_intersect_key($input, array_flip(['visibility', 'show_in_shop', 'design_status']));
        if ($changes) {
            $this->db()->table('starttoo_artworks')->where('id', $id)
                ->update([...$changes, 'version' => (int) $row->version + 1, 'updated_at' => now()]);
        }
        $row = $this->db()->table('starttoo_artworks')->where('id', $id)->first();
        $images = $this->db()->table('starttoo_artwork_images')->where('artwork_id', $id)->orderBy('position')->get(['artwork_id', 'path'])->groupBy('artwork_id');
        $likes = $this->db()->table('starttoo_artwork_interactions')->where('artwork_id', $id)->where('kind', 'like')
            ->groupBy('artwork_id')->selectRaw('artwork_id, COUNT(*) as total')->pluck('total', 'artwork_id');
        return $this->artwork($row, $images, $likes);
    }

    private function artwork(object $r, $images, $likes): array
    {
        $first = optional($images->get($r->id))->first();
        return ['id' => $r->id, 'kind' => $r->kind, 'title' => $r->title, 'description' => $r->description,
            'body_part' => $r->body_part, 'price' => $r->price === null ? null : (int) $r->price,
            'visibility' => $r->visibility, 'show_in_shop' => (bool) $r->show_in_shop,
            'design_status' => $r->kind === 'design' ? $r->design_status : null,
            'moderation_hidden' => (bool) $r->moderation_hidden,
            'like_count' => (int) ($likes[$r->id] ?? 0),
            'image_url' => $first ? app(MediaUrls::class)->url($first->path, 300) : null,
            'created_at' => $r->created_at];
    }

    /** 승인된 아티스트의 상점(없으면 승인 정보로 만든다). */
    public function shop(int $userId): object
    {
        $user = $this->db()->table('starttoo_users')->where('id', $userId)->first();
        if (($user->artist_status ?? null) !== 'approved') {
            $this->fail(403, 'ARTIST_REQUIRED', '아티스트 승인 후 이용할 수 있어요.');
        }
        $shop = $this->db()->table('starttoo_shops')->where('user_id', $userId)->first();
        if (!$shop) {
            $app = $this->db()->table('starttoo_artist_applications')->where('user_id', $userId)->first();
            if ($app) $this->open($userId, $app);
            $shop = $this->db()->table('starttoo_shops')->where('user_id', $userId)->first();
            if (!$shop) $this->fail(404, 'SHOP_NOT_FOUND', '상점을 찾을 수 없어요.');
        }
        return $shop;
    }

    public function view(object $shop): array
    {
        $regions = app(Profiles::class)->options()['regions'];
        $label = collect($regions)->firstWhere('id', $shop->region)['label'] ?? $shop->region;
        return ['user_id' => (int) $shop->user_id, 'activity_name' => $shop->activity_name, 'studio_name' => $shop->studio_name,
            'region' => $shop->region, 'region_label' => $label, 'bio' => $shop->bio,
            'price_min' => $shop->price_min === null ? null : (int) $shop->price_min,
            'price_max' => $shop->price_max === null ? null : (int) $shop->price_max,
            'consult_status' => $shop->consult_status, 'notice' => $shop->notice,
            'cover_url' => $shop->cover_path ? app(MediaUrls::class)->url($shop->cover_path, 1080) : null,
            'business_hours' => $shop->business_hours ?? null, 'guide' => $shop->guide ?? null,
            'version' => (int) $shop->version];
    }

    private function fail(int $status, string $code, string $message): never
    {
        throw new HttpResponseException(response()->json(compact('code', 'message'), $status));
    }
}
