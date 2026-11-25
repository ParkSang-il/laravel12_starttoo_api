<?php

namespace App\Services\Starttoo;

use App\Services\ProjectDatabase;
use Carbon\Carbon;
use Illuminate\Database\QueryException;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;

/**
 * Phase 5 커뮤니티(5-04 피드 · 5-05 게시판 · 5-06 상세 · 5-07 글쓰기).
 * - 카테고리: question 질문 · review 후기 · free 자유. 후기 글만 작가를 태그할 수 있다.
 * - 제목 필수(60자), 본문 5000자, 사진 10장. 본인이 수정 · 삭제.
 * - 좋아요는 회원당 1표, 댓글은 한 단계(답글 없음), 본인 댓글 삭제.
 * - 운영자가 숨긴 글 · 댓글, 차단 관계, 탈퇴/숨김 회원의 글은 목록 · 상세에서 뺀다.
 */
class Community
{
    public const CATEGORIES = ['question', 'review', 'free'];
    public const MAX_PHOTOS = 10;
    private const PAGE = 20;

    private function db() { return app(ProjectDatabase::class)->database(); }

    // ── 목록 ─────────────────────────────────────

    /**
     * 5-04 · 5-05 목록. category(없으면 전체) · sort: latest 최신순 · popular 인기순 · comments 댓글순
     * hot=1이면 첫 장에 「이번 주 인기글」 1건을 같이 준다.
     */
    public function feed(?int $viewer, array $input): array
    {
        $category = in_array($input['category'] ?? null, self::CATEGORIES, true) ? $input['category'] : null;
        $sort = in_array($input['sort'] ?? 'latest', ['latest', 'popular', 'comments'], true) ? ($input['sort'] ?? 'latest') : 'latest';
        $page = max(1, (int) ($input['page'] ?? 1));
        $q = $this->listQuery($viewer);
        if ($category) $q->where('p.category', $category);
        if (filter_var($input['mine'] ?? false, FILTER_VALIDATE_BOOLEAN) && $viewer !== null) $q->where('p.user_id', $viewer);
        $total = (clone $q)->count();
        match ($sort) {
            'popular' => $q->orderByDesc('p.like_count')->orderByDesc('p.id'),
            'comments' => $q->orderByDesc('p.comment_count')->orderByDesc('p.id'),
            default => $q->orderByDesc('p.id'),
        };
        $rows = $q->select('p.*')->offset(($page - 1) * self::PAGE)->limit(self::PAGE + 1)->get();
        $out = [
            'items' => $rows->take(self::PAGE)->map(fn ($p) => $this->format($p, $viewer))->values()->all(),
            'has_more' => $rows->count() > self::PAGE, 'total' => $total,
        ];
        if ($page === 1 && filter_var($input['hot'] ?? false, FILTER_VALIDATE_BOOLEAN)) {
            // 최근 7일 글 중 좋아요 + 댓글이 가장 많은 글
            $hot = $this->listQuery($viewer)->where('p.created_at', '>=', now()->subDays(7))
                ->whereRaw('(p.like_count + p.comment_count) > 0')
                ->orderByRaw('(p.like_count + p.comment_count * 2) desc')->orderByDesc('p.id')->select('p.*')->first();
            $out['hot'] = $hot ? $this->format($hot, $viewer) : null;
        }
        return $out;
    }

    /** 5-06 상세 + 댓글. 열 때마다 조회 수 +1(본인 제외). */
    public function detail(int $id, ?int $viewer, bool $count = true): array
    {
        $p = $this->visible($id, $viewer);
        if ($count && ($viewer === null || (int) $p->user_id !== $viewer)) {
            $this->db()->table('starttoo_community_posts')->where('id', $id)->increment('view_count');
            $p->view_count++;
        }
        return $this->format($p, $viewer) + ['comments' => $this->comments($id, $viewer)];
    }

    // ── 글 쓰기 · 수정 · 삭제 ──────────────────

