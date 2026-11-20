<?php

namespace App\Services\Starttoo;

use App\Services\ProjectDatabase;
use Illuminate\Http\Exceptions\HttpResponseException;
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

    public function feed(array $input, ?int $viewerId = null, ?int $authorId = null, ?array $onlyIds = null, ?int $savedOwner = null): array
    {
        $options = $this->options();
        $validator = Validator::make($input, [
            'style' => ['nullable', 'string', Rule::in(array_column($options['styles'], 'id'))],
            'cursor' => ['nullable', 'string', 'max:512'],
            'scope' => ['nullable', Rule::in(['public', 'mine', 'saved', 'following'])],
        ]);
        if ($validator->fails()) $this->fail(422, 'INVALID_ARTWORK_FILTER', '작품 목록 조회 조건을 확인해주세요.');
        $scope = $input['scope'] ?? 'public';
        if ($savedOwner !== null) {
            app(PublicProfiles::class)->user($savedOwner, $viewerId);
            if ($scope !== 'saved' || ($savedOwner !== $viewerId && !app(UserPreferences::class)->savedPublic($savedOwner))) $this->fail(403, 'SAVED_NOT_PUBLIC', '공개하지 않은 저장 목록이에요.');
        }
        if ($scope !== 'public' && $viewerId === null && !($scope === 'saved' && $savedOwner !== null)) $this->fail(401, 'SESSION_EXPIRED', '로그인이 필요해요.');
        $cursor = null;
        if (isset($input['cursor']) && $input['cursor'] !== '') {
            try {
                $decoded = base64_decode(strtr($input['cursor'], '-_', '+/'), true);
                $cursor = json_decode($decoded === false ? '' : $decoded, true, 512, JSON_THROW_ON_ERROR);
                if (!is_array($cursor) || Validator::make($cursor, [
                    'at' => ['required', 'date_format:Y-m-d H:i:s'], 'id' => ['required', 'uuid'],
                    'd' => ['sometimes', Rule::in(['next', 'previous'])],
                ])->fails()) throw new \InvalidArgumentException('Invalid artwork cursor.');
            } catch (\Throwable $e) {
                $this->fail(422, 'INVALID_ARTWORK_FILTER', '작품 목록 조회 조건을 확인해주세요.');
            }
        }
        $db = app(ProjectDatabase::class)->database();
        $query = $this->feedQuery($viewerId, $scope, $authorId);
        if ($onlyIds !== null) $query->whereIn('a.id', $onlyIds);
        $orderColumn = 'a.created_at';
        if ($scope === 'saved') {
            $query->join('starttoo_artwork_interactions as s', function ($join) use ($viewerId, $savedOwner) {
                $join->on('s.artwork_id', '=', 'a.id')->where('s.user_id', $savedOwner ?? $viewerId)->where('s.kind', 'save');
            })->addSelect('s.created_at as saved_at');
            $orderColumn = 's.created_at';
        }
        if (!empty($input['style'])) $query->whereJsonContains('a.styles', $input['style']);
        $total = ($scope !== 'public' || $authorId !== null) && $cursor === null ? (clone $query)->count() : null;
        $backward = ($cursor['d'] ?? 'next') === 'previous';
        $operator = $backward ? '>' : '<';
        if ($cursor !== null) {
            $query->where(function ($q) use ($cursor, $orderColumn, $operator) {
                $q->where($orderColumn, $operator, $cursor['at'])->orWhere(function ($sameTime) use ($cursor, $orderColumn, $operator) {
                    $sameTime->where($orderColumn, $cursor['at'])->where('a.id', $operator, $cursor['id']);
                });
            });
        }
        // 같은 초에 올라온 작품도 UUID로 순서를 고정한다. COUNT/OFFSET 없이 10개씩 조회한다.
        $direction = $backward ? 'asc' : 'desc';
        $rows = $query->orderBy($orderColumn, $direction)->orderBy('a.id', $direction)->limit(11)->get();
        $hasMore = $rows->count() > 10;
        $page = $rows->take(10)->values();
        if ($backward) $page = $page->reverse()->values();
        $images = $page->isEmpty() ? collect() : $db->table('starttoo_artwork_images')
            ->whereIn('artwork_id', $page->pluck('id')->all())->orderBy('position')->get()->groupBy('artwork_id');
        $items = [];
        $states = app(ArtworkInteractions::class)->states($page->pluck('id')->all(), $viewerId);
        $following = app(Follows::class)->following($page->pluck('author_id')->all(), $viewerId);
        $commentCounts = app(ArtworkComments::class)->counts($page->pluck('id')->all(), $viewerId);
        foreach ($page as $row) {
            $author = (object) ['id' => $row->author_id, 'nickname' => $row->author_nickname,
                'display_name' => $row->author_display_name, 'interest_region' => $row->author_region, 'avatar_path' => $row->author_avatar,
                'artist_status' => $row->author_artist_status];
            $photos = $images->get($row->id, collect());
            if ($photos->isNotEmpty()) $items[] = $this->format($row, $author, $photos, $options, $viewerId, $states[$row->id], in_array((int) $author->id, $following, true), (int) ($commentCounts[$row->id] ?? 0));
        }
        $encode = fn ($row, $d) => $row ? rtrim(strtr(base64_encode(json_encode([
            'at' => $scope === 'saved' ? $row->saved_at : $row->created_at, 'id' => $row->id, 'd' => $d,
        ], JSON_THROW_ON_ERROR)), '+/', '-_'), '=') : null;
        $next = ($backward || $hasMore) ? $encode($page->last(), 'next') : null;
        $previous = ($backward ? $hasMore : $cursor !== null) ? $encode($page->first(), 'previous') : null;
        return ['items' => $items, 'next_cursor' => $next, 'previous_cursor' => $previous, 'styles' => $options['styles'], 'total' => $total];
    }

    /** 기존 목록과 통합 피드가 동일한 공개 범위·차단·팔로잉 조건을 사용한다. */
    public function feedQuery(?int $viewerId, string $scope, ?int $authorId = null): \Illuminate\Database\Query\Builder
    {
        $query = app(ProjectDatabase::class)->database()->table('starttoo_artworks as a')
            ->join('starttoo_users as u', 'u.id', '=', 'a.user_id')->where('u.status', 'active')
            ->whereExists(fn ($q) => $q->selectRaw('1')->from('starttoo_artwork_images as i')->whereColumn('i.artwork_id', 'a.id'))
            ->select('a.*', 'u.id as author_id', 'u.nickname as author_nickname', 'u.display_name as author_display_name',
                'u.interest_region as author_region', 'u.avatar_path as author_avatar', 'u.artist_status as author_artist_status');
        if ($scope === 'mine') $query->where('a.user_id', $viewerId);
        else $query->where('a.visibility', 'public')->where('a.moderation_hidden', false)->where('u.moderation_hidden', false);
        app(Blocks::class)->filter($query, 'a.user_id', $viewerId);
        if ($viewerId !== null && in_array($scope, ['public', 'following'], true) && $authorId === null) {
            $query->whereNotExists(fn ($q) => $q->selectRaw('1')->from('starttoo_hidden_artworks as h')->whereColumn('h.artwork_id', 'a.id')->where('h.user_id', $viewerId));
        }
        if ($authorId !== null) $query->where('a.user_id', $authorId);
        if ($scope === 'following') $query->whereExists(fn ($q) => $q->selectRaw('1')->from('starttoo_follows as f')
            ->whereColumn('f.target_user_id', 'a.user_id')->where('f.user_id', $viewerId));
        return $query;
    }

    /** 미리 올린 사진(media_ids, 1~10장, 순서대로)으로 작품을 등록한다. */
    public function create(string $token, array $input, AuthSessions $sessions): array
    {
        $identity = $this->ready($token, $sessions);
        $options = $this->options();
        foreach (['title', 'description'] as $field) {
            if (is_string($input[$field] ?? null)) $input[$field] = trim($input[$field]);
        }
        $validator = Validator::make($input, [
            'request_key' => ['required', 'uuid'],
            'kind' => ['required', Rule::in(['work', 'design'])],
            // 제목 입력은 앱에서 제거됐다. 기존 앱 호환을 위해 보내면 저장한다.
            'title' => ['nullable', 'string', 'max:100'],
            'description' => ['nullable', 'string', 'max:2000'],
            'size_text' => ['nullable', 'string', 'max:50'],
            'styles' => ['required', 'array', 'min:1', 'max:5'],
            'styles.*' => ['required', 'string', 'distinct', Rule::in(array_column($options['styles'], 'id'))],
            'body_part' => ['nullable', 'string', Rule::in(array_column($options['body_parts'], 'id'))],
            'color' => ['nullable', 'string', Rule::in(array_column($options['colors'], 'id'))],
            'duration_minutes' => ['nullable', 'integer', 'min:1', 'max:10080'],
            'price' => ['required_if:kind,design', 'nullable', 'integer', 'min:0', 'max:100000000'],
            'show_in_shop' => ['required', 'boolean'],
        ]);
        if ($validator->fails() || !array_is_list($input['styles'] ?? [])) {
            $this->fail(422, 'INVALID_ARTWORK', '스타일과 작품 입력 정보를 확인해주세요.');
        }
        if (Validator::make($input, [
            'media_ids' => ['required', 'array', 'min:1', 'max:10'],
            'media_ids.*' => ['required', 'uuid', 'distinct'],
        ])->fails() || !array_is_list($input['media_ids'])) {
            $this->fail(422, 'INVALID_ARTWORK_PHOTOS', '사진을 1~10장 선택해주세요.');
        }
        $mediaIds = array_values($input['media_ids']);
        $valid = $validator->validated();
        // 필드 순서를 고정하고 원본 사진 해시와 순서까지 포함한다.
        $values = ['kind' => $valid['kind'], 'title' => $valid['title'] ?? '', 'description' => $valid['description'] ?? '',
            'size_text' => $valid['size_text'] ?? null,
            'styles' => array_values($valid['styles']), 'body_part' => $valid['body_part'] ?? null,
            'color' => $valid['color'] ?? null,
            'duration_minutes' => $valid['kind'] === 'work' && isset($valid['duration_minutes']) ? (int) $valid['duration_minutes'] : null,
            'price' => $valid['kind'] === 'design' ? (int) $valid['price'] : null,
            'show_in_shop' => (bool) $valid['show_in_shop']];
        // 필드 순서를 고정하고 사진 순서까지 포함한다. 같은 키의 다른 내용은 409로 거절한다.
        $hash = hash('sha256', json_encode([$values, $mediaIds], JSON_THROW_ON_ERROR));
        $userId = (int) $identity['account']['id'];
        $db = app(ProjectDatabase::class)->database();
        $find = fn () => $db->table('starttoo_artworks')->where('user_id', $userId)->where('request_key', $valid['request_key'])->first();
        $existing = $find();
        if ($existing) {
            $this->checkRetry($existing, $hash);
            return $this->detail($existing->id, $userId);
        }
        $id = $db->transaction(function () use ($db, $token, $sessions, $userId, $valid, $values, $hash, $mediaIds, $find) {
            $db->table('starttoo_users')->where('id', $userId)->lockForUpdate()->first();
            $this->ready($token, $sessions);
            $existing = $find();
            if ($existing) {
                $this->checkRetry($existing, $hash);
                return $existing->id;
            }
            // 본인이 올린 미첨부 사진만 첨부한다. 실패하면 트랜잭션 전체를 되돌린다.
            $paths = app(ArtworkUploads::class)->claim($userId, $mediaIds);
            $id = (string) Str::uuid();
            $db->table('starttoo_artworks')->insert([
                ...$values, 'styles' => json_encode($values['styles'], JSON_THROW_ON_ERROR),
                'tags' => json_encode(ArtworkHashtags::extract($values['description']), JSON_THROW_ON_ERROR),
                'id' => $id, 'user_id' => $userId, 'request_key' => $valid['request_key'],
                'payload_hash' => $hash, 'created_at' => now(), 'updated_at' => now(),
            ]);
            foreach ($mediaIds as $position => $mediaId) {
                $db->table('starttoo_artwork_images')->insert(['artwork_id' => $id, 'position' => $position, 'path' => $paths[$mediaId]]);
            }
            if (($values['visibility'] ?? 'public') === 'public') app(FollowerNotifications::class)->created((int) $userId, 'artwork', $id);
            $sessions->authenticate($token, true);
            return $id;
        }, 3);
        return $this->detail($id, $userId);
    }

    /**
     * 작성자 작품 삭제. 피드·프로필·상세에서 즉시 빠지고(좋아요·저장·숨김 기록은 함께 삭제)
     * 사진 파일은 커밋 뒤 지운다. 처리 대기 신고는 target_deleted로 닫고 감사 기록을 남긴다.
     * 이미 삭제된 작품은 404로 응답한다.
     */
    public function delete(string $id, string $token, AuthSessions $sessions): array
    {
        $userId = (int) $this->ready($token, $sessions)['account']['id'];
        $db = app(ProjectDatabase::class)->database();
        $paths = $db->transaction(function () use ($db, $id, $userId, $token, $sessions) {
            // 운영자 처리와 같은 회원 -> 작품 -> 신고 순서로 잠근다.
            $db->table('starttoo_users')->where('id', $userId)->lockForUpdate()->first();
            $row = $db->table('starttoo_artworks')->where('id', $id)->lockForUpdate()->first();
            $this->ready($token, $sessions);
            if (!$row) $this->fail(404, 'ARTWORK_NOT_FOUND', '이미 삭제된 작품이에요.');
            if ((int) $row->user_id !== $userId) $this->fail(403, 'ARTWORK_NOT_OWNER', '작성자만 작품을 삭제할 수 있어요.');
            $paths = $db->table('starttoo_artwork_images')->where('artwork_id', $id)->pluck('path')->all();
            $commentIds = $db->table('starttoo_artwork_comments')->where('artwork_id', $id)->pluck('id')->map(fn ($value) => (string) $value)->all();
            $closed = $db->table('starttoo_reports')->where('target_type', 'artwork')->where('target_id', $id)
                ->where('status', 'pending')->lockForUpdate()->pluck('id')->all();
            if ($closed) {
                $db->table('starttoo_reports')->whereIn('id', $closed)
                    ->update(['status' => 'target_deleted', 'open_key' => null, 'updated_at' => now()]);
            }
            if ($commentIds) {
                $db->table('starttoo_reports')->where('target_type', 'comment')->whereIn('target_id', $commentIds)->where('status', 'pending')
                    ->update(['status' => 'target_deleted', 'open_key' => null, 'updated_at' => now()]);
            }
            $db->table('starttoo_safety_audits')->insert([
                'actor_type' => 'member', 'actor_user_id' => $userId, 'target_type' => 'artwork', 'target_id' => $id,
                'action' => 'delete', 'changes' => json_encode([
                    'title' => $row->title, 'image_count' => count($paths), 'moderation_hidden' => (bool) $row->moderation_hidden,
                    'closed_reports' => $closed,
                ], JSON_THROW_ON_ERROR),
            ]);
            $db->table('starttoo_artworks')->where('id', $id)->delete();
            app(ArtworkUploads::class)->forget($paths);
            return $paths;
        }, 3);
        app(ArtworkPhotos::class)->delete($paths);
        return ['artwork_id' => $id, 'deleted' => true];
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
        if (!$author || $author->status !== 'active' || app(Blocks::class)->between($viewerId, (int) $row->user_id)
            || ((int) $row->user_id !== $viewerId && ($row->moderation_hidden || $author->moderation_hidden))) $this->fail(404, 'ARTWORK_NOT_FOUND', '작품을 찾을 수 없어요.');
        $images = $db->table('starttoo_artwork_images')->where('artwork_id', $row->id)->orderBy('position')->get();
        return $this->format($row, $author, $images, $this->options(), $viewerId);
    }

    private function format(object $row, object $author, iterable $images, array $options, ?int $viewerId = null, ?array $state = null, ?bool $following = null, ?int $commentCount = null): array
    {
        $styles = json_decode($row->styles, true, 512, JSON_THROW_ON_ERROR);
        $label = fn ($list, $value) => collect($list)->firstWhere('id', $value)['label'] ?? null;
        return ['id' => $row->id, 'kind' => $row->kind, 'title' => $row->title, 'description' => $row->description ?? '',
            'tags' => isset($row->tags) ? json_decode($row->tags, true, 512, JSON_THROW_ON_ERROR) : ArtworkHashtags::extract($row->description ?? ''),
            'viewer_id' => $viewerId, 'interaction' => $state ?? app(ArtworkInteractions::class)->states([$row->id], $viewerId)[$row->id],
            'comment_count' => $commentCount ?? (int) (app(ArtworkComments::class)->counts([$row->id], $viewerId)[$row->id] ?? 0),
            'moderation_hidden' => (bool) $row->moderation_hidden, 'size_text' => $row->size_text, 'visibility' => $row->visibility, 'version' => (int) $row->version,
            'can_edit' => $viewerId !== null && (int) $row->user_id === $viewerId,
            'styles' => array_values(array_filter($options['styles'], fn ($style) => in_array($style['id'], $styles, true))),
            'body_part' => $label($options['body_parts'], $row->body_part), 'color' => $label($options['colors'], $row->color),
            'duration_minutes' => $row->duration_minutes, 'price' => $row->price, 'show_in_shop' => (bool) $row->show_in_shop,
            'size_text' => $row->size_text ?? null,
            // 2-10 예약 가능 도안: 예약 가능·상담 중·작업 완료
            'design_status' => $row->kind === 'design' ? ($row->design_status ?? 'available') : null,
            'created_at' => $row->created_at,
            'author' => ['id' => $author->id, 'nickname' => $author->nickname ?? $author->display_name,
                'following' => $following ?? in_array((int) $author->id, app(Follows::class)->following([(int) $author->id], $viewerId), true),
                'region' => $label(app(Profiles::class)->options()['regions'], $author->interest_region),
                'avatar_url' => app(MediaUrls::class)->url($author->avatar_path, 300),
                // 아티스트면 상점으로 이동할 수 있다.
                'is_artist' => ($author->artist_status ?? null) === 'approved'],
            'images' => collect($images)
                ->map(fn ($image) => ['id' => (int) $image->id, 'position' => $image->position, 'url' => app(MediaUrls::class)->url($image->path, 500)])->all()];
    }

    private function fail(int $status, string $code, string $message): never
    {
        throw new HttpResponseException(response()->json(compact('code', 'message'), $status));
    }
}
