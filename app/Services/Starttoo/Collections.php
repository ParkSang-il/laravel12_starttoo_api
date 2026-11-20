<?php

namespace App\Services\Starttoo;

use App\Services\ProjectDatabase;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;

/**
 * 1-14 저장 컬렉션.
 * - 「전체」는 저장한 모든 항목(기존 저장 목록)이고, 컬렉션은 그중 일부를 묶은 것이다.
 * - 컬렉션에 담으면 저장도 함께 된다. 저장을 취소하면 모든 컬렉션에서 빠진다.
 * - 공개 범위: public 컬렉션은 다른 회원 프로필에서 보인다(전체 저장 목록 공개 설정과 별개).
 * - 항목 목록은 통합 피드(scope=saved, collection=ID)로 조회한다.
 */
class Collections
{
    public const MAX_COLLECTIONS = 50;
    private const TYPES = ['artwork', 'post'];

    private function db() { return app(ProjectDatabase::class)->database(); }

    /** 소유자 컬렉션 목록. 다른 회원이 보면 공개 컬렉션만. target을 주면 담김 여부를 함께 준다. */
    public function index(int $owner, ?int $viewer, array $input = []): array
    {
        $own = $owner === $viewer;
        if (! $own) app(PublicProfiles::class)->user($owner, $viewer);
        $target = null;
        if ($own && isset($input['target_type'], $input['target_id'])) {
            if (Validator::make($input, ['target_type' => ['required', Rule::in(self::TYPES)], 'target_id' => ['required', 'uuid']])->fails()) {
                $this->fail(422, 'INVALID_COLLECTION', '컬렉션 요청을 확인해주세요.');
            }
            $target = [$input['target_type'], $input['target_id']];
        }
        $rows = $this->db()->table('starttoo_collections')->where('user_id', $owner)
            ->when(! $own, fn ($q) => $q->where('visibility', 'public'))
            ->orderByDesc('id')->get();
        $items = [];
        foreach ($rows as $row) {
            [$count, $cover] = $this->summary((int) $row->id, $owner, $viewer);
            $item = ['id' => (int) $row->id, 'name' => $row->name, 'visibility' => $row->visibility,
                'count' => $count, 'cover_url' => $cover];
            if ($target) {
                $item['contains'] = $this->db()->table('starttoo_collection_items')->where('collection_id', $row->id)
                    ->where('target_type', $target[0])->where('target_id', $target[1])->exists();
            }
            $items[] = $item;
        }
        $all = null;
        if ($own || app(UserPreferences::class)->savedPublic($owner)) {
            [$count, $cover] = $this->summary(null, $owner, $viewer);
            $all = ['count' => $count, 'cover_url' => $cover];
        }

        return ['all' => $all, 'items' => $items, 'max' => self::MAX_COLLECTIONS];
    }

    public function create(int $owner, array $input): array
    {
        $input = $this->validated($input, true);
        return $this->db()->transaction(function () use ($owner, $input) {
            $this->db()->table('starttoo_users')->where('id', $owner)->lockForUpdate()->first();
            if ($this->db()->table('starttoo_collections')->where('user_id', $owner)->count() >= self::MAX_COLLECTIONS) {
                $this->fail(422, 'COLLECTION_LIMIT', '컬렉션은 최대 '.self::MAX_COLLECTIONS.'개까지 만들 수 있어요.');
            }
            try {
                $id = $this->db()->table('starttoo_collections')->insertGetId([
                    'user_id' => $owner, 'name' => $input['name'], 'visibility' => $input['visibility'] ?? 'private',
                    'created_at' => now(), 'updated_at' => now(),
                ]);
            } catch (UniqueConstraintViolationException) {
                $this->fail(409, 'COLLECTION_NAME_TAKEN', '같은 이름의 컬렉션이 있어요.');
            }
            if (isset($input['target_type'], $input['target_id'])) {
                $this->put($owner, (int) $id, ['target_type' => $input['target_type'], 'target_id' => $input['target_id'], 'active' => true], false);
            }
            return $this->one($owner, (int) $id);
        });
    }

