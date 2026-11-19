<?php

namespace App\Services\Starttoo;

use App\Services\ProjectDatabase;
use Illuminate\Database\Query\Builder;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Support\Carbon;

class ArtworkComments
{
    protected const CONTENT_TABLE = 'starttoo_artworks';
    protected const CONTENT_ID = 'artwork_id';
    protected const COMMENTS = 'starttoo_artwork_comments';
    protected const LIKES = 'starttoo_comment_likes';
    protected const REQUESTS = 'starttoo_comment_requests';
    protected const REPORT_TYPE = 'comment';
    protected const DOMAIN = 'artwork';

    protected function contentService(): Artworks|Posts { return app(Artworks::class); }
    private function detail(string $id, ?int $viewer): array
    {
        return $this->contentService()->detail($id, $viewer) + ['visibility' => 'public'];
    }

    private function db() { return app(ProjectDatabase::class)->database(); }

    /** 차단·비활성·운영자 숨김 댓글은 목록·수·직접 변경에서 동일하게 제외한다. */
    private function visible(?int $viewer, string $alias = 'c'): Builder
    {
        $q = $this->db()->table(static::COMMENTS." as $alias")
            ->join("starttoo_users as {$alias}_u", "{$alias}_u.id", '=', "$alias.user_id")
            ->where("{$alias}_u.status", 'active')->where("{$alias}_u.moderation_hidden", false)
            ->where("$alias.moderation_hidden", false);
        return app(Blocks::class)->filter($q, "$alias.user_id", $viewer);
    }

    private function inThreads(?int $viewer): Builder
    {
        return $this->visible($viewer)->where(function ($q) use ($viewer) {
            $q->whereNull('c.parent_id')->orWhereIn('c.parent_id',
                $this->visible($viewer, 'p')->select('p.id')->whereNull('p.parent_id'));
        });
    }

    public function counts(array $artworkIds, ?int $viewer): array
    {
        if (!$artworkIds) return [];
        return $this->inThreads($viewer)->whereIn('c.'.static::CONTENT_ID, $artworkIds)->whereNull('c.deleted_at')
            ->groupBy('c.'.static::CONTENT_ID)->selectRaw('c.'.static::CONTENT_ID.', COUNT(*) as total')->pluck('total', static::CONTENT_ID)->all();
    }

    public function listing(string $artwork, array $input, ?int $viewer, bool $canWrite): array
    {
        $detail = $this->detail($artwork, $viewer);
        if (Validator::make($input, ['sort' => ['sometimes', Rule::in(['popular', 'latest'])], 'cursor' => ['nullable', 'string', 'max:512']])->fails()) {
            $this->fail(422, 'INVALID_COMMENT_CURSOR', '댓글 조회 조건을 확인해주세요.');
        }
        $sort = $input['sort'] ?? 'popular';
        $cursor = $this->cursor($input['cursor'] ?? null, $artwork, $sort);
        $q = $this->visible($viewer)->where('c.'.static::CONTENT_ID, $artwork)->whereNull('c.parent_id')
            ->where(function ($q) use ($viewer) {
                $q->whereNull('c.deleted_at')->orWhereExists(function ($sub) use ($viewer) {
                    $sub->from(static::COMMENTS.' as r')->join('starttoo_users as ru', 'ru.id', '=', 'r.user_id')
                        ->whereColumn('r.parent_id', 'c.id')->whereNull('r.deleted_at')->where('r.moderation_hidden', false)
                        ->where('ru.status', 'active')->where('ru.moderation_hidden', false)->selectRaw('1');
                    app(Blocks::class)->filter($sub, 'r.user_id', $viewer);
                });
            });
        if ($cursor) {
            if ($sort === 'latest') $q->where('c.id', '<', $cursor['id']);
            else $q->where(fn ($q) => $q->where('c.like_count', '<', $cursor['likes'])
                ->orWhere(fn ($q) => $q->where('c.like_count', $cursor['likes'])->where('c.id', '<', $cursor['id'])));
        }
        if ($sort === 'popular') $q->orderByDesc('c.like_count');
        $rows = $q->orderByDesc('c.id')->limit(11)->get(['c.*', 'c_u.nickname', 'c_u.avatar_path']);
        $canWrite = $canWrite && $detail['visibility'] === 'public' && !$detail['moderation_hidden'];
        $items = [];
        foreach ($rows->take(10) as $row) {
            $item = $this->format($row, $viewer, $detail, $canWrite);
            $replies = $this->replyPage($row->id, $viewer, $detail, $canWrite, null, 3);
            $items[] = [...$item, 'replies' => $replies['items'], 'reply_count' => $replies['total'], 'replies_next_cursor' => $replies['next_cursor']];
        }
        $last = $rows->take(10)->last();
        return ['items' => $items, 'total' => (int) ($this->counts([$artwork], $viewer)[$artwork] ?? 0),
            'next_cursor' => $rows->count() > 10 ? $this->encode(['artwork' => $artwork, 'sort' => $sort, 'id' => (int) $last->id, 'likes' => (int) $last->like_count]) : null,
            'viewer_id' => $viewer, 'can_comment' => $canWrite];
    }

