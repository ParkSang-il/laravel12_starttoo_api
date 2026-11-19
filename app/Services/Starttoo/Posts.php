<?php

namespace App\Services\Starttoo;

use App\Services\ProjectDatabase;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;

/** 일반 게시물 전용 저장소. 사진만 기존 선업로드/첨부 검증을 공유한다. */
class Posts
{
    public function feedQuery(?int $viewerId, string $scope, ?int $authorId = null): \Illuminate\Database\Query\Builder
    {
        $query = app(ProjectDatabase::class)->database()->table('starttoo_posts as p')
            ->join('starttoo_users as u', 'u.id', '=', 'p.user_id')
            ->where('u.status', 'active')->where('u.moderation_hidden', false)->where('p.moderation_hidden', false)
            ->whereExists(fn ($q) => $q->selectRaw('1')->from('starttoo_post_images as i')->whereColumn('i.post_id', 'p.id'))
            ->select('p.*', 'u.nickname as author_nickname', 'u.display_name as author_display_name', 'u.avatar_path as author_avatar');
        app(Blocks::class)->filter($query, 'p.user_id', $viewerId);
        if ($authorId !== null) $query->where('p.user_id', $authorId);
        if ($viewerId !== null && $authorId === null && in_array($scope, ['public', 'following'], true)) {
            $query->whereNotExists(fn ($q) => $q->selectRaw('1')->from('starttoo_hidden_posts as h')
                ->whereColumn('h.post_id', 'p.id')->where('h.user_id', $viewerId));
        }
        if ($scope === 'following') $query->whereExists(fn ($q) => $q->selectRaw('1')->from('starttoo_follows as f')
            ->whereColumn('f.target_user_id', 'p.user_id')->where('f.user_id', $viewerId));
        return $query;
    }

    /** 통합 피드가 고른 게시물만 일괄 조회한다. 카드별 추가 쿼리를 실행하지 않는다. */
    public function feedItems(array $ids, ?int $viewerId, string $scope, ?int $authorId = null): array
    {
        if (!$ids) return [];
        $rows = $this->feedQuery($viewerId, $scope, $authorId)->whereIn('p.id', $ids)->get();
        $images = app(ProjectDatabase::class)->database()->table('starttoo_post_images')
            ->whereIn('post_id', $rows->pluck('id')->all())->orderBy('position')->get()->groupBy('post_id');
        $following = app(Follows::class)->following($rows->pluck('user_id')->all(), $viewerId);
        $interactions = app(PostLikes::class)->states($rows->pluck('id')->all(), $viewerId);
        $comments = app(PostComments::class)->counts($rows->pluck('id')->all(), $viewerId);
        $items = [];
        foreach ($rows as $row) {
            $photos = $images->get($row->id, collect());
            if ($photos->isEmpty()) continue;
            $items[] = ['kind' => 'post', 'id' => $row->id, 'content' => $row->content,
                'interaction' => $interactions[$row->id], 'comment_count' => (int) ($comments[$row->id] ?? 0),
                'tags' => json_decode($row->tags, true, 512, JSON_THROW_ON_ERROR),
                'version' => (int) $row->version, 'viewer_id' => $viewerId,
                'can_edit' => $viewerId === (int) $row->user_id, 'moderation_hidden' => false,
                'created_at' => $row->created_at,
                'author' => ['id' => (int) $row->user_id, 'nickname' => $row->author_nickname ?? $row->author_display_name,
                    'avatar_url' => app(MediaUrls::class)->url($row->author_avatar, 300),
                    'following' => in_array((int) $row->user_id, $following, true)],
                'images' => $photos->map(fn ($image) => ['id' => (int) $image->id, 'url' => app(MediaUrls::class)->url($image->path, 500)])->all()];
        }
        return $items;
    }

    public function ready(string $token, AuthSessions $sessions): array
    {
        $identity = $sessions->authenticate($token);
        if ($identity['account']['next_action'] !== 'home') {
            $this->fail(409, 'POST_SIGNUP_REQUIRED', '가입을 완료한 뒤 게시물을 작성해주세요.');
        }
        return $identity;
    }