    public function update(int $owner, int $id, array $input): array
    {
        $input = $this->validated($input, false);
        $this->owned($owner, $id);
        $changes = array_intersect_key($input, array_flip(['name', 'visibility']));
        if ($changes) {
            try {
                $this->db()->table('starttoo_collections')->where('id', $id)->update([...$changes, 'updated_at' => now()]);
            } catch (UniqueConstraintViolationException) {
                $this->fail(409, 'COLLECTION_NAME_TAKEN', '같은 이름의 컬렉션이 있어요.');
            }
        }
        return $this->one($owner, $id);
    }

    /** 컬렉션만 지운다. 담겨 있던 항목의 저장은 그대로 둔다. */
    public function delete(int $owner, int $id): void
    {
        $this->owned($owner, $id);
        $this->db()->table('starttoo_collections')->where('id', $id)->delete();
    }

    /** 컬렉션에 담기(active=true, 저장도 함께) 또는 빼기(active=false, 저장은 유지). */
    public function put(int $owner, int $id, array $input, bool $transaction = true): array
    {
        if (Validator::make($input, [
            'target_type' => ['required', Rule::in(self::TYPES)], 'target_id' => ['required', 'uuid'], 'active' => ['required', 'boolean'],
        ])->fails() || ! is_bool($input['active'])) {
            $this->fail(422, 'INVALID_COLLECTION', '컬렉션 요청을 확인해주세요.');
        }
        $run = function () use ($owner, $id, $input) {
            $this->owned($owner, $id);
            $key = ['collection_id' => $id, 'target_type' => $input['target_type'], 'target_id' => $input['target_id']];
            if ($input['active']) {
                $this->ensureSaved($owner, $input['target_type'], $input['target_id']);
                $this->db()->table('starttoo_collection_items')->insertOrIgnore([...$key, 'created_at' => now()]);
            } else {
                $this->db()->table('starttoo_collection_items')->where($key)->delete();
            }
            $this->db()->table('starttoo_collections')->where('id', $id)->update(['updated_at' => now()]);
            return ['collection_id' => $id, 'contains' => $input['active'], 'saved' => true];
        };
        return $transaction ? $this->db()->transaction($run) : $run();
    }

    /** 저장 취소 시 그 회원의 모든 컬렉션에서 뺀다. */
    public function forget(int $owner, string $type, string $targetId): void
    {
        $this->db()->table('starttoo_collection_items')->where('target_type', $type)->where('target_id', $targetId)
            ->whereIn('collection_id', $this->db()->table('starttoo_collections')->where('user_id', $owner)->select('id'))
            ->delete();
    }

    /** 통합 피드 조회 권한: 본인 컬렉션이거나 공개 컬렉션이어야 한다. */
    public function readable(int $id, int $owner, ?int $viewer): void
    {
        $row = $this->db()->table('starttoo_collections')->where('id', $id)->where('user_id', $owner)->first();
        if (! $row || ($owner !== $viewer && $row->visibility !== 'public')) {
            $this->fail(404, 'COLLECTION_NOT_FOUND', '컬렉션을 찾을 수 없어요.');
        }
    }

    private function ensureSaved(int $owner, string $type, string $targetId): void
    {
        if ($type === 'artwork') {
            // 볼 수 있는 작품인지 확인(비공개·차단·숨김은 404)
            app(Artworks::class)->detail($targetId, $owner);
            $this->db()->table('starttoo_artwork_interactions')->insertOrIgnore([
                'user_id' => $owner, 'artwork_id' => $targetId, 'kind' => 'save', 'created_at' => now(), 'updated_at' => now(),
            ]);
        } else {
            app(Posts::class)->detail($targetId, $owner);
            $this->db()->table('starttoo_post_saves')->insertOrIgnore([
                'user_id' => $owner, 'post_id' => $targetId, 'created_at' => now(),
            ]);
        }
    }

