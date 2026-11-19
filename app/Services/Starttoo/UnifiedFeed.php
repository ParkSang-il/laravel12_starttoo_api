<?php

namespace App\Services\Starttoo;

use App\Services\ProjectDatabase;
use Illuminate\Database\Query\Builder;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;

/** 작품/도안과 일반 게시물을 서버에서 합쳐 단일 커서로 조회한다. */
class UnifiedFeed
{
    public function list(array $input, ?int $viewerId, ?int $authorId = null): array
    {
        $artworks = app(Artworks::class);
        $styles = $artworks->options()['styles'];
        if (Validator::make($input, [
            'scope' => ['nullable', Rule::in(['public', 'following', 'saved'])],
            'style' => ['nullable', 'string', Rule::in(array_column($styles, 'id'))],
            'cursor' => ['nullable', 'string', 'max:512'],
        ])->fails()) $this->fail();
        $scope = $input['scope'] ?? 'public';
        $style = $input['style'] ?? null;
        if ($scope !== 'public' && $viewerId === null) {
            throw new HttpResponseException(response()->json(['code' => 'SESSION_EXPIRED', 'message' => '로그인이 필요해요.'], 401));
        }
        $cursor = null;
        if (!empty($input['cursor'])) {
            try {
                $decoded = base64_decode(strtr($input['cursor'], '-_', '+/'), true);
                $cursor = json_decode($decoded === false ? '' : $decoded, true, 512, JSON_THROW_ON_ERROR);
                if (!is_array($cursor) || Validator::make($cursor, [
                    'v' => ['required', Rule::in([2])], 'at' => ['required', 'date_format:Y-m-d H:i:s'],
                    'id' => ['required', 'uuid'], 't' => ['required', Rule::in(['artwork', 'post'])],
                    'd' => ['required', Rule::in(['next', 'previous'])], 'scope' => ['required', Rule::in([$scope])],
                ])->fails() || (($cursor['style'] ?? null) !== $style || ($cursor['author'] ?? null) !== $authorId || ($cursor['viewer'] ?? null) !== $viewerId)) $this->fail();
            } catch (\JsonException $e) {
                $this->fail();
            }
        }
        $backward = ($cursor['d'] ?? 'next') === 'previous';
        $direction = $backward ? 'asc' : 'desc';
        $art = $artworks->feedQuery($viewerId, $scope, $authorId)
            ->select('a.id', 'a.created_at')->selectRaw("'artwork' as entry_type");
        if ($style !== null) $art->whereJsonContains('a.styles', $style);
        $artTime = 'a.created_at';
        if ($scope === 'saved') {
            $art->join('starttoo_artwork_interactions as sv', fn ($j) => $j->on('sv.artwork_id', '=', 'a.id')->where('sv.user_id', $viewerId)->where('sv.kind', 'save'));
            $artTime = 'sv.created_at';
        }
        $art->select('a.id', $artTime.' as created_at')->selectRaw("'artwork' as entry_type");
        $total = ($scope === 'saved' || $authorId !== null) && $cursor === null ? (clone $art)->count() : null;
        $this->boundary($art, 'a', 'artwork', $cursor, $backward, $artTime);
        $art->orderBy($artTime, $direction)->orderBy('a.id', $direction)->limit(11);
        $db = app(ProjectDatabase::class)->database();
        $candidates = $db->query()->fromSub($art, 'art_candidates')->select('*');
        // 일반 게시물에는 타투 스타일이 없으므로 스타일 필터에서는 제외한다.
        if ($style === null) {
            $posts = app(Posts::class)->feedQuery($viewerId, $scope, $authorId)
                ->select('p.id', 'p.created_at')->selectRaw("'post' as entry_type");
            $postTime = 'p.created_at';
            if ($scope === 'saved') {
                $posts->join('starttoo_post_saves as sp', fn ($j) => $j->on('sp.post_id', '=', 'p.id')->where('sp.user_id', $viewerId));
                $postTime = 'sp.created_at';
            }
            $posts->select('p.id', $postTime.' as created_at')->selectRaw("'post' as entry_type");
            if ($total !== null) $total += (clone $posts)->count();
            $this->boundary($posts, 'p', 'post', $cursor, $backward, $postTime);
            $posts->orderBy($postTime, $direction)->orderBy('p.id', $direction)->limit(11);
            $candidates->unionAll($db->query()->fromSub($posts, 'post_candidates')->select('*'));
        }
        // 각 원본에서 최대 11개만 읽고 최대 22개를 합쳐 페이지 경계를 결정한다.
        $rows = $db->query()->fromSub($candidates, 'feed')
            ->orderBy('created_at', $direction)->orderBy('id', $direction)->orderBy('entry_type', $direction)->limit(11)->get();
        $hasMore = $rows->count() > 10;
        $page = $rows->take(10)->values();
        if ($backward) $page = $page->reverse()->values();
        $cards = [];
        $artIds = $page->where('entry_type', 'artwork')->pluck('id')->all();
        if ($artIds) {
            $payload = ['scope' => $scope];
            if ($style !== null) $payload['style'] = $style;
            foreach ($artworks->feed($payload, $viewerId, $authorId, $artIds)['items'] as $card) $cards['artwork:'.$card['id']] = $card;
        }
        $postIds = $page->where('entry_type', 'post')->pluck('id')->all();
        foreach (app(Posts::class)->feedItems($postIds, $viewerId, $scope, $authorId) as $card) $cards['post:'.$card['id']] = $card;
        $items = [];
        foreach ($page as $row) {
            if (isset($cards[$row->entry_type.':'.$row->id])) $items[] = $cards[$row->entry_type.':'.$row->id];
        }
        $encode = fn ($row, $d) => $row ? rtrim(strtr(base64_encode(json_encode([
            'v' => 2, 'author' => $authorId, 'viewer' => $viewerId, 'at' => $row->created_at, 'id' => $row->id, 't' => $row->entry_type,
            'd' => $d, 'scope' => $scope, 'style' => $style,
        ], JSON_THROW_ON_ERROR)), '+/', '-_'), '=') : null;
        return ['items' => $items, 'styles' => $styles, 'total' => $total,
            'next_cursor' => ($backward || $hasMore) ? $encode($page->last(), 'next') : null,
            'previous_cursor' => ($backward ? $hasMore : $cursor !== null) ? $encode($page->first(), 'previous') : null];
    }

    private function boundary(Builder $query, string $alias, string $type, ?array $cursor, bool $backward, string $time): void
    {
        if ($cursor === null) return;
        $op = $backward ? '>' : '<';
        $query->where(function ($q) use ($alias, $type, $cursor, $op, $time) {
            $q->where($time, $op, $cursor['at'])
                ->orWhere(function ($sameTime) use ($alias, $type, $cursor, $op, $time) {
                    $sameTime->where($time, $cursor['at'])
                        ->where(function ($same) use ($alias, $type, $cursor, $op) {
                            $same->where($alias.'.id', $op, $cursor['id'])
                                ->orWhere(fn ($tie) => $tie->where($alias.'.id', $cursor['id'])->whereRaw('? '.$op.' ?', [$type, $cursor['t']]));
                        });
                });
        });
    }

    private function fail(): never
    {
        throw new HttpResponseException(response()->json(['code' => 'INVALID_FEED_FILTER', 'message' => '피드 조회 조건을 확인하고 새로고침해주세요.'], 422));
    }
}