    public function create(int $viewer, array $input): array
    {
        $in = $this->validate($input, true);
        $db = $this->db();
        if ($done = $db->table('starttoo_community_posts')->where('user_id', $viewer)->where('request_key', $in['request_key'])->first()) {
            return $this->format($done, $viewer);
        }
        $artist = $this->artistFor($viewer, $in);
        try {
            $id = $db->transaction(function () use ($db, $viewer, $in, $artist) {
                $paths = array_values(app(ArtworkUploads::class)->claim($viewer, array_values($in['media_ids'] ?? [])));
                return $db->table('starttoo_community_posts')->insertGetId([
                    'user_id' => $viewer, 'request_key' => $in['request_key'], 'category' => $in['category'],
                    'title' => $in['title'], 'content' => $in['content'] ?? '', 'images' => json_encode($paths),
                    'artist_id' => $artist, 'created_at' => now(), 'updated_at' => now(),
                ]);
            }, 3);
        } catch (QueryException $e) {
            // 동시에 두 번 눌렀을 때: 먼저 저장된 글을 돌려준다
            if ($done = $db->table('starttoo_community_posts')->where('user_id', $viewer)->where('request_key', $in['request_key'])->first()) return $this->format($done, $viewer);
            throw $e;
        }
        return $this->format($db->table('starttoo_community_posts')->where('id', $id)->first(), $viewer);
    }

    /** 수정: keep = 남길 기존 사진 순번, media_ids = 새 사진 */
    public function update(int $viewer, int $id, array $input): array
    {
        $in = $this->validate($input, false);
        $p = $this->own($viewer, $id);
        $old = json_decode((string) $p->images, true) ?: [];
        $keep = [];
        foreach (array_unique(array_map('intval', $in['keep'] ?? [])) as $i) {
            if (isset($old[$i])) $keep[] = $old[$i];
        }
        if (count($keep) + count($in['media_ids'] ?? []) > self::MAX_PHOTOS) {
            $this->fail(422, 'INVALID_COMMUNITY', '사진은 '.self::MAX_PHOTOS.'장까지 넣을 수 있어요.');
        }
        $artist = $this->artistFor($viewer, $in);
        $removed = array_values(array_diff($old, $keep));
        $this->db()->transaction(function () use ($viewer, $id, $in, $keep, $artist) {
            $new = array_values(app(ArtworkUploads::class)->claim($viewer, array_values($in['media_ids'] ?? [])));
            $this->db()->table('starttoo_community_posts')->where('id', $id)->update([
                'category' => $in['category'], 'title' => $in['title'], 'content' => $in['content'] ?? '',
                'images' => json_encode([...$keep, ...$new]), 'artist_id' => $artist, 'updated_at' => now(),
            ]);
        }, 3);
        $this->forget($removed);
        return $this->format($this->db()->table('starttoo_community_posts')->where('id', $id)->first(), $viewer);
    }

    public function delete(int $viewer, int $id): array
    {
        $p = $this->own($viewer, $id);
        $this->db()->table('starttoo_community_posts')->where('id', $id)->delete();
        $this->forget(json_decode((string) $p->images, true) ?: []);
        return ['deleted' => true];
    }

    // ── 좋아요 · 댓글 ────────────────────────────

    public function like(int $viewer, int $id, array $input): array
    {
        $this->visible($id, $viewer);
        $active = filter_var($input['active'] ?? true, FILTER_VALIDATE_BOOLEAN);
        $this->db()->transaction(function () use ($viewer, $id, $active) {
            $this->db()->table('starttoo_community_posts')->where('id', $id)->lockForUpdate()->first();
            if ($active) {
                $this->db()->table('starttoo_community_likes')->insertOrIgnore(['post_id' => $id, 'user_id' => $viewer, 'created_at' => now()]);
            } else {
                $this->db()->table('starttoo_community_likes')->where('post_id', $id)->where('user_id', $viewer)->delete();
            }
            $this->db()->table('starttoo_community_posts')->where('id', $id)->update([
                'like_count' => $this->db()->table('starttoo_community_likes')->where('post_id', $id)->count(),
            ]);
        }, 3);
        $p = $this->db()->table('starttoo_community_posts')->where('id', $id)->first();
        return ['like_count' => (int) $p->like_count, 'liked' => $active];
    }