    /** 보이는 항목 수와 가장 최근 항목의 대표 사진. $collection이 null이면 전체 저장. */
    private function summary(?int $collection, int $owner, ?int $viewer): array
    {
        $art = app(Artworks::class)->feedQuery($viewer ?? $owner, 'saved')
            ->join('starttoo_artwork_interactions as sv', fn ($j) => $j->on('sv.artwork_id', '=', 'a.id')->where('sv.user_id', $owner)->where('sv.kind', 'save'));
        $post = app(Posts::class)->feedQuery($viewer ?? $owner, 'saved')
            ->join('starttoo_post_saves as sp', fn ($j) => $j->on('sp.post_id', '=', 'p.id')->where('sp.user_id', $owner));
        if ($collection !== null) {
            $art->join('starttoo_collection_items as ci', fn ($j) => $j->on('ci.target_id', '=', 'a.id')->where('ci.target_type', 'artwork')->where('ci.collection_id', $collection));
            $post->join('starttoo_collection_items as ci', fn ($j) => $j->on('ci.target_id', '=', 'p.id')->where('ci.target_type', 'post')->where('ci.collection_id', $collection));
        }
        $time = $collection !== null ? 'ci.created_at' : null;
        $count = (clone $art)->count() + (clone $post)->count();
        // feedQuery가 이미 select를 정해 두었으므로 select()로 바꿔야 at 값이 들어온다.
        $artTime = $time ?? 'sv.created_at';
        $postTime = $time ?? 'sp.created_at';
        $latestArt = (clone $art)->select('a.id', $artTime.' as at')->orderByDesc($artTime)->first();
        $latestPost = (clone $post)->select('p.id', $postTime.' as at')->orderByDesc($postTime)->first();
        $path = null;
        if ($latestArt && (! $latestPost || $latestArt->at >= $latestPost->at)) {
            $path = $this->db()->table('starttoo_artwork_images')->where('artwork_id', $latestArt->id)->orderBy('position')->value('path');
        } elseif ($latestPost) {
            $path = $this->db()->table('starttoo_post_images')->where('post_id', $latestPost->id)->orderBy('position')->value('path');
        }

        return [$count, $path ? app(MediaUrls::class)->url($path, 300) : null];
    }

    private function one(int $owner, int $id): array
    {
        $row = $this->owned($owner, $id);
        [$count, $cover] = $this->summary($id, $owner, $owner);
        return ['id' => (int) $row->id, 'name' => $row->name, 'visibility' => $row->visibility, 'count' => $count, 'cover_url' => $cover];
    }

    private function owned(int $owner, int $id): object
    {
        $row = $this->db()->table('starttoo_collections')->where('id', $id)->where('user_id', $owner)->first();
        if (! $row) $this->fail(404, 'COLLECTION_NOT_FOUND', '컬렉션을 찾을 수 없어요.');
        return $row;
    }

    private function validated(array $input, bool $create): array
    {
        if (is_string($input['name'] ?? null)) $input['name'] = trim(preg_replace('/\s+/u', ' ', $input['name']) ?? '');
        $rules = [
            'name' => [$create ? 'required' : 'sometimes', 'string', 'min:1', 'max:20'],
            'visibility' => ['sometimes', Rule::in(['public', 'private'])],
        ];
        if ($create) $rules += ['target_type' => ['sometimes', Rule::in(self::TYPES)], 'target_id' => ['required_with:target_type', 'uuid']];
        $validator = Validator::make($input, $rules);
        if ($validator->fails()) $this->fail(422, 'INVALID_COLLECTION', '컬렉션 이름은 1~20자로 입력해주세요.');
        return $validator->validated();
    }

    private function fail(int $status, string $code, string $message): never
    {
        throw new HttpResponseException(response()->json(compact('code', 'message'), $status));
    }
}
