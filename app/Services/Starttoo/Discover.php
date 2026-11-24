<?php

namespace App\Services\Starttoo;

use App\Services\ProjectDatabase;
use Illuminate\Database\Query\Builder;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;

/** 2-01~06. 기존 공개 작품·상점·팔로우를 조회한다. */
class Discover
{
    private const TYPES = ['all', 'work', 'design', 'artist', 'shop'];
    private const BUDGETS = [
        ['id' => 'under10', 'label' => '10만원 이하', 'min' => 0, 'max' => 100000],
        ['id' => '10to30', 'label' => '10~30만원', 'min' => 100000, 'max' => 300000],
        ['id' => '30to50', 'label' => '30~50만원', 'min' => 300000, 'max' => 500000],
        ['id' => 'over50', 'label' => '50만원 이상', 'min' => 500000, 'max' => 100000000],
    ];

    private function db() { return app(ProjectDatabase::class)->database(); }

    public function options(): array
    {
        return ['styles' => app(Artworks::class)->options()['styles'],
            'parts' => app(Artworks::class)->options()['body_parts'],
            'regions' => app(Profiles::class)->options()['regions'], 'budgets' => self::BUDGETS];
    }

    public function explore(?int $viewer): array
    {
        $f = $this->filters([]);
        $counts = []; $covers = []; $tags = [];
        // 검색과 같은 공개·숨김·차단 조건으로 집계한다. 샘플 수치나 가짜 상승 표시를 만들지 않는다.
        foreach ($this->artworks($f, $viewer)->select('a.id', 'a.styles', 'a.tags')
            ->orderByDesc('a.created_at')->orderByDesc('a.id')->cursor() as $row) {
            foreach (array_unique(json_decode($row->styles ?? '[]', true) ?: []) as $style) {
                $counts[$style] = ($counts[$style] ?? 0) + 1;
                $covers[$style] ??= $row->id;
            }
            foreach (array_unique(json_decode($row->tags ?? '[]', true) ?: []) as $tag) {
                if (mb_strlen((string) $tag) > 99) continue;
                $tags[$tag] = ($tags[$tag] ?? 0) + 1;
            }
        }
        $images = $covers ? $this->db()->table('starttoo_artwork_images')
            ->whereIn('artwork_id', array_values($covers))->orderBy('position')
            ->get(['artwork_id', 'path'])->groupBy('artwork_id') : collect();
        $styles = array_map(fn ($style) => [...$style, 'count' => $counts[$style['id']] ?? 0,
            'image_url' => app(MediaUrls::class)->url($images->get($covers[$style['id']] ?? '')?->first()?->path, 500)],
            $this->options()['styles']);
        $tagRows = [];
        foreach ($tags as $tag => $count) $tagRows[] = ['tag' => (string) $tag, 'count' => $count];
        usort($tagRows, fn ($a, $b) => ($b['count'] <=> $a['count']) ?: strcmp($a['tag'], $b['tag']));
        return ['styles' => $styles, 'tags' => array_slice($tagRows, 0, 20)];
    }

    private function filters(array $input): array
    {
        $options = $this->options();
        $rules = ['q' => ['nullable', 'string', 'max:100'], 'type' => ['nullable', Rule::in(self::TYPES)],
            'sort' => ['nullable', Rule::in(['popular', 'latest'])], 'cursor' => ['nullable', 'string', 'max:4096'],
            'consult_only' => ['sometimes', 'boolean'], 'available_only' => ['sometimes', 'boolean'],
            'count_only' => ['sometimes', 'boolean']];
        foreach (['styles', 'parts', 'regions', 'budgets'] as $key) {
            $rules[$key] = ['sometimes', 'array', 'max:30'];
            $rules[$key.'.*'] = ['string', 'distinct', Rule::in(array_column($options[$key], 'id'))];
        }
        if (Validator::make($input, $rules)->fails()) $this->fail();
        $f = ['q' => trim($input['q'] ?? ''), 'type' => $input['type'] ?? 'all', 'sort' => $input['sort'] ?? 'popular',
            'consult_only' => (bool) ($input['consult_only'] ?? false), 'available_only' => (bool) ($input['available_only'] ?? false)];
        foreach (['styles', 'parts', 'regions', 'budgets'] as $key) {
            $values = array_values($input[$key] ?? []);
            sort($values);
            $f[$key] = $values;
        }
        if (in_array('all', $f['regions'], true)) $f['regions'] = [];
        return $f;
    }