    /** 댓글 등록 → 그 글의 댓글 목록 전체를 돌려준다 */
    public function comment(int $viewer, int $id, array $input): array
    {
        $this->visible($id, $viewer);
        $content = trim((string) ($input['content'] ?? ''));
        $key = (string) ($input['request_key'] ?? '');
        if ($content === '' || mb_strlen($content) > 1000 || Validator::make(['k' => $key], ['k' => ['required', 'uuid']])->fails()) {
            $this->fail(422, 'INVALID_COMMUNITY', '댓글은 1~1000자로 적어주세요.');
        }
        $db = $this->db();
        if (!$db->table('starttoo_community_comments')->where('user_id', $viewer)->where('request_key', $key)->exists()) {
            try {
                $db->transaction(function () use ($db, $viewer, $id, $content, $key) {
                    $db->table('starttoo_community_posts')->where('id', $id)->lockForUpdate()->first();
                    $db->table('starttoo_community_comments')->insert([
                        'post_id' => $id, 'user_id' => $viewer, 'request_key' => $key, 'content' => $content,
                        'created_at' => now(), 'updated_at' => now(),
                    ]);
                    $this->recount($id);
                }, 3);
            } catch (QueryException $e) {
                if (!$db->table('starttoo_community_comments')->where('user_id', $viewer)->where('request_key', $key)->exists()) throw $e;
            }
        }
        return $this->commentState($id, $viewer);
    }

    /** 댓글 삭제(본인) */
    public function deleteComment(int $viewer, int $commentId): array
    {
        $c = $this->db()->table('starttoo_community_comments')->where('id', $commentId)->first();
        if (!$c || (int) $c->user_id !== $viewer) $this->fail(404, 'COMMENT_NOT_FOUND', '댓글을 찾을 수 없어요.');
        $this->db()->transaction(function () use ($c) {
            $this->db()->table('starttoo_community_posts')->where('id', $c->post_id)->lockForUpdate()->first();
            $this->db()->table('starttoo_community_comments')->where('id', $c->id)->delete();
            $this->recount((int) $c->post_id);
        }, 3);
        return $this->commentState((int) $c->post_id, $viewer);
    }

    /**
     * 5-07 작가 태그 고르기. 검색어가 없으면 내가 시술을 마친 작가, 있으면 닉네임 · 상점 이름 검색.
     */
    public function artists(int $viewer, array $input): array
    {
        $term = trim((string) ($input['q'] ?? ''));
        $db = $this->db();
        if ($term === '') {
            $ids = $db->table('starttoo_bookings')->where('customer_id', $viewer)->where('status', 'completed')
                ->orderByDesc('starts_at')->limit(50)->pluck('artist_id')->unique()->values()->all();
        } else {
            $like = '%'.addcslashes(mb_substr($term, 0, 40), '%_\\').'%';
            $q = $db->table('starttoo_users as u')->leftJoin('starttoo_shops as s', 's.user_id', '=', 'u.id')
                ->where('u.artist_status', 'approved')->where('u.status', 'active')->where('u.moderation_hidden', false)
                ->where(fn ($w) => $w->where('u.nickname', 'like', $like)->orWhere('s.studio_name', 'like', $like));
            app(Blocks::class)->filter($q, 'u.id', $viewer);
            $ids = $q->orderBy('u.nickname')->limit(20)->pluck('u.id')->all();
        }
        $items = [];
        foreach ($ids as $id) {
            if ($a = $this->artistCard((int) $id, $viewer)) $items[] = $a;
        }
        return ['items' => $items];
    }

    // ── 내부 ─────────────────────────────────────