    public function detail(string $id, ?int $viewerId): array
    {
        $db = app(ProjectDatabase::class)->database();
        $row = $db->table('starttoo_posts')->where('id', $id)->first();
        if (!$row) $this->fail(404, 'POST_NOT_FOUND', '게시물을 찾을 수 없어요.');
        $author = $db->table('starttoo_users')->where('id', $row->user_id)->first();
        if (!$author || $author->status !== 'active' || app(Blocks::class)->between($viewerId, (int) $row->user_id)
            || ($viewerId !== (int) $row->user_id && ($row->moderation_hidden || $author->moderation_hidden))) {
            $this->fail(404, 'POST_NOT_FOUND', '게시물을 찾을 수 없어요.');
        }
        return [
            'id' => $row->id, 'content' => $row->content,
            'interaction' => app(PostLikes::class)->states([$id], $viewerId)[$id],
            'comment_count' => (int) (app(PostComments::class)->counts([$id], $viewerId)[$id] ?? 0),
            'tags' => json_decode($row->tags, true, 512, JSON_THROW_ON_ERROR),
            'version' => (int) $row->version, 'viewer_id' => $viewerId,
            'can_edit' => $viewerId === (int) $row->user_id,
            'moderation_hidden' => (bool) ($row->moderation_hidden || $author->moderation_hidden),
            'created_at' => $row->created_at,
            'author' => ['id' => (int) $author->id, 'nickname' => $author->nickname ?? $author->display_name,
                'avatar_url' => app(MediaUrls::class)->url($author->avatar_path, 300),
                'following' => in_array((int) $author->id, app(Follows::class)->following([(int) $author->id], $viewerId), true)],
            'images' => $db->table('starttoo_post_images')->where('post_id', $id)->orderBy('position')->get()
                ->map(fn ($image) => ['id' => (int) $image->id, 'url' => app(MediaUrls::class)->url($image->path, 500)])->all(),
        ];
    }