    public function search(array $input, ?int $viewer): array
    {
        $f = $this->filters($input);
        // 정렬 기간 변경 전 커서를 새 점수에 재사용하지 않는다.
        $signature = hash('sha256', json_encode(['likes48h-follow7d-hashtags-v3', $f, $viewer], JSON_THROW_ON_ERROR));
        $at = now()->format('Y-m-d H:i:s');
        $after = [];
        if (!empty($input['cursor'])) {
            try {
                $cursor = json_decode(base64_decode($input['cursor'], true) ?: '', true, 512, JSON_THROW_ON_ERROR);
                if (!is_array($cursor) || ($cursor['f'] ?? '') !== $signature || Validator::make($cursor, [
                    'at' => ['required', 'date_format:Y-m-d H:i:s', 'before_or_equal:now'],
                    'after' => ['required', 'array:artworks,artists,shops'],
                ])->fails()) $this->fail();
                $at = $cursor['at'];
                $after = $cursor['after'];
                foreach ($after as $key => $value) {
                    if ($value === false) continue;
                    if (!is_array($value) || Validator::make($value, [
                        'score' => ['required', 'integer', 'min:0'], 'at' => ['required', 'date_format:Y-m-d H:i:s'],
                        'id' => $key === 'artworks' ? ['required', 'uuid'] : ['required', 'integer', 'min:1'],
                    ])->fails()) $this->fail();
                }
            } catch (\JsonException $e) { $this->fail(); }
        }
        $art = $this->artworks($f, $viewer)->where('a.created_at', '<=', $at);
        $people = $this->people($f, $viewer)->where('shop.created_at', '<=', $at);
        $counts = ['work' => (clone $art)->where('a.kind', 'work')->count(), 'design' => (clone $art)->where('a.kind', 'design')->count(),
            'artist' => (clone $people)->count(), 'shop' => (clone $people)->count()];
        $result = ['artworks' => [], 'artists' => [], 'shops' => [], 'counts' => $counts, 'next_cursor' => null];
        if (!empty($input['count_only'])) return $result;
        $next = [];
        if (in_array($f['type'], ['all', 'work', 'design'], true)) {
            if ($f['type'] !== 'all') $art->where('a.kind', $f['type']);
            $likes = $this->db()->table('starttoo_artwork_interactions')->where('kind', 'like')
                ->whereBetween('created_at', [date('Y-m-d H:i:s', strtotime($at.' -48 hours')), $at])
                ->groupBy('artwork_id')->selectRaw('artwork_id, COUNT(*) as score');
            $art->leftJoinSub($likes, 'rank_likes', fn ($j) => $j->on('rank_likes.artwork_id', '=', 'a.id'));
            $score = $f['sort'] === 'popular' ? 'COALESCE(rank_likes.score, 0)' : 'CAST(0 AS UNSIGNED)';
            [$rows, $next['artworks']] = $this->page($art->select('a.*')->selectRaw($score.' as search_score'), 'a.id', 'a.created_at', $score, $after['artworks'] ?? null);
            $result['artworks'] = $this->artworkCards($rows);
        }
        $follows = $this->db()->table('starttoo_follows')->whereBetween('created_at', [date('Y-m-d H:i:s', strtotime($at.' -7 days')), $at])
            ->groupBy('target_user_id')->selectRaw('target_user_id, COUNT(*) as score');
        $people->leftJoinSub($follows, 'rank_follows', fn ($j) => $j->on('rank_follows.target_user_id', '=', 'owner.id'));
        $score = $f['sort'] === 'popular' ? 'COALESCE(rank_follows.score, 0)' : 'CAST(0 AS UNSIGNED)';
        foreach (['artists' => 'artist', 'shops' => 'shop'] as $group => $type) {
            if (!in_array($f['type'], ['all', $type], true)) continue;
            [$rows, $next[$group]] = $this->page((clone $people)->select('shop.*', 'owner.avatar_path', 'owner.nickname')
                ->selectRaw($score.' as search_score'), 'owner.id', 'shop.created_at', $score, $after[$group] ?? null);
            $result[$group] = $rows->map(fn ($row) => [...app(Studios::class)->view($row),
                'nickname' => $row->nickname, 'avatar_url' => app(MediaUrls::class)->url($row->avatar_path, 300)])->all();
        }
        if (collect($next)->contains(fn ($value) => $value !== false)) {
            $result['next_cursor'] = base64_encode(json_encode(['f' => $signature, 'at' => $at, 'after' => $next], JSON_THROW_ON_ERROR));
        }
        return $result;
    }