    private function listQuery(?int $viewer)
    {
        $q = $this->db()->table('starttoo_community_posts as p')->join('starttoo_users as u', 'u.id', '=', 'p.user_id')
            ->where('p.moderation_hidden', false)->where('u.status', 'active')->where('u.moderation_hidden', false);
        app(Blocks::class)->filter($q, 'p.user_id', $viewer);
        return $q;
    }

    /** 볼 수 있는 글. 본인은 숨김이어도 본다. */
    private function visible(int $id, ?int $viewer): object
    {
        $p = $this->db()->table('starttoo_community_posts')->where('id', $id)->first();
        if (!$p) $this->fail(404, 'COMMUNITY_NOT_FOUND', '글을 찾을 수 없어요.');
        if ($viewer === null || (int) $p->user_id !== $viewer) {
            $author = $this->db()->table('starttoo_users')->where('id', $p->user_id)->first(['status', 'moderation_hidden']);
            if ($p->moderation_hidden || !$author || $author->status !== 'active' || $author->moderation_hidden
                || app(Blocks::class)->between($viewer, (int) $p->user_id)) {
                $this->fail(404, 'COMMUNITY_NOT_FOUND', '글을 찾을 수 없어요.');
            }
        }
        return $p;
    }

    private function own(int $viewer, int $id): object
    {
        $p = $this->db()->table('starttoo_community_posts')->where('id', $id)->first();
        if (!$p || (int) $p->user_id !== $viewer) $this->fail(404, 'COMMUNITY_NOT_FOUND', '글을 찾을 수 없어요.');
        return $p;
    }

    private function validate(array $input, bool $creating): array
    {
        foreach (['title', 'content'] as $k) {
            if (is_string($input[$k] ?? null)) $input[$k] = trim($input[$k]);
        }
        $v = Validator::make($input, [
            'request_key' => [$creating ? 'required' : 'nullable', 'uuid'],
            'category' => ['required', Rule::in(self::CATEGORIES)],
            'title' => ['required', 'string', 'min:1', 'max:60'],
            'content' => ['nullable', 'string', 'max:5000'],
            'artist_id' => ['nullable', 'integer', 'min:1'],
            'media_ids' => ['sometimes', 'array', 'max:'.self::MAX_PHOTOS],
            'media_ids.*' => ['uuid', 'distinct'],
            'keep' => ['sometimes', 'array', 'max:'.self::MAX_PHOTOS],
            'keep.*' => ['integer', 'min:0'],
        ]);
        if ($v->fails()) {
            $this->fail(422, 'INVALID_COMMUNITY', ($input['title'] ?? '') === '' ? '제목을 입력해주세요.' : '글 내용을 확인해주세요. (제목 60자 · 본문 5000자 · 사진 '.self::MAX_PHOTOS.'장까지)');
        }
        return $v->validated();
    }

    /** 작가 태그: 후기 글에서만, 승인된 작가만(본인 제외) */
    private function artistFor(int $viewer, array $in): ?int
    {
        $id = (int) ($in['artist_id'] ?? 0);
        if ($in['category'] !== 'review' || $id < 1) return null;
        if ($id === $viewer || !$this->artistCard($id, $viewer)) $this->fail(422, 'INVALID_COMMUNITY', '태그할 수 없는 작가예요.');
        return $id;
    }

    private function artistCard(int $id, ?int $viewer): ?array
    {
        $u = $this->db()->table('starttoo_users')->where('id', $id)->first(['id', 'nickname', 'avatar_path', 'status', 'moderation_hidden', 'artist_status']);
        if (!$u || $u->status !== 'active' || $u->moderation_hidden || $u->artist_status !== 'approved' || app(Blocks::class)->between($viewer, $id)) return null;
        return [
            'id' => (int) $u->id, 'nickname' => $u->nickname,
            'studio_name' => $this->db()->table('starttoo_shops')->where('user_id', $id)->value('studio_name'),
            'avatar_url' => app(MediaUrls::class)->url($u->avatar_path, 300),
        ];
    }

