<?php

namespace App\Services\Starttoo;

use App\Services\ProjectDatabase;
use Illuminate\Database\Query\Builder;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

/** 현재 유지 중인 좋아요/저장과 삭제하지 않은 작성 댓글을 본인 범위에서 조회한다. */
class Activities
{
    private const SOURCES = [
        1 => ['table' => 'starttoo_artwork_interactions', 'target' => 'artwork', 'kind' => null, 'name' => 'artwork_interaction'],
        2 => ['table' => 'starttoo_post_likes', 'target' => 'post', 'kind' => 'like', 'name' => 'post_like'],
        3 => ['table' => 'starttoo_post_saves', 'target' => 'post', 'kind' => 'save', 'name' => 'post_save'],
        4 => ['table' => 'starttoo_artwork_comments', 'target' => 'artwork', 'kind' => 'comment', 'name' => 'artwork_comment'],
        5 => ['table' => 'starttoo_post_comments', 'target' => 'post', 'kind' => 'comment', 'name' => 'post_comment'],
    ];

    public function listing(int $viewer, array $input): array
    {
        if (Validator::make($input, [
            'filter' => ['nullable', Rule::in(['all', 'like', 'comment', 'save'])],
            'cursor' => ['nullable', 'string', 'max:512'],
        ])->fails()) $this->fail('활동 조회 조건을 확인해주세요.');
        $filter = $input['filter'] ?? 'all';
        $cursor = $this->cursor($input['cursor'] ?? null, $viewer, $filter);
        $db = app(ProjectDatabase::class)->database();
        $union = null;
        foreach (self::SOURCES as $rank => $source) {
            if ($filter !== 'all' && $source['kind'] !== null && $source['kind'] !== $filter) continue;
            if ($source['kind'] === null && $filter === 'comment') continue;
            $q = $this->source($source, $viewer);
            if ($source['kind'] === null && $filter !== 'all') $q->where('a.kind', $filter);
            if ($cursor !== null) $q->where(function ($q) use ($cursor, $rank) {
                $q->where('a.created_at', '<', $cursor['at'])->orWhere(function ($same) use ($cursor, $rank) {
                    $same->where('a.created_at', $cursor['at']);
                    if ($rank < $cursor['rank']) return;
                    if ($rank === $cursor['rank']) $same->where('a.id', '<', $cursor['id']);
                    else $same->whereRaw('1 = 0');
                });
            });
            $q->selectRaw('a.id as activity_id, a.created_at, ? as source_rank', [$rank])
                ->selectRaw($source['kind'] === null ? 'a.kind as kind' : '? as kind', $source['kind'] === null ? [] : [$source['kind']])
                ->selectRaw('? as target_type, t.id as target_id, u.id as author_id, COALESCE(u.nickname, u.display_name) as author_name', [$source['target']])
                // 제목 없는 작품은 내용으로 대신 보여 준다.
                ->selectRaw($source['target'] === 'artwork' ? "t.kind as target_kind, COALESCE(NULLIF(t.title, ''), t.description) as target_text" : "'post' as target_kind, t.content as target_text")
                ->selectRaw($source['kind'] === 'comment' ? 'a.content as comment_text, a.like_count as comment_like_count' : 'NULL as comment_text, NULL as comment_like_count')
                ->orderByDesc('a.created_at')->orderByDesc('a.id')->limit(21);
            $candidate = $db->query()->fromSub($q, 's'.$rank)->select('*');
            if ($union === null) $union = $candidate;
            else $union->unionAll($candidate);
        }
        $rows = $db->query()->fromSub($union, 'activity')
            ->orderByDesc('created_at')->orderByDesc('source_rank')->orderByDesc('activity_id')->limit(21)->get();
        $page = $rows->take(20);
        $items = $page->map(function ($row) {
            $source = self::SOURCES[(int) $row->source_rank];
            return [
                'id' => $source['name'].':'.$row->activity_id, 'kind' => $row->kind,
                'created_at' => Carbon::parse($row->created_at)->toIso8601String(),
                'comment_excerpt' => $row->kind === 'comment' ? Str::limit($row->comment_text, 120, '…') : null,
                'comment_like_count' => $row->kind === 'comment' ? (int) $row->comment_like_count : null,
                'target' => [
                    'type' => $row->target_type, 'id' => $row->target_id, 'kind' => $row->target_kind,
                    'title' => Str::limit($row->target_text, 120, '…'),
                    'author_id' => (int) $row->author_id, 'author_name' => $row->author_name,
                    'thumbnail_url' => app(MediaUrls::class)->url($row->thumbnail_path, 300),
                ],
            ];
        })->all();
        $last = $page->last();
        $next = $rows->count() > 20 ? rtrim(strtr(base64_encode(json_encode([
            'v' => 1, 'viewer' => $viewer, 'filter' => $filter, 'at' => $last->created_at,
            'rank' => (int) $last->source_rank, 'id' => (int) $last->activity_id,
        ], JSON_THROW_ON_ERROR)), '+/', '-_'), '=') : null;
        return ['items' => $items, 'next_cursor' => $next];
    }