    private function page(Builder $q, string $id, string $time, string $score, mixed $after): array
    {
        if ($after === false) return [collect(), false];
        if ($after !== null) {
            $q->where(fn ($w) => $w->whereRaw($score.' < ?', [$after['score']])
                ->orWhere(fn ($same) => $same->whereRaw($score.' = ?', [$after['score']])
                    ->where(fn ($tie) => $tie->where($time, '<', $after['at'])
                        ->orWhere(fn ($last) => $last->where($time, $after['at'])->where($id, '<', $after['id'])))));
        }
        $rows = $q->orderByRaw($score.' DESC')->orderByDesc($time)->orderByDesc($id)->limit(13)->get();
        $page = $rows->take(12)->values();
        $last = $page->last();
        return [$page, $rows->count() > 12 ? ['score' => (int) $last->search_score, 'at' => $last->created_at,
            'id' => $id === 'a.id' ? $last->id : (int) $last->user_id] : false];
    }

    private function artworks(array $f, ?int $viewer): Builder
    {
        $q = app(Artworks::class)->feedQuery($viewer, 'public')->leftJoin('starttoo_shops as search_shop',
            fn ($j) => $j->on('search_shop.user_id', '=', 'u.id')->where('u.artist_status', 'approved'));
        $options = $this->options();
        foreach (preg_split('/\s+/u', $f['q'], -1, PREG_SPLIT_NO_EMPTY) ?: [] as $word) {
            if (str_starts_with($word, '#')) {
                $q->whereJsonContains('a.tags', mb_strtolower(mb_substr($word, 1), 'UTF-8'));
                continue;
            }
            $like = '%'.addcslashes($word, '\\%_').'%';
            $q->where(function ($w) use ($like, $word, $options) {
                $w->where('a.title', 'like', $like)->orWhere('a.description', 'like', $like)
                    ->orWhere('u.nickname', 'like', $like)->orWhere('search_shop.studio_name', 'like', $like);
                foreach ($options['styles'] as $style) if (mb_stripos($style['label'], $word) !== false) $w->orWhereJsonContains('a.styles', $style['id']);
                foreach ($options['parts'] as $part) if (mb_stripos($part['label'], $word) !== false) $w->orWhere('a.body_part', $part['id']);
            });
        }
        if ($f['regions']) $q->whereIn($this->db()->raw('COALESCE(search_shop.region, u.interest_region)'), $f['regions']);
        if ($f['parts']) $q->whereIn('a.body_part', $f['parts']);
        if ($f['styles']) $q->where(function ($w) use ($f) { foreach ($f['styles'] as $style) $w->orWhereJsonContains('a.styles', $style); });
        if ($f['consult_only']) $q->where('search_shop.consult_status', 'open');
        if ($f['available_only']) $q->where('a.kind', 'design')->where('a.design_status', 'available');
        $this->budget($q, $f['budgets'], "CASE WHEN a.kind = 'design' THEN a.price ELSE COALESCE(search_shop.price_min, IF(search_shop.price_max IS NOT NULL, 0, NULL)) END",
            "CASE WHEN a.kind = 'design' THEN a.price ELSE COALESCE(search_shop.price_max, IF(search_shop.price_min IS NOT NULL, 100000000, NULL)) END");
        return $q;
    }