    private function recount(int $postId): void
    {
        $this->db()->table('starttoo_community_posts')->where('id', $postId)->update([
            'comment_count' => $this->db()->table('starttoo_community_comments')->where('post_id', $postId)->where('moderation_hidden', false)->count(),
        ]);
    }

    private function commentState(int $postId, int $viewer): array
    {
        return [
            'comment_count' => (int) $this->db()->table('starttoo_community_posts')->where('id', $postId)->value('comment_count'),
            'comments' => $this->comments($postId, $viewer),
        ];
    }

    /** 댓글(오래된 순, 최근 300개까지) */
    private function comments(int $postId, ?int $viewer): array
    {
        $q = $this->db()->table('starttoo_community_comments as c')->join('starttoo_users as u', 'u.id', '=', 'c.user_id')
            ->where('c.post_id', $postId)->where('c.moderation_hidden', false)
            ->where('u.status', 'active')->where('u.moderation_hidden', false);
        app(Blocks::class)->filter($q, 'c.user_id', $viewer);
        $urls = app(MediaUrls::class);
        return $q->orderByDesc('c.id')->limit(300)
            ->get(['c.id', 'c.user_id', 'c.content', 'c.created_at', 'u.nickname', 'u.avatar_path', 'u.artist_status'])
            ->reverse()->values()->map(fn ($c) => [
                'id' => (int) $c->id, 'content' => $c->content,
                'author' => ['id' => (int) $c->user_id, 'nickname' => $c->nickname,
                    'avatar_url' => $urls->url($c->avatar_path, 300), 'is_artist' => $c->artist_status === 'approved'],
                'mine' => $viewer !== null && (int) $c->user_id === $viewer,
                'created_at' => Carbon::parse($c->created_at)->toIso8601String(),
            ])->all();
    }

    private function forget(array $paths): void
    {
        if (!$paths) return;
        try {
            app(ArtworkUploads::class)->forget($paths);
            app(ArtworkPhotos::class)->delete($paths);
        } catch (\Throwable $e) {
            report($e);
        }
    }

    public function format(object $p, ?int $viewer): array
    {
        $db = $this->db();
        $author = $db->table('starttoo_users')->where('id', $p->user_id)->first(['nickname', 'avatar_path', 'status', 'artist_status']);
        $withdrawn = ($author->status ?? null) !== 'active';
        $urls = app(MediaUrls::class);
        $images = json_decode((string) $p->images, true) ?: [];
        return [
            'id' => (int) $p->id, 'category' => $p->category, 'title' => $p->title, 'content' => (string) $p->content,
            'images' => array_map(fn ($x) => $urls->url($x, 1200), $images),
            'thumbs' => array_map(fn ($x) => $urls->url($x, 600), $images),
            'author' => ['id' => (int) $p->user_id, 'nickname' => $withdrawn ? '탈퇴한 회원' : ($author->nickname ?? ''),
                'avatar_url' => $withdrawn ? null : $urls->url($author->avatar_path ?? null, 300),
                'is_artist' => !$withdrawn && ($author->artist_status ?? null) === 'approved'],
            'artist' => $p->artist_id ? $this->artistCard((int) $p->artist_id, $viewer) : null,
            'like_count' => (int) $p->like_count, 'comment_count' => (int) $p->comment_count, 'view_count' => (int) $p->view_count,
            'liked' => $viewer !== null && $db->table('starttoo_community_likes')->where('post_id', $p->id)->where('user_id', $viewer)->exists(),
            'mine' => $viewer !== null && (int) $p->user_id === $viewer,
            'hidden' => (bool) $p->moderation_hidden,
            'edited' => Carbon::parse($p->updated_at)->gt(Carbon::parse($p->created_at)->addSeconds(5)),
            'created_at' => Carbon::parse($p->created_at)->toIso8601String(),
        ];
    }

    private function fail(int $status, string $code, string $message): never
    {
        throw new HttpResponseException(response()->json(compact('code', 'message'), $status));
    }
}
