<?php

namespace App\Services\Starttoo;

use App\Services\ProjectDatabase;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

class Artworks
{
    public function options(): array
    {
        $option = fn ($id, $label) => compact('id', 'label');
        return ['styles' => app(Profiles::class)->options()['styles'],
            'body_parts' => array_map($option,
                ['head', 'neck', 'shoulder', 'arm', 'forearm', 'wrist', 'hand', 'chest', 'back', 'abdomen', 'waist', 'thigh', 'calf', 'ankle', 'foot', 'other'],
                ['머리', '목', '어깨', '팔', '팔뚝', '손목', '손', '가슴', '등', '복부', '허리', '허벅지', '종아리', '발목', '발', '기타']),
            'colors' => array_map($option, ['black', 'color', 'black_and_color'], ['블랙', '컬러', '블랙 + 컬러'])];
    }

    public function ready(string $token, AuthSessions $sessions): array
    {
        $identity = $sessions->authenticate($token);
        if ($identity['account']['next_action'] !== 'home') {
            $this->fail(409, 'ARTWORK_SIGNUP_REQUIRED', '가입을 완료한 뒤 작품을 등록해주세요.');
        }
        return $identity;
    }

    public function feed(array $input, ?int $viewerId = null): array
    {
        $options = $this->options();
        $validator = Validator::make($input, [
            'style' => ['nullable', 'string', Rule::in(array_column($options['styles'], 'id'))],
            'cursor' => ['nullable', 'string', 'max:512'],
            'scope' => ['nullable', Rule::in(['public', 'mine', 'saved'])],
        ]);
        if ($validator->fails()) $this->fail(422, 'INVALID_ARTWORK_FILTER', '작품 목록 조회 조건을 확인해주세요.');
        $scope = $input['scope'] ?? 'public';
        if ($scope !== 'public' && $viewerId === null) $this->fail(401, 'SESSION_EXPIRED', '로그인이 필요해요.');
        $cursor = null;
        if (isset($input['cursor']) && $input['cursor'] !== '') {
            try {
                $decoded = base64_decode(strtr($input['cursor'], '-_', '+/'), true);
                $cursor = json_decode($decoded === false ? '' : $decoded, true, 512, JSON_THROW_ON_ERROR);
                if (!is_array($cursor) || Validator::make($cursor, [
                    'at' => ['required', 'date_format:Y-m-d H:i:s'], 'id' => ['required', 'uuid'],
                ])->fails()) throw new \InvalidArgumentException('Invalid artwork cursor.');
            } catch (\Throwable $e) {
                $this->fail(422, 'INVALID_ARTWORK_FILTER', '작품 목록 조회 조건을 확인해주세요.');
            }
        }
        $db = app(ProjectDatabase::class)->database();
        $query = $db->table('starttoo_artworks as a')
            ->join('starttoo_users as u', 'u.id', '=', 'a.user_id')->where('u.status', 'active')
            ->whereExists(fn ($q) => $q->selectRaw('1')->from('starttoo_artwork_images as i')->whereColumn('i.artwork_id', 'a.id'))
            ->select('a.*', 'u.id as author_id', 'u.nickname as author_nickname', 'u.display_name as author_display_name',
                'u.interest_region as author_region', 'u.avatar_path as author_avatar');
        if ($scope === 'mine') $query->where('a.user_id', $viewerId);
        else $query->where('a.visibility', 'public');
        $orderColumn = 'a.created_at';
        if ($scope === 'saved') {
            $query->join('starttoo_artwork_interactions as s', function ($join) use ($viewerId) {
                $join->on('s.artwork_id', '=', 'a.id')->where('s.user_id', $viewerId)->where('s.kind', 'save');
            })->addSelect('s.created_at as saved_at');
            $orderColumn = 's.created_at';
        }
        if (!empty($input['style'])) $query->whereJsonContains('a.styles', $input['style']);
        $total = $scope !== 'public' && $cursor === null ? (clone $query)->count() : null;
        if ($cursor !== null) {
            $query->where(function ($q) use ($cursor, $orderColumn) {
                $q->where($orderColumn, '<', $cursor['at'])->orWhere(function ($sameTime) use ($cursor, $orderColumn) {
                    $sameTime->where($orderColumn, $cursor['at'])->where('a.id', '<', $cursor['id']);
                });
            });
        }
        // 같은 초에 올라온 작품도 UUID로 순서를 고정한다. COUNT/OFFSET 없이 10개씩 조회한다.
        $rows = $query->orderByDesc($orderColumn)->orderByDesc('a.id')->limit(11)->get();
        $hasMore = $rows->count() > 10;
        $page = $rows->take(10)->values();
        $images = $page->isEmpty() ? collect() : $db->table('starttoo_artwork_images')
            ->whereIn('artwork_id', $page->pluck('id')->all())->orderBy('position')->get()->groupBy('artwork_id');
        $items = [];
        $states = app(ArtworkInteractions::class)->states($page->pluck('id')->all(), $viewerId);
        foreach ($page as $row) {
            $author = (object) ['id' => $row->author_id, 'nickname' => $row->author_nickname,
                'display_name' => $row->author_display_name, 'interest_region' => $row->author_region, 'avatar_path' => $row->author_avatar];
            $photos = $images->get($row->id, collect());
            if ($photos->isNotEmpty()) $items[] = $this->format($row, $author, $photos, $options, $viewerId, $states[$row->id]);
        }
        $last = $page->last();
        $next = $hasMore && $last ? rtrim(strtr(base64_encode(json_encode([
            'at' => $scope === 'saved' ? $last->saved_at : $last->created_at, 'id' => $last->id,
        ], JSON_THROW_ON_ERROR)), '+/', '-_'), '=') : null;
        return ['items' => $items, 'next_cursor' => $next, 'styles' => $options['styles'], 'total' => $total];
    }