    private function people(array $f, ?int $viewer): Builder
    {
        $q = $this->db()->table('starttoo_shops as shop')->join('starttoo_users as owner', 'owner.id', '=', 'shop.user_id')
            ->where('owner.artist_status', 'approved')->where('owner.status', 'active')->where('owner.moderation_hidden', false)->whereNotNull('owner.profile_completed_at');
        app(Blocks::class)->filter($q, 'owner.id', $viewer);
        foreach (preg_split('/\s+/u', $f['q'], -1, PREG_SPLIT_NO_EMPTY) ?: [] as $word) {
            $like = '%'.addcslashes($word, '\\%_').'%';
            $match = $f; $match['q'] = $word;
            $visibleWork = $this->artworks($match, $viewer)->select('a.id')->whereColumn('a.user_id', 'owner.id');
            if (str_starts_with($word, '#')) {
                $q->whereExists($visibleWork);
                continue;
            }
            $q->where(fn ($w) => $w->where('owner.nickname', 'like', $like)
                ->orWhere('shop.studio_name', 'like', $like)->orWhere('shop.bio', 'like', $like)->orWhereExists($visibleWork));
        }
        if ($f['regions']) $q->whereIn('shop.region', $f['regions']);
        if ($f['consult_only']) $q->where('shop.consult_status', 'open');
        $this->budget($q, $f['budgets'], 'COALESCE(shop.price_min, IF(shop.price_max IS NOT NULL, 0, NULL))',
            'COALESCE(shop.price_max, IF(shop.price_min IS NOT NULL, 100000000, NULL))');
        if ($f['styles'] || $f['parts'] || $f['available_only']) {
            $match = $f; $match['q'] = ''; $match['regions'] = []; $match['budgets'] = [];
            $q->whereExists($this->artworks($match, $viewer)->selectRaw('1')->whereColumn('a.user_id', 'owner.id'));
        }
        return $q;
    }

    private function budget(Builder $q, array $ids, string $min, string $max): void
    {
        if (!$ids) return;
        $q->where(function ($w) use ($ids, $min, $max) {
            foreach (self::BUDGETS as $budget) if (in_array($budget['id'], $ids, true)) {
                $w->orWhere(fn ($range) => $range->whereRaw($min.' <= ?', [$budget['max']])->whereRaw($max.' >= ?', [$budget['min']]));
            }
        });
    }

    private function artworkCards($rows): array
    {
        $images = $rows->isEmpty() ? collect() : $this->db()->table('starttoo_artwork_images')->whereIn('artwork_id', $rows->pluck('id')->all())
            ->orderBy('position')->get(['artwork_id', 'path'])->groupBy('artwork_id');
        return $rows->map(fn ($row) => ['id' => $row->id, 'kind' => $row->kind, 'title' => $row->title,
            'description' => $row->description, 'price' => $row->price === null ? null : (int) $row->price,
            'design_status' => $row->kind === 'design' ? $row->design_status : null,
            'image_url' => app(MediaUrls::class)->url($images->get($row->id)?->first()?->path, 500)])->all();
    }

    private function fail(): never
    {
        throw new HttpResponseException(response()->json(['code' => 'INVALID_DISCOVER_QUERY', 'message' => '검색 조건을 확인하고 다시 검색해주세요.'], 422));
    }
}