    public function save(?string $id, string $token, array $input, AuthSessions $sessions): array
    {
        $userId = (int) $this->ready($token, $sessions)['account']['id'];
        $input['content'] = is_string($input['content'] ?? null) ? trim($input['content']) : ($input['content'] ?? null);
        $rules = ['request_key' => ['required', 'uuid'], 'content' => ['present', 'string', 'max:2000']];
        if ($id === null) {
            $rules += ['media_ids' => ['required', 'array', 'min:1', 'max:10'], 'media_ids.*' => ['required', 'uuid', 'distinct']];
        } else {
            $rules += ['version' => ['required', 'integer', 'min:1'], 'image_order' => ['required', 'array', 'min:1', 'max:10']];
        }
        $validator = Validator::make($input, $rules);
        $list = $id === null ? 'media_ids' : 'image_order';
        if ($validator->fails() || !array_is_list($input[$list] ?? [])) {
            $this->fail(422, 'INVALID_POST', '본문은 2000자 이하, 사진은 1~10장으로 입력해주세요.');
        }
        $valid = $validator->validated();
        $mediaIds = $id === null ? $valid['media_ids'] : [];
        $order = [];
        $used = [];
        if ($id !== null) {
            foreach ($valid['image_order'] as $entry) {
                if (!is_array($entry) || count($entry) !== 1) $this->fail(422, 'INVALID_POST', '사진 순서를 확인해주세요.');
                if (isset($entry['existing_id']) && is_int($entry['existing_id']) && $entry['existing_id'] > 0) {
                    $key = 'old:'.$entry['existing_id'];
                } elseif (isset($entry['media_id']) && is_string($entry['media_id']) && Str::isUuid($entry['media_id'])) {
                    $key = 'new:'.$entry['media_id'];
                    $mediaIds[] = $entry['media_id'];
                } else $this->fail(422, 'INVALID_POST', '사진 순서를 확인해주세요.');
                if (isset($used[$key])) $this->fail(422, 'INVALID_POST', '같은 사진을 중복 등록할 수 없어요.');
                $used[$key] = true;
                $order[] = $entry;
            }
        }
        // 키 순서나 미사용 필드가 달라도 의미가 같은 요청은 같은 해시를 사용한다.
        $hash = hash('sha256', json_encode([$id, $valid['content'], $valid['version'] ?? null,
            $id === null ? $mediaIds : $order], JSON_THROW_ON_ERROR));
        $db = app(ProjectDatabase::class)->database();
        $removed = [];
        $savedId = $db->transaction(function () use ($db, $id, $token, $sessions, $userId, $valid, $hash, $mediaIds, $order, &$removed) {
            $db->table('starttoo_users')->where('id', $userId)->lockForUpdate()->first();
            $this->ready($token, $sessions);
            $row = $id === null ? null : $db->table('starttoo_posts')->where('id', $id)->lockForUpdate()->first();
            if ($id !== null && (!$row || (int) $row->user_id !== $userId)) {
                $this->fail(403, 'POST_NOT_OWNER', '작성자만 게시물을 편집할 수 있어요.');
            }
            $retry = $db->table($id === null ? 'starttoo_posts' : 'starttoo_post_edits')
                ->where('user_id', $userId)->where('request_key', $valid['request_key'])->first();
            if ($retry) {
                if ($retry->payload_hash !== $hash) $this->fail(409, 'POST_REQUEST_CONFLICT', '요청 내용이 변경됐어요. 다시 시도해주세요.');
                return $id ?? $retry->id;
            }
            if ($row && (int) $row->version !== (int) $valid['version']) {
                $this->fail(409, 'POST_EDIT_CONFLICT', '다른 곳에서 게시물이 변경됐어요. 다시 불러온 뒤 편집해주세요.');
            }
            $images = $id === null ? collect() : $db->table('starttoo_post_images')->where('post_id', $id)->get()->keyBy('id');
            foreach ($order as $entry) {
                if (isset($entry['existing_id']) && !$images->has($entry['existing_id'])) {
                    $this->fail(422, 'INVALID_POST', '이 게시물에 첨부된 사진만 사용할 수 있어요.');
                }
            }
            $paths = app(ArtworkUploads::class)->claim($userId, $mediaIds);
            $values = ['content' => $valid['content'], 'tags' => json_encode(ArtworkHashtags::extract($valid['content']), JSON_THROW_ON_ERROR), 'updated_at' => now()];
            $savedId = $id ?? (string) Str::uuid();
            if ($id === null) {
                $db->table('starttoo_posts')->insert([...$values, 'id' => $savedId, 'user_id' => $userId,
                    'request_key' => $valid['request_key'], 'payload_hash' => $hash, 'created_at' => now()]);
                foreach ($mediaIds as $position => $mediaId) {
                    $db->table('starttoo_post_images')->insert(['post_id' => $savedId, 'position' => $position, 'path' => $paths[$mediaId]]);
                }
                app(FollowerNotifications::class)->created((int) $userId, 'post', $savedId);
            } else {
                $retained = array_column($order, 'existing_id');
                $removed = $images->filter(fn ($image) => !in_array((int) $image->id, $retained, true))->pluck('path')->all();
                $db->table('starttoo_post_images')->where('post_id', $id)->increment('position', 20);
                $db->table('starttoo_post_images')->where('post_id', $id)->whereNotIn('id', $retained)->delete();
                foreach ($order as $position => $entry) {
                    if (isset($entry['existing_id'])) $db->table('starttoo_post_images')->where('post_id', $id)->where('id', $entry['existing_id'])->update(['position' => $position]);
                    else $db->table('starttoo_post_images')->insert(['post_id' => $id, 'position' => $position, 'path' => $paths[$entry['media_id']]]);
                }
                $db->table('starttoo_posts')->where('id', $id)->update([...$values, 'version' => $row->version + 1]);
                $db->table('starttoo_post_edits')->insert(['post_id' => $id, 'user_id' => $userId, 'request_key' => $valid['request_key'], 'payload_hash' => $hash]);
                app(ArtworkUploads::class)->forget($removed);
            }
            $sessions->authenticate($token, true);
            return $savedId;
        }, 3);
        app(ArtworkPhotos::class)->delete($removed);
        return $this->detail($savedId, $userId);
    }

