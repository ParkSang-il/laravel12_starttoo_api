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
    public function list(array $input, ?int $viewerId, ?int $authorId = null, ?int $savedOwner = null): array
    {
        $artworks = app(Artworks::class);
        $styles = $artworks->options()['styles'];
        if (Validator::make($input, [
            'scope' => ['nullable', Rule::in(['public', 'following', 'saved'])],
            'style' => ['nullable', 'string', Rule::in(array_column($styles, 'id'))],
            'cursor' => ['nullable', 'string', 'max:512'],
            'collection' => ['nullable', 'integer', 'min:1'],
        ])->fails()) $this->fail();
        $scope = $input['scope'] ?? 'public';
        $style = $input['style'] ?? null;
        $owner = $savedOwner ?? $viewerId;
        // 저장 컬렉션: 본인 컬렉션이거나 공개 컬렉션만 조회한다(전체 저장 목록 공개 설정과 별개).
        $collection = isset($input['collection']) ? (int) $input['collection'] : null;
        if ($collection !== null) {
            if ($scope !== 'saved' || $owner === null) $this->fail();
            if ($savedOwner !== null) app(PublicProfiles::class)->user($savedOwner, $viewerId);
            app(Collections::class)->readable($collection, $owner, $viewerId);
        } elseif ($savedOwner !== null) {
            app(PublicProfiles::class)->user($savedOwner, $viewerId);
            if ($scope !== 'saved' || ($savedOwner !== $viewerId && !app(UserPreferences::class)->savedPublic($savedOwner))) {
                throw new HttpResponseException(response()->json(['code' => 'SAVED_NOT_PUBLIC', 'message' => '공개하지 않은 저장 목록이에요.'], 403));
            }
        }
        if ($scope !== 'public' && $viewerId === null && !($scope === 'saved' && $savedOwner !== null)) {
            throw new HttpResponseException(response()->json(['code' => 'SESSION_EXPIRED', 'message' => '로그인이 필요해요.'], 401));
        }
        $raw = null;
        if (!empty($input['cursor'])) {
            try {
                $decoded = base64_decode(strtr($input['cursor'], '-_', '+/'), true);
                $raw = json_decode($decoded === false ? '' : $decoded, true, 512, JSON_THROW_ON_ERROR);
            } catch (\JsonException $e) {
                $this->fail();
            }
            if (!is_array($raw)) $this->fail();
        }
        // 홈(전체 스타일)은 추천 순서(docs/feed-ranking.md). 팔로잉 · 스타일 · 저장 · 프로필은 최신순.
        $ranked = $scope === 'public' && $style === null && $authorId === null && $savedOwner === null && $collection === null
            && app(FeedRanking::class)->enabled();
        if ($ranked && ($raw === null || ($raw['v'] ?? null) === 3)) return $this->rankedPage($raw, $viewerId, $styles);
        // 추천 순서가 끝난 뒤 이어지는 최신순(추천 후보보다 오래된 글) 커서: rank = 세션, rl = 추천 개수, n = 꼬리 페이지 번호
        $tail = null;
        $cursor = $raw;
        if ($cursor !== null) {
            if (Validator::make($cursor, [
                'v' => ['required', Rule::in([2])], 'at' => ['required', 'date_format:Y-m-d H:i:s'],
                'id' => ['required', 'uuid'], 't' => ['required', Rule::in(['artwork', 'post'])],
                'd' => ['required', Rule::in(['next', 'previous'])], 'scope' => ['required', Rule::in([$scope])],
            ])->fails() || (($cursor['style'] ?? null) !== $style || ($cursor['author'] ?? null) !== $authorId || ($cursor['viewer'] ?? null) !== $viewerId)
                || ($scope === 'saved' && ($cursor['saved_owner'] ?? $viewerId) !== $owner)
                || ($cursor['collection'] ?? null) !== $collection) $this->fail();
            if ($ranked && isset($cursor['rank'])) {
                if (Validator::make($cursor, ['rank' => ['required', 'string', 'regex:/^[a-z0-9]{1,32}$/'],
                    'rl' => ['required', 'integer', 'min:0'], 'n' => ['required', 'integer', 'min:0']])->fails()) $this->fail();
                $tail = ['rank' => $cursor['rank'], 'rl' => (int) $cursor['rl'], 'n' => (int) $cursor['n']];
            }
        }
        $backward = ($cursor['d'] ?? 'next') === 'previous';
        $direction = $backward ? 'asc' : 'desc';
        $art = $artworks->feedQuery($viewerId, $scope, $authorId)
            ->select('a.id', 'a.created_at')->selectRaw("'artwork' as entry_type");
        if ($scope === 'saved' && $owner !== $viewerId && $viewerId !== null) {
            $art->whereNotExists(fn ($q) => $q->selectRaw('1')->from('starttoo_hidden_artworks as vh')->whereColumn('vh.artwork_id', 'a.id')->where('vh.user_id', $viewerId));
        }
        if ($style !== null) $art->whereJsonContains('a.styles', $style);
        $artTime = 'a.created_at';
        if ($scope === 'saved') {
            $art->join('starttoo_artwork_interactions as sv', fn ($j) => $j->on('sv.artwork_id', '=', 'a.id')->where('sv.user_id', $owner)->where('sv.kind', 'save'));
            $artTime = 'sv.created_at';
            if ($collection !== null) {
                $art->join('starttoo_collection_items as ci', fn ($j) => $j->on('ci.target_id', '=', 'a.id')->where('ci.target_type', 'artwork')->where('ci.collection_id', $collection));
                $artTime = 'ci.created_at';
            }
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
            if ($scope === 'saved' && $owner !== $viewerId && $viewerId !== null) {
                $posts->whereNotExists(fn ($q) => $q->selectRaw('1')->from('starttoo_hidden_posts as vh')->whereColumn('vh.post_id', 'p.id')->where('vh.user_id', $viewerId));
            }
            $postTime = 'p.created_at';
            if ($scope === 'saved') {
                $posts->join('starttoo_post_saves as sp', fn ($j) => $j->on('sp.post_id', '=', 'p.id')->where('sp.user_id', $owner));
                $postTime = 'sp.created_at';
                if ($collection !== null) {
                    $posts->join('starttoo_collection_items as ci', fn ($j) => $j->on('ci.target_id', '=', 'p.id')->where('ci.target_type', 'post')->where('ci.collection_id', $collection));
                    $postTime = 'ci.created_at';
                }
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
        $items = $this->cards($page, $scope, $style, $viewerId, $authorId, $savedOwner);
        $encode = fn ($row, $d) => $row ? $this->encode([
            'v' => 2, 'author' => $authorId, 'viewer' => $viewerId, 'at' => $row->created_at, 'id' => $row->id, 't' => $row->entry_type,
            'd' => $d, 'scope' => $scope, 'style' => $style, 'saved_owner' => $scope === 'saved' ? $owner : null,
            'collection' => $collection,
            ...($tail ? ['rank' => $tail['rank'], 'rl' => $tail['rl'], 'n' => $d === 'next' ? $tail['n'] + 1 : max(0, $tail['n'] - 1)] : []),
        ]) : null;
        $previous = ($backward ? $hasMore : $cursor !== null) ? $encode($page->first(), 'previous') : null;
        // 꼬리 첫 페이지의 이전 페이지는 추천 순서의 마지막 페이지
        if ($tail && $tail['n'] === 0) $previous = $this->encode(['v' => 3, 's' => $tail['rank'], 'viewer' => $viewerId,
            'o' => max(0, intdiv(max(0, $tail['rl'] - 1), 10) * 10)]);
        return ['items' => $items, 'styles' => $styles, 'total' => $total,
            'next_cursor' => ($backward || $hasMore) ? $encode($page->last(), 'next') : null,
            'previous_cursor' => $previous];
    }

    /** 홈 추천 순서의 한 페이지(v3 커서: s = 세션, o = 시작 위치). 순서는 FeedRanking 이 만든다. */
    private function rankedPage(?array $raw, ?int $viewerId, array $styles): array
    {
        $ranking = app(FeedRanking::class);
        if ($raw !== null && (Validator::make($raw, ['s' => ['required', 'string', 'regex:/^[a-z0-9]{1,32}$/'],
            'o' => ['required', 'integer', 'min:0', 'max:100000']])->fails() || ($raw['viewer'] ?? null) !== $viewerId)) $this->fail();
        $sid = $raw['s'] ?? $ranking->newSession($viewerId);
        $offset = (int) ($raw['o'] ?? 0);
        $session = $ranking->session($viewerId, $sid);
        $keys = $session['keys'];
        $page = collect(array_slice($keys, $offset, 10))->map(function ($key) {
            [$type, $id] = explode(':', $key, 2);
            return (object) ['entry_type' => $type, 'id' => $id];
        });
        $v3 = fn (int $o) => $this->encode(['v' => 3, 's' => $sid, 'viewer' => $viewerId, 'o' => $o]);
        $next = null;
        if ($offset + 10 < count($keys)) $next = $v3($offset + 10);
        elseif ($floor = $session['floor']) $next = $this->encode([
            'v' => 2, 'author' => null, 'viewer' => $viewerId, 'at' => $floor['at'], 'id' => $floor['id'], 't' => $floor['t'],
            'd' => 'next', 'scope' => 'public', 'style' => null, 'saved_owner' => null, 'collection' => null,
            'rank' => $sid, 'rl' => count($keys), 'n' => 0,
        ]);
        return ['items' => $this->cards($page, 'public', null, $viewerId, null, null), 'styles' => $styles, 'total' => null,
            'next_cursor' => $next, 'previous_cursor' => $offset > 0 ? $v3(max(0, $offset - 10)) : null];
    }

    /** 고른 순서대로 카드를 만든다. 그사이 지워졌거나 볼 수 없게 된 글은 빠진다. */
    private function cards($page, string $scope, ?string $style, ?int $viewerId, ?int $authorId, ?int $savedOwner): array
    {
        $cards = [];
        $artIds = $page->where('entry_type', 'artwork')->pluck('id')->all();
        if ($artIds) {
            $payload = ['scope' => $scope];
            if ($style !== null) $payload['style'] = $style;
            foreach (app(Artworks::class)->feed($payload, $viewerId, $authorId, $artIds, $savedOwner)['items'] as $card) $cards['artwork:'.$card['id']] = $card;
        }
        $postIds = $page->where('entry_type', 'post')->pluck('id')->all();
        foreach (app(Posts::class)->feedItems($postIds, $viewerId, $scope, $authorId) as $card) $cards['post:'.$card['id']] = $card;
        $items = [];
        foreach ($page as $row) {
            if (isset($cards[$row->entry_type.':'.$row->id])) $items[] = $cards[$row->entry_type.':'.$row->id];
        }
        return $items;
    }

    private function encode(array $cursor): string
    {
        return rtrim(strtr(base64_encode(json_encode($cursor, JSON_THROW_ON_ERROR)), '+/', '-_'), '=');
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
