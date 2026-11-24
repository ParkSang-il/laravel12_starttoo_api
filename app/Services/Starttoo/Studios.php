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
            'user_id' => $userId, 'activity_name' => $this->db()->table('starttoo_users')->where('id', $userId)->value('nickname') ?? $application->activity_name,
            'region' => $application->region,
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
        return ['shop' => $this->ownerView($shop), 'counts' => [
            'works' => (int) ($counts->works ?? 0), 'designs' => (int) ($counts->designs ?? 0),
            'open_designs' => (int) ($counts->open_designs ?? 0),
        ], 'social' => $social];
    }

    public function update(int $userId, array $input): array
    {
        $input['activity_name'] = $this->db()->table('starttoo_users')->where('id', $userId)->value('nickname');
        foreach (['activity_name', 'studio_name', 'bio', 'notice', 'business_hours', 'guide', 'address', 'address_detail'] as $field) {
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
            // 위치: 주소 검색 결과의 address만 받고 좌표는 서버가 다시 찾는다. 키가 없으면 위치를 바꾸지 않는다.
            'address' => ['sometimes', 'nullable', 'string', 'max:200'],
            'address_detail' => ['sometimes', 'nullable', 'string', 'max:100'],
        ]);
        if ($validator->fails()) $this->fail(422, 'INVALID_SHOP', '상점 정보를 확인해주세요.');
        $input = $validator->validated();
        if (isset($input['price_min'], $input['price_max']) && $input['price_min'] > $input['price_max']) {
            $this->fail(422, 'INVALID_SHOP_PRICE', '최소 가격이 최대 가격보다 클 수 없어요.');
        }
        $location = null;
        if (array_key_exists('address', $input)) {
            $current = $this->db()->table('starttoo_shops')->where('user_id', $userId)->first(['address', 'lat']);
            if (($input['address'] ?? '') === '') {
                $location = ['address' => null, 'address_detail' => null, 'area' => null, 'lat' => null, 'lng' => null, 'map_lat' => null, 'map_lng' => null];
            } elseif ($current && $current->address === $input['address'] && $current->lat !== null) {
                $location = ['address_detail' => ($input['address_detail'] ?? '') ?: null];
            } else {
                // 트랜잭션 밖에서 외부 API를 호출한다.
                $found = app(KakaoLocal::class)->geocode($input['address']);
                if (!$found) $this->fail(422, 'ADDRESS_NOT_FOUND', '주소를 찾지 못했어요. 주소 검색에서 다시 선택해주세요.');
                [$mapLat, $mapLng] = $this->blur($userId, $found['lat'], $found['lng']);
                $location = ['address' => $found['address'], 'address_detail' => ($input['address_detail'] ?? '') ?: null,
                    'area' => $found['area'] ?: null, 'lat' => $found['lat'], 'lng' => $found['lng'], 'map_lat' => $mapLat, 'map_lng' => $mapLng];
            }
        }
        $oldCover = null;
        $result = $this->db()->transaction(function () use ($userId, $input, $location, &$oldCover) {
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
                ...($location ?? []),
            ]);
            return $this->ownerView($this->db()->table('starttoo_shops')->where('user_id', $userId)->first());
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
        $shop->nickname = $user->nickname;
        return $shop;
    }

    public function view(object $shop): array
    {
        // activity_name은 기존 앱 호환용 필드명이며 응답 값은 언제나 현재 닉네임이다.
        $nickname = $shop->nickname ?? $this->db()->table('starttoo_users')->where('id', $shop->user_id)->value('nickname') ?? $shop->activity_name;
        $regions = app(Profiles::class)->options()['regions'];
        $label = collect($regions)->firstWhere('id', $shop->region)['label'] ?? $shop->region;
        return ['user_id' => (int) $shop->user_id, 'nickname' => $nickname, 'activity_name' => $nickname, 'studio_name' => $shop->studio_name,
            'region' => $shop->region, 'region_label' => $label, 'bio' => $shop->bio,
            'price_min' => $shop->price_min === null ? null : (int) $shop->price_min,
            'price_max' => $shop->price_max === null ? null : (int) $shop->price_max,
            'consult_status' => $shop->consult_status, 'notice' => $shop->notice,
            'cover_url' => $shop->cover_path ? app(MediaUrls::class)->url($shop->cover_path, 1080) : null,
            'business_hours' => $shop->business_hours ?? null, 'guide' => $shop->guide ?? null,
            // 공개 위치는 동 단위와 흐린 좌표만 준다. 정확한 주소·좌표는 ownerView에서만.
            'area' => $shop->area ?? null,
            'map_lat' => isset($shop->map_lat) ? (float) $shop->map_lat : null,
            'map_lng' => isset($shop->map_lng) ? (float) $shop->map_lng : null,
            'version' => (int) $shop->version];
    }

    /** 본인 스튜디오용: 공개 정보 + 정확한 주소. */
    public function ownerView(object $shop): array
    {
        return [...$this->view($shop), 'address' => $shop->address ?? null, 'address_detail' => $shop->address_detail ?? null];
    }

    /**
     * 공개 지도용 좌표: 회원·좌표마다 고정된 방향으로 150~300m 옮긴다.
     * 같은 위치는 항상 같은 값이 나와 여러 번 조회해 평균을 내도 정확한 위치가 드러나지 않는다.
     */
    public function blur(int $userId, float $lat, float $lng): array
    {
        $seed = crc32($userId.'|'.round($lat, 5).'|'.round($lng, 5).'|'.config('app.key'));
        $angle = deg2rad($seed % 360);
        $meters = 150 + intdiv($seed, 360) % 151;
        $dLat = $meters * cos($angle) / 111320;
        $dLng = $meters * sin($angle) / (111320 * max(cos(deg2rad($lat)), 0.01));
        return [round($lat + $dLat, 7), round($lng + $dLng, 7)];
    }

    private function fail(int $status, string $code, string $message): never
    {
        throw new HttpResponseException(response()->json(compact('code', 'message'), $status));
    }
}