    public function create(string $token, array $input, array $files, AuthSessions $sessions): array
    {
        $identity = $this->ready($token, $sessions);
        $options = $this->options();
        foreach (['title', 'description'] as $field) {
            if (is_string($input[$field] ?? null)) $input[$field] = trim($input[$field]);
        }
        $validator = Validator::make($input, [
            'request_key' => ['required', 'uuid'],
            'kind' => ['required', Rule::in(['work', 'design'])],
            'title' => ['required', 'string', 'max:100'],
            'description' => ['nullable', 'string', 'max:2000'],
            'size_text' => ['nullable', 'string', 'max:50'],
            'styles' => ['required', 'array', 'min:1', 'max:5'],
            'styles.*' => ['required', 'string', 'distinct', Rule::in(array_column($options['styles'], 'id'))],
            'body_part' => ['nullable', 'string', Rule::in(array_column($options['body_parts'], 'id'))],
            'color' => ['nullable', 'string', Rule::in(array_column($options['colors'], 'id'))],
            'duration_minutes' => ['nullable', 'integer', 'min:1', 'max:10080'],
            'price' => ['required_if:kind,design', 'nullable', 'integer', 'min:0', 'max:100000000'],
            'show_in_shop' => ['required', 'boolean'],
            'photo_count' => ['required', 'integer', 'min:1', 'max:10'],
        ]);
        if ($validator->fails() || !array_is_list($input['styles'] ?? [])) {
            $this->fail(422, 'INVALID_ARTWORK', '제목·스타일과 작품 입력 정보를 확인해주세요.');
        }
        $photoValidator = Validator::make(['photos' => $files], [
            'photos' => ['required', 'array', 'min:1', 'max:10'],
            'photos.*' => ['required', 'file', 'image', 'mimes:jpg,jpeg,png,webp', 'max:5120', 'dimensions:max_width=4096,max_height=4096'],
        ]);
        if ($photoValidator->fails() || !array_is_list($files) || count($files) !== (int) $input['photo_count']) {
            $this->fail(422, 'INVALID_ARTWORK_PHOTOS', '사진은 1~10장, 각 5MB 이하의 JPG·PNG·WebP로 선택해주세요.');
        }
        $valid = $validator->validated();
        // 필드 순서를 고정하고 원본 사진 해시와 순서까지 포함한다.
        $values = ['kind' => $valid['kind'], 'title' => $valid['title'], 'description' => $valid['description'] ?? '',
            'size_text' => $valid['size_text'] ?? null,
            'styles' => array_values($valid['styles']), 'body_part' => $valid['body_part'] ?? null,
            'color' => $valid['color'] ?? null,
            'duration_minutes' => $valid['kind'] === 'work' && isset($valid['duration_minutes']) ? (int) $valid['duration_minutes'] : null,
            'price' => $valid['kind'] === 'design' ? (int) $valid['price'] : null,
            'show_in_shop' => (bool) $valid['show_in_shop']];
        $hash = hash('sha256', json_encode([$values, array_map(fn ($file) => hash_file('sha256', $file->getRealPath()), $files)], JSON_THROW_ON_ERROR));
        $db = app(ProjectDatabase::class)->database();
        $find = fn () => $db->table('starttoo_artworks')->where('user_id', $identity['account']['id'])->where('request_key', $valid['request_key'])->first();
        $existing = $find();
        if ($existing) {
            $this->checkRetry($existing, $hash);
            return $this->detail($existing->id, (int) $identity['account']['id']);
        }
        $paths = [];
        $keep = false;
        $photos = app(ArtworkPhotos::class);
        try {
            foreach ($files as $file) $paths[] = $photos->store($identity['account']['id'], $file);
            $result = $db->transaction(function () use ($db, $token, $sessions, $identity, $valid, $values, $hash, $paths, $find) {
                $db->table('starttoo_users')->where('id', $identity['account']['id'])->lockForUpdate()->first();
                $this->ready($token, $sessions);
                $existing = $find();
                if ($existing) {
                    $this->checkRetry($existing, $hash);
                    return ['id' => $existing->id, 'created' => false];
                }
                $id = (string) Str::uuid();
                $db->table('starttoo_artworks')->insert([
                    ...$values, 'styles' => json_encode($values['styles'], JSON_THROW_ON_ERROR),
                    'id' => $id, 'user_id' => $identity['account']['id'], 'request_key' => $valid['request_key'],
                    'payload_hash' => $hash, 'created_at' => now(), 'updated_at' => now(),
                ]);
                foreach ($paths as $position => $path) {
                    $db->table('starttoo_artwork_images')->insert(['artwork_id' => $id, 'position' => $position, 'path' => $path]);
                }
                $sessions->authenticate($token, true);
                return ['id' => $id, 'created' => true];
            }, 3);
            $keep = $result['created'];
            return $this->detail($result['id'], (int) $identity['account']['id']);
        } finally {
            if (!$keep) $photos->delete($paths);
        }
    }