    private function source(array $source, int $viewer): Builder
    {
        $db = app(ProjectDatabase::class)->database();
        $art = $source['target'] === 'artwork';
        $foreign = $art ? 'artwork_id' : 'post_id';
        $images = $art ? 'starttoo_artwork_images' : 'starttoo_post_images';
        $q = $db->table($source['table'].' as a')
            ->join(($art ? 'starttoo_artworks' : 'starttoo_posts').' as t', 't.id', '=', 'a.'.$foreign)
            ->join('starttoo_users as u', 'u.id', '=', 't.user_id')
            ->where('a.user_id', $viewer)->where('t.moderation_hidden', false)
            ->where('u.status', 'active')->where('u.moderation_hidden', false);
        if ($art) $q->where('t.visibility', 'public');
        app(Blocks::class)->filter($q, 't.user_id', $viewer);
        $hidden = $art ? 'starttoo_hidden_artworks' : 'starttoo_hidden_posts';
        $q->whereNotExists(fn ($sub) => $sub->selectRaw('1')->from($hidden.' as h')
            ->whereColumn('h.'.$foreign, 't.id')->where('h.user_id', $viewer));
        $q->whereExists(fn ($sub) => $sub->selectRaw('1')->from($images.' as i')->whereColumn('i.'.$foreign, 't.id'));
        $q->selectSub($db->table($images.' as photo')->select('photo.path')
            ->whereColumn('photo.'.$foreign, 't.id')->orderBy('photo.position')->limit(1), 'thumbnail_path');
        if ($source['kind'] === 'comment') {
            $q->join('starttoo_users as cu', 'cu.id', '=', 'a.user_id')
                ->where('cu.status', 'active')->where('cu.moderation_hidden', false)
                ->whereNull('a.deleted_at')->where('a.moderation_hidden', false)
                ->where(function ($root) use ($source, $foreign, $viewer) {
                    $root->whereNull('a.parent_id')->orWhereExists(function ($parent) use ($source, $foreign, $viewer) {
                        $parent->selectRaw('1')->from($source['table'].' as pc')
                            ->join('starttoo_users as pu', 'pu.id', '=', 'pc.user_id')
                            ->whereColumn('pc.id', 'a.parent_id')->whereColumn('pc.'.$foreign, 't.id')
                            ->whereNull('pc.parent_id')->where('pc.moderation_hidden', false)
                            ->where('pu.status', 'active')->where('pu.moderation_hidden', false);
                        app(Blocks::class)->filter($parent, 'pc.user_id', $viewer);
                    });
                });
        }
        return $q;
    }

    private function cursor(?string $value, int $viewer, string $filter): ?array
    {
        if ($value === null || $value === '') return null;
        try {
            $decoded = base64_decode(strtr($value, '-_', '+/'), true);
            $c = json_decode($decoded === false ? '' : $decoded, true, 8, JSON_THROW_ON_ERROR);
            if (!is_array($c) || ($c['v'] ?? null) !== 1 || ($c['viewer'] ?? null) !== $viewer || ($c['filter'] ?? null) !== $filter
                || !is_int($c['rank'] ?? null) || !isset(self::SOURCES[$c['rank']])
                || !is_int($c['id'] ?? null) || $c['id'] < 1
                || Validator::make($c, ['at' => ['required', 'date_format:Y-m-d H:i:s']])->fails()) $this->fail('활동 목록을 처음부터 다시 조회해주세요.');
            return $c;
        } catch (\JsonException $e) { $this->fail('활동 조회 위치를 확인해주세요.'); }
    }

    private function fail(string $message): never
    {
        throw new HttpResponseException(response()->json(['code' => 'INVALID_ACTIVITY_CURSOR', 'message' => $message], 422));
    }
}