    public function replies(string $artwork, int $parent, array $input, ?int $viewer, bool $canWrite): array
    {
        if (Validator::make($input, ['cursor' => ['nullable', 'string', 'max:512']])->fails()) {
            $this->fail(422, 'INVALID_COMMENT_CURSOR', '댓글 목록을 다시 열어주세요.');
        }
        $detail = $this->detail($artwork, $viewer);
        $row = $this->visible($viewer)->where('c.'.static::CONTENT_ID, $artwork)->where('c.id', $parent)->whereNull('c.parent_id')->first(['c.id']);
        if (!$row) $this->fail(404, 'COMMENT_NOT_FOUND', '댓글을 찾을 수 없어요.');
        $cursor = $this->cursor($input['cursor'] ?? null, $artwork, 'replies:'.$parent);
        return $this->replyPage($parent, $viewer, $detail,
            $canWrite && $detail['visibility'] === 'public' && !$detail['moderation_hidden'], $cursor['id'] ?? null, 20);
    }

    private function replyPage(int $parent, ?int $viewer, array $detail, bool $canWrite, ?int $after, int $limit): array
    {
        $q = $this->visible($viewer)->where('c.parent_id', $parent)->whereNull('c.deleted_at');
        $total = (clone $q)->count();
        if ($after !== null) $q->where('c.id', '>', $after);
        $rows = $q->orderBy('c.id')->limit($limit + 1)->get(['c.*', 'c_u.nickname', 'c_u.avatar_path']);
        return ['items' => $rows->take($limit)->map(fn ($row) => $this->format($row, $viewer, $detail, $canWrite))->all(),
            'total' => $total, 'next_cursor' => $rows->count() > $limit
                ? $this->encode(['artwork' => $detail['id'], 'sort' => 'replies:'.$parent, 'id' => (int) $rows[$limit - 1]->id]) : null];
    }