    public function delete(string $id, string $token, array $input, AuthSessions $sessions): array
    {
        $userId = (int) $this->ready($token, $sessions)['account']['id'];
        if (Validator::make($input, ['request_key' => ['required', 'uuid']])->fails()) $this->fail(422, 'INVALID_POST', '삭제 요청을 확인해주세요.');
        $db = app(ProjectDatabase::class)->database();
        $paths = $db->transaction(function () use ($db, $id, $token, $input, $sessions, $userId) {
            $db->table('starttoo_users')->where('id', $userId)->lockForUpdate()->first();
            $this->ready($token, $sessions);
            $retry = $db->table('starttoo_post_deletions')->where('user_id', $userId)->where('request_key', $input['request_key'])->first();
            if ($retry) {
                if ($retry->post_id !== $id) $this->fail(409, 'POST_REQUEST_CONFLICT', '삭제 대상이 변경됐어요.');
                return json_decode($retry->paths, true, 512, JSON_THROW_ON_ERROR);
            }
            $row = $db->table('starttoo_posts')->where('id', $id)->lockForUpdate()->first();
            if (!$row) $this->fail(404, 'POST_NOT_FOUND', '이미 삭제된 게시물이에요.');
            if ((int) $row->user_id !== $userId) $this->fail(403, 'POST_NOT_OWNER', '작성자만 게시물을 삭제할 수 있어요.');
            $paths = $db->table('starttoo_post_images')->where('post_id', $id)->pluck('path')->all();
            $comments = $db->table('starttoo_post_comments')->where('post_id', $id)->pluck('id')->map(fn ($v) => (string) $v)->all();
            $reports = $db->table('starttoo_reports')->where('status', 'pending')->where(function ($q) use ($id, $comments) {
                $q->where(fn ($p) => $p->where('target_type', 'post')->where('target_id', $id))
                    ->orWhere(fn ($c) => $c->where('target_type', 'post_comment')->whereIn('target_id', $comments));
            })->lockForUpdate()->pluck('id')->all();
            if ($reports) $db->table('starttoo_reports')->whereIn('id', $reports)->update(['status' => 'target_deleted', 'open_key' => null, 'updated_at' => now()]);
            $db->table('starttoo_safety_audits')->insert([
                'actor_type' => 'member', 'actor_user_id' => $userId, 'target_type' => 'post', 'target_id' => $id,
                'action' => 'delete', 'changes' => json_encode(['image_count' => count($paths), 'closed_reports' => $reports], JSON_THROW_ON_ERROR),
            ]);
            $db->table('starttoo_posts')->where('id', $id)->delete();
            app(ArtworkUploads::class)->forget($paths);
            $db->table('starttoo_post_deletions')->insert([
                'user_id' => $userId, 'post_id' => $id, 'request_key' => $input['request_key'],
                'paths' => json_encode($paths, JSON_THROW_ON_ERROR),
            ]);
            $sessions->authenticate($token, true);
            return $paths;
        }, 3);
        app(ArtworkPhotos::class)->delete($paths);
        return ['post_id' => $id, 'deleted' => true];
    }

    public function hide(string $id, string $token, array $input, AuthSessions $sessions): array
    {
        $viewer = (int) $this->ready($token, $sessions)['account']['id'];
        if (Validator::make($input, ['request_key' => ['required', 'uuid'], 'active' => ['required', 'boolean']])->fails()
            || !is_bool($input['active'] ?? null)) $this->fail(422, 'INVALID_SAFETY_REQUEST', '숨김 요청을 확인해주세요.');
        $db = app(ProjectDatabase::class)->database();
        $author = $db->table('starttoo_posts')->where('id', $id)->value('user_id');
        return $db->transaction(function () use ($db, $viewer, $author, $id, $input, $token, $sessions) {
            $db->table('starttoo_users')->whereIn('id', array_filter([$viewer, $author]))->orderBy('id')->lockForUpdate()->get();
            $this->ready($token, $sessions);
            $requests = app(Blocks::class);
            $hash = hash('sha256', json_encode(['hide_post', $id, $input['active']], JSON_THROW_ON_ERROR));
            if ($result = $requests->retry($viewer, $input['request_key'], $hash)) return $result;
            $detail = $this->detail($id, $viewer);
            if ($detail['can_edit']) $this->fail(422, 'CANNOT_HIDE_SELF', '본인 게시물은 편집 또는 삭제로 관리해주세요.');
            if ($detail['moderation_hidden']) $this->fail(404, 'POST_NOT_FOUND', '게시물을 찾을 수 없어요.');
            $key = ['user_id' => $viewer, 'post_id' => $id];
            if ($input['active']) $db->table('starttoo_hidden_posts')->insertOrIgnore([...$key, 'created_at' => now()]);
            else $db->table('starttoo_hidden_posts')->where($key)->delete();
            $result = ['post_id' => $id, 'hidden' => $input['active']];
            $requests->remember($viewer, $input['request_key'], $hash, $result);
            return $result;
        }, 3);
    }

    private function fail(int $status, string $code, string $message): never
    {
        throw new HttpResponseException(response()->json(compact('code', 'message'), $status));
    }
}