    private function checkRetry(object $artwork, string $hash): void
    {
        if ($artwork->payload_hash !== $hash) {
            $this->fail(409, 'ARTWORK_REQUEST_CONFLICT', '게시 요청이 변경됐어요. 내용을 확인하고 다시 시도해주세요.');
        }
    }

    public function detail(string $id, ?int $viewerId = null): array
    {
        $db = app(ProjectDatabase::class)->database();
        $row = $db->table('starttoo_artworks')->where('id', $id)->first();
        if (!$row) $this->fail(404, 'ARTWORK_NOT_FOUND', '작품을 찾을 수 없어요.');
        if ($row->visibility !== 'public' && (int) $row->user_id !== $viewerId) $this->fail(404, 'ARTWORK_NOT_FOUND', '작품을 찾을 수 없어요.');
        $author = $db->table('starttoo_users')->where('id', $row->user_id)->first();
        if (!$author || $author->status !== 'active') $this->fail(404, 'ARTWORK_NOT_FOUND', '작품을 찾을 수 없어요.');
        $images = $db->table('starttoo_artwork_images')->where('artwork_id', $row->id)->orderBy('position')->get();
        return $this->format($row, $author, $images, $this->options(), $viewerId);
    }

    private function format(object $row, object $author, iterable $images, array $options, ?int $viewerId = null, ?array $state = null): array
    {
        $styles = json_decode($row->styles, true, 512, JSON_THROW_ON_ERROR);
        $label = fn ($list, $value) => collect($list)->firstWhere('id', $value)['label'] ?? null;
        return ['id' => $row->id, 'kind' => $row->kind, 'title' => $row->title, 'description' => $row->description ?? '',
            'viewer_id' => $viewerId, 'interaction' => $state ?? app(ArtworkInteractions::class)->states([$row->id], $viewerId)[$row->id],
            'size_text' => $row->size_text, 'visibility' => $row->visibility, 'version' => (int) $row->version,
            'can_edit' => $viewerId !== null && (int) $row->user_id === $viewerId,
            'styles' => array_values(array_filter($options['styles'], fn ($style) => in_array($style['id'], $styles, true))),
            'body_part' => $label($options['body_parts'], $row->body_part), 'color' => $label($options['colors'], $row->color),
            'duration_minutes' => $row->duration_minutes, 'price' => $row->price, 'show_in_shop' => (bool) $row->show_in_shop,
            'created_at' => $row->created_at,
            'author' => ['id' => $author->id, 'nickname' => $author->nickname ?? $author->display_name,
                'region' => $label(app(Profiles::class)->options()['regions'], $author->interest_region),
                'avatar_url' => $author->avatar_path ? Storage::disk('starttoo')->temporaryUrl($author->avatar_path, now()->addHour()) : null],
            'images' => collect($images)
                ->map(fn ($image) => ['id' => (int) $image->id, 'position' => $image->position, 'url' => Storage::disk('starttoo')->temporaryUrl($image->path, now()->addHour())])->all()];
    }

    private function fail(int $status, string $code, string $message): never
    {
        throw new HttpResponseException(response()->json(compact('code', 'message'), $status));
    }
}