    public function change(string $action, string $artwork, ?int $commentId, string $token, array $input, AuthSessions $sessions): array
    {
        $artworks = $this->contentService();
        $viewer = (int) $artworks->ready($token, $sessions)['account']['id'];
        $input['content'] = is_string($input['content'] ?? null) ? preg_replace('/^\s+|\s+$/u', '', $input['content']) : ($input['content'] ?? null);
        $rules = ['request_key' => ['required', 'uuid']];
        if ($action === 'create') $rules += ['content' => ['required', 'string', 'max:1000'], 'parent_id' => ['nullable', 'integer', 'min:1'],
            'mentions' => ['sometimes', 'array', 'max:20'], 'mentions.*.user_id' => ['required', 'integer', 'min:1'], 'mentions.*.nickname' => ['required', 'string', 'max:20']];
        if ($action === 'like') $rules += ['active' => ['required', 'boolean']];
        $validator = Validator::make($input, $rules);
        if ($validator->fails() || ($action === 'create' && !preg_match('/[^\s\x{200B}\x{FEFF}]/u', $input['content'] ?? '')) || ($action === 'like' && !is_bool($input['active'] ?? null))
            || ($action === 'create' && !array_is_list($input['mentions'] ?? []))) $this->fail(422, 'INVALID_COMMENT', '댓글과 요청 내용을 확인해주세요.');
        $input = $validator->validated();
        $hash = hash('sha256', json_encode([$action, $artwork, $commentId, $input], JSON_THROW_ON_ERROR));
        $db = $this->db();
        $targetId = $action === 'create' ? ($input['parent_id'] ?? null) : $commentId;
        $author = $db->table(static::CONTENT_TABLE)->where('id', $artwork)->value('user_id');
        $targetAuthor = $targetId ? $db->table(static::COMMENTS)->where('id', $targetId)->value('user_id') : null;
        return $db->transaction(function () use ($db, $action, $artwork, $commentId, $token, $sessions, $viewer, $input, $hash, $targetId, $author, $targetAuthor, $artworks) {
            $db->table('starttoo_users')->whereIn('id', array_unique(array_filter([$viewer, $author, $targetAuthor])))->orderBy('id')->lockForUpdate()->get();
            $artworks->ready($token, $sessions);
            $db->table(static::CONTENT_TABLE)->where('id', $artwork)->lockForUpdate()->first();
            $detail = $this->detail($artwork, $viewer);
            if ($detail['visibility'] !== 'public' || $detail['moderation_hidden']) $this->fail(409, 'COMMENTS_UNAVAILABLE', '이 작품에는 댓글을 작성할 수 없어요.');
            $retry = $db->table(static::REQUESTS)->where('user_id', $viewer)->where('request_key', $input['request_key'])->first();
            if ($retry) {
                if ($retry->payload_hash !== $hash) $this->fail(409, 'COMMENT_REQUEST_CONFLICT', '댓글 요청 내용이 변경됐어요. 다시 확인해주세요.');
                return json_decode($retry->response_payload, true, 512, JSON_THROW_ON_ERROR);
            }
            $target = $targetId ? $this->visible($viewer)->where('c.'.static::CONTENT_ID, $artwork)->where('c.id', $targetId)->whereNull('c.deleted_at')->lockForUpdate()->first(['c.*', 'c_u.nickname', 'c_u.avatar_path']) : null;
            if ($targetId && !$target) $this->fail(404, 'COMMENT_NOT_FOUND', '댓글을 찾을 수 없어요.');
            if ($target && $target->parent_id !== null) {
                if ($action === 'create') $this->fail(422, 'COMMENT_REPLY_DEPTH', '대댓글에는 답글을 작성할 수 없어요.');
                if (!$this->visible($viewer)->where('c.id', $target->parent_id)->exists()) $this->fail(404, 'COMMENT_NOT_FOUND', '댓글을 찾을 수 없어요.');
            }
            if ($action === 'create') {
                $id = $db->table(static::COMMENTS)->insertGetId([static::CONTENT_ID => $artwork, 'user_id' => $viewer,
                    'parent_id' => $targetId, 'content' => $input['content'], 'mentions' => json_encode($this->mentions($artwork, $viewer, $input), JSON_THROW_ON_ERROR),
                    'created_at' => now(), 'updated_at' => now()]);
                app(Notifications::class)->emit((int) $author, $viewer, 'comment', static::DOMAIN, $artwork, $id);
                if ($target && (int) $target->user_id !== (int) $author) {
                    app(Notifications::class)->emit((int) $target->user_id, $viewer, 'reply', static::DOMAIN, $artwork, $id);
                }
            } elseif ($action === 'delete') {
                if ((int) $target->user_id !== $viewer) $this->fail(403, 'COMMENT_NOT_OWNER', '작성자만 댓글을 삭제할 수 있어요.');
                $db->table(static::COMMENTS)->where('id', $commentId)->update(['content' => '', 'mentions' => '[]', 'like_count' => 0, 'deleted_at' => now(), 'updated_at' => now()]);
                $db->table(static::LIKES)->where('comment_id', $commentId)->delete();
                $db->table('starttoo_reports')->where('target_type', static::REPORT_TYPE)->where('target_id', (string) $commentId)->where('status', 'pending')
                    ->update(['status' => 'target_deleted', 'open_key' => null, 'updated_at' => now()]);
                $db->table('starttoo_safety_audits')->insert(['actor_type' => 'member', 'actor_user_id' => $viewer,
                    'target_type' => static::REPORT_TYPE, 'target_id' => (string) $commentId, 'action' => 'delete']);
                $id = $commentId;
            } else {
                $key = ['comment_id' => $commentId, 'user_id' => $viewer];
                if ($input['active']) $inserted = $db->table(static::LIKES)->insertOrIgnore([...$key, 'created_at' => now()]);
                else $db->table(static::LIKES)->where($key)->delete();
                if ($input['active'] && $inserted) app(Notifications::class)->emit((int) $target->user_id, $viewer, 'comment_like', static::DOMAIN, $artwork, $commentId);
                if (!$input['active']) app(Notifications::class)->withdraw($viewer, 'comment_like', static::DOMAIN, $artwork, $commentId);
                $db->table(static::COMMENTS)->where('id', $commentId)->update(['like_count' => $db->table(static::LIKES)->where('comment_id', $commentId)->count()]);
                $id = $commentId;
            }
            $row = $this->visible($viewer)->where('c.id', $id)->first(['c.*', 'c_u.nickname', 'c_u.avatar_path']);
            $result = ['item' => $this->format($row, $viewer, $detail, true), 'total' => (int) ($this->counts([$artwork], $viewer)[$artwork] ?? 0)];
            $db->table(static::REQUESTS)->insert(['user_id' => $viewer, 'request_key' => $input['request_key'], 'payload_hash' => $hash,
                'response_payload' => json_encode($result, JSON_THROW_ON_ERROR)]);
            $sessions->authenticate($token, true);
            return $result;
        }, 3);
    }

    private function mentions(string $artwork, int $viewer, array $input): array
    {
        $mentions = [];
        foreach ($input['mentions'] ?? [] as $mention) {
            $id = (int) $mention['user_id'];
            if (isset($mentions[$id])) continue;
            if (!$this->visible($viewer)->where('c.'.static::CONTENT_ID, $artwork)->where('c.user_id', $id)->whereNull('c.deleted_at')->exists()) continue;
            $name = $this->db()->table('starttoo_users')->where('id', $id)->value('nickname');
            if ($name !== $mention['nickname'] || !preg_match('/(?<![가-힣a-z0-9@])@'.preg_quote($name, '/').'(?![가-힣a-z0-9])/u', $input['content'])) continue;
            $mentions[$id] = ['user_id' => $id, 'nickname' => $name];
        }
        return array_values($mentions);
    }

    private function format(object $row, ?int $viewer, array $detail, bool $canWrite): array
    {
        $deleted = $row->deleted_at !== null;
        $mentions = $deleted ? [] : json_decode($row->mentions, true, 512, JSON_THROW_ON_ERROR);
        $mentions = array_values(array_filter($mentions, function ($mention) use ($viewer) {
            return !app(Blocks::class)->between($viewer, (int) $mention['user_id'])
                && $this->db()->table('starttoo_users')->where('id', $mention['user_id'])->where('status', 'active')->where('moderation_hidden', false)->exists();
        }));
        return ['id' => (int) $row->id, 'parent_id' => $row->parent_id === null ? null : (int) $row->parent_id,
            'content' => $deleted ? '' : $row->content, 'deleted' => $deleted, 'created_at' => Carbon::parse($row->created_at)->toIso8601String(),
            'author' => $deleted ? null : ['id' => (int) $row->user_id, 'nickname' => $row->nickname,
                'avatar_url' => app(MediaUrls::class)->url($row->avatar_path, 300), 'is_artwork_author' => (int) $detail['author']['id'] === (int) $row->user_id],
            'mentions' => $mentions, 'like_count' => $deleted ? 0 : (int) $row->like_count,
            'liked' => !$deleted && $viewer !== null && $this->db()->table(static::LIKES)->where('comment_id', $row->id)->where('user_id', $viewer)->exists(),
            'can_delete' => !$deleted && $canWrite && (int) $row->user_id === $viewer,
            'can_reply' => !$deleted && $canWrite && $row->parent_id === null];
    }

    private function cursor(?string $value, string $artwork, string $sort): ?array
    {
        if ($value === null || $value === '') return null;
        if (strlen($value) > 512) $this->fail(422, 'INVALID_COMMENT_CURSOR', '댓글 조회 위치를 확인해주세요.');
        try {
            $cursor = json_decode(base64_decode(strtr($value, '-_', '+/'), true), true, 8, JSON_THROW_ON_ERROR);
            if (!is_array($cursor) || ($cursor['domain'] ?? 'artwork') !== static::DOMAIN || ($cursor['artwork'] ?? null) !== $artwork || ($cursor['sort'] ?? null) !== $sort
                || !is_int($cursor['id'] ?? null) || $cursor['id'] < 1 || ($sort === 'popular' && (!is_int($cursor['likes'] ?? null) || $cursor['likes'] < 0))) throw new \RuntimeException();
            return $cursor;
        } catch (\Throwable $e) { $this->fail(422, 'INVALID_COMMENT_CURSOR', '댓글 조회 위치를 확인해주세요.'); }
    }

    private function encode(array $value): string { return rtrim(strtr(base64_encode(json_encode(['domain' => static::DOMAIN, ...$value], JSON_THROW_ON_ERROR)), '+/', '-_'), '='); }
    private function fail(int $status, string $code, string $message): never { throw new HttpResponseException(response()->json(compact('code', 'message'), $status)); }
}
