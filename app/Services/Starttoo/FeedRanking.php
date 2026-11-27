<?php

namespace App\Services\Starttoo;

use App\Services\ProjectDatabase;
use Carbon\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;

/**
 * 홈 추천 피드 순서(docs/feed-ranking.md). 후보 모으기 → 점수 → 섞기.
 * - 후보: 공개 피드와 같은 조건(차단 · 숨김 · 비공개 제외)의 최신 POOL 개. 그보다 오래된 글은 추천 순서가 끝난 뒤 최신순으로 이어진다.
 * - 점수: 반응률(노출 대비 열기 · 좋아요 · 댓글 · 저장) × 관심 일치(스타일 · 태그 · 작가 · 팔로우 · 지역) × 최신도 × 이미 본 정도.
 * - 섞기: 같은 작가 연속 금지, 같은 스타일 3연속 금지, 10개 중 도안 2개 · 새 글 2개 · 안 보던 스타일 1개.
 * 첫 페이지에서 순서를 만들어 캐시에 두고(세션), 다음 페이지는 같은 순서를 이어서 준다.
 */
class FeedRanking
{
    public const POOL = 400;
    private const TTL = 21600; // 세션 순서 보관 6시간
    private const GUEST_BUCKET = 300; // 비회원은 5분마다 같은 순서를 함께 쓴다
    private const HALF_LIFE_HOURS = 36;
    private const PRIOR_VIEWS = 20; // 노출이 적은 글이 운 좋게 1등이 되지 않게 하는 보정
    private const PRIOR_RATE = 0.05;
    private const EXPLORE_HOURS = 48;
    private const EXPLORE_VIEWS = 200;
    private const NEW_AUTHOR_ITEMS = 3;
    private const OWN_MINUTES = 10; // 방금 올린 내 글은 맨 위
    private const TASTE_DAYS = 90;
    private const TASTE_HALF_LIFE_DAYS = 14;

    private function db() { return app(ProjectDatabase::class)->database(); }

    public function enabled(): bool { return app(Settings::class)->bool('feed_ranking'); }

    public function newSession(?int $viewer): string
    {
        return $viewer === null ? 'g'.intdiv(time(), self::GUEST_BUCKET) : Str::lower(Str::random(16));
    }

    /** ['keys' => ['artwork:uuid', 'post:uuid', ...], 'floor' => 추천 후보 중 가장 오래된 글 {at,id,t} | null(더 오래된 글 없음)] */
    public function session(?int $viewer, string $sid): array
    {
        $key = 'starttoo:feed-rank:'.app(ProjectDatabase::class)->code().':'.($viewer ?? 0).':'.$sid;
        return Cache::remember($key, self::TTL, fn () => $this->build($viewer));
    }

    public function build(?int $viewer): array
    {
        $candidates = $this->candidates($viewer, $more);
        if (!$candidates) return ['keys' => [], 'floor' => null];
        $last = end($candidates);
        $floor = $more ? ['at' => $last['at'], 'id' => $last['id'], 't' => $last['t']] : null;
        $ordered = $this->order($viewer, $candidates, $this->stats($candidates), $viewer === null ? [] : $this->seen($viewer, $candidates));
        return ['keys' => array_map(fn ($c) => $c['t'].':'.$c['id'], $ordered), 'floor' => $floor];
    }

    // ── 무빙 피드(홈 무빙 탭): 홈 피드와 같은 점수 · 섞기, 신호에 영상 시청을 더한다 ──

    /** ['keys' => [영상 id …], 'floor' => 후보 중 가장 오래된 영상 {at, id} | null] */
    public function videoSession(?int $viewer, string $sid): array
    {
        $key = 'starttoo:moving-rank:'.app(ProjectDatabase::class)->code().':'.($viewer ?? 0).':'.$sid;
        return Cache::remember($key, self::TTL, fn () => $this->buildVideos($viewer));
    }

    /**
     * $base: Videos::shorts 와 같은 공개 조건(차단 · 숨김 · 비공개 제외)의 영상 쿼리.
     * 영상마다 게시된 작품 · 게시물의 스타일 · 태그 · 작가로 점수를 매긴다(좋아요 · 저장 · 댓글도 그 글 기준).
     */
    public function buildVideos(?int $viewer, ?\Closure $base = null): array
    {
        $base ??= fn () => app(Videos::class)->feedBase($viewer);
        $rows = $base()->leftJoin('starttoo_artworks as a', fn ($j) => $j->on('a.id', '=', 'v.published_id')->where('v.published_type', 'artwork'))
            ->leftJoin('starttoo_posts as p', fn ($j) => $j->on('p.id', '=', 'v.published_id')->where('v.published_type', 'post'))
            ->orderByDesc('v.published_at')->orderByDesc('v.id')->limit(self::POOL + 1)
            ->get(['v.id as vid', 'v.published_type', 'v.published_id', 'v.published_at', 'v.user_id', 'u.interest_region as region',
                'a.styles', 'a.tags as a_tags', 'a.kind', 'p.tags as p_tags']);
        $more = $rows->count() > self::POOL;
        $rows = $rows->take(self::POOL);
        if ($rows->isEmpty()) return ['keys' => [], 'floor' => null];
        $tags = fn ($v) => array_values(array_unique(array_map(fn ($t) => mb_strtolower(ltrim($t, '#')), $this->list($v))));
        $candidates = [];
        foreach ($rows as $r) {
            $art = $r->published_type === 'artwork';
            $candidates[] = ['t' => (string) $r->published_type, 'id' => (string) $r->published_id, 'vid' => (string) $r->vid,
                'user' => (int) $r->user_id, 'at' => Carbon::parse($r->published_at)->format('Y-m-d H:i:s'),
                'styles' => $art ? $this->list($r->styles) : [], 'tags' => $tags($art ? $r->a_tags : $r->p_tags),
                'design' => $art && $r->kind === 'design', 'region' => $r->region];
        }
        // 반응: 그 글의 좋아요 · 저장 · 댓글 · 피드 노출 + 영상 재생(노출) · 3초 시청 · 끝까지 시청(열기) · 상담 · 피팅 버튼(저장급)
        $stats = $this->stats($candidates);
        $vids = array_column($candidates, 'vid');
        $byVid = array_column($candidates, null, 'vid');
        foreach ($this->db()->table('starttoo_video_views')->whereIn('video_id', $vids)->groupBy('video_id')
            ->selectRaw('video_id, SUM(plays) as plays, SUM(watched_3s) as w3, SUM(completed) as done, SUM(cta_clicks) as cta')->get() as $v) {
            $c = $byVid[$v->video_id] ?? null;
            if (!$c) continue;
            $k = $c['t'].':'.$c['id'];
            $stats[$k]['views'] += (int) $v->plays;
            $stats[$k]['opens'] += (int) $v->done + intdiv((int) $v->w3, 2);
            $stats[$k]['saves'] += (int) $v->cta;
        }
        // 이 회원이 이미 본 영상(재생 수)
        $seen = [];
        if ($viewer !== null) {
            foreach ($this->db()->table('starttoo_video_views')->where('user_id', $viewer)->whereIn('video_id', $vids)
                ->groupBy('video_id')->selectRaw('video_id, SUM(plays) as n')->pluck('n', 'video_id') as $vid => $n) {
                $c = $byVid[$vid] ?? null;
                if ($c) $seen[$c['t'].':'.$c['id']] = (int) $n;
            }
        }
        $ordered = $this->order($viewer, $candidates, $stats, $seen);
        $last = end($candidates);
        return ['keys' => array_column($ordered, 'vid'), 'floor' => $more ? ['at' => $last['at'], 'id' => $last['vid']] : null];
    }

    /** 점수 매기기 → 섞기(홈 피드 · 무빙 피드 공통). $stats · $seen 키는 'type:id' */
    private function order(?int $viewer, array $candidates, array $stats, array $seen): array
    {
        $taste = $this->taste($viewer);
        $authors = $this->authorCounts(array_values(array_unique(array_column($candidates, 'user'))));
        $now = now();
        $own = [];
        $scored = [];
        foreach ($candidates as $c) {
            $key = $c['t'].':'.$c['id'];
            $hours = max(0, ($now->getTimestamp() - Carbon::parse($c['at'])->getTimestamp()) / 3600);
            if ($viewer !== null && $c['user'] === $viewer && $hours * 60 < self::OWN_MINUTES) { $own[] = $c; continue; }
            $s = $stats[$key] ?? ['views' => 0, 'opens' => 0, 'likes' => 0, 'saves' => 0, 'comments' => 0];
            $eng = ($s['opens'] + 2 * $s['likes'] + 4 * $s['comments'] + 6 * $s['saves'] + self::PRIOR_VIEWS * self::PRIOR_RATE)
                / ($s['views'] + self::PRIOR_VIEWS) / self::PRIOR_RATE;
            $eng = max(0.3, min(5.0, $eng));
            $style = 0.0;
            foreach ($c['styles'] as $st) $style = max($style, $taste['styles'][$st] ?? 0);
            $tag = 0.0;
            foreach ($c['tags'] as $tg) $tag = max($tag, $taste['tags'][$tg] ?? 0);
            $aff = 1 + 0.8 * $style + 0.3 * $tag + 0.8 * ($taste['authors'][$c['user']] ?? 0)
                + (isset($taste['follows'][$c['user']]) ? 0.6 : 0)
                + ($taste['region'] !== null && $c['region'] === $taste['region'] ? 0.2 : 0);
            $fresh = 0.5 ** ($hours / self::HALF_LIFE_HOURS);
            if (($authors[$c['user']] ?? 0) <= self::NEW_AUTHOR_ITEMS) $fresh *= 2;
            $views = $seen[$key] ?? 0;
            $engaged = isset($taste['engaged'][$key]);
            $seenFactor = $engaged ? ($views > 0 ? 0.4 : 0.6) : ($views >= 3 ? 0.15 : 0.7 ** $views);
            if ($viewer !== null && $c['user'] === $viewer) $seenFactor *= 0.3; // 내 글은 덜 보여준다
            $c['score'] = $eng * $aff * $fresh * $seenFactor;
            $c['explore'] = $hours < self::EXPLORE_HOURS && $s['views'] < self::EXPLORE_VIEWS;
            $c['off'] = $c['styles'] && $style === 0.0;
            $scored[] = $c;
        }
        // 점수가 같으면 최신 글 먼저(후보가 이미 최신순이므로 안정 정렬)
        usort($scored, fn ($a, $b) => $b['score'] <=> $a['score']);
        return [...$own, ...$this->diversify($scored, (bool) $taste['styles'])];
    }

    /** 공개 피드와 같은 조건의 최신 POOL 개(작품 · 도안 · 게시물). $more: 더 오래된 글이 남아 있을 수 있음 */
    private function candidates(?int $viewer, ?bool &$more): array
    {
        $art = app(Artworks::class)->feedQuery($viewer, 'public')
            ->select('a.id', 'a.user_id', 'a.created_at', 'a.styles', 'a.tags', 'a.kind', 'u.interest_region as region')
            ->orderByDesc('a.created_at')->orderByDesc('a.id')->limit(self::POOL)->get();
        $posts = app(Posts::class)->feedQuery($viewer, 'public')
            ->select('p.id', 'p.user_id', 'p.created_at', 'p.tags', 'u.interest_region as region')
            ->orderByDesc('p.created_at')->orderByDesc('p.id')->limit(self::POOL)->get();
        $list = function ($v) { $v = is_string($v) ? json_decode($v, true) : $v; return is_array($v) ? array_values(array_filter($v, 'is_string')) : []; };
        $tags = fn ($v) => array_values(array_unique(array_map(fn ($t) => mb_strtolower(ltrim($t, '#')), $list($v))));
        $rows = [];
        foreach ($art as $r) $rows[] = ['t' => 'artwork', 'id' => (string) $r->id, 'user' => (int) $r->user_id,
            'at' => Carbon::parse($r->created_at)->format('Y-m-d H:i:s'), 'styles' => $list($r->styles), 'tags' => $tags($r->tags),
            'design' => $r->kind === 'design', 'region' => $r->region];
        foreach ($posts as $r) $rows[] = ['t' => 'post', 'id' => (string) $r->id, 'user' => (int) $r->user_id,
            'at' => Carbon::parse($r->created_at)->format('Y-m-d H:i:s'), 'styles' => [], 'tags' => $tags($r->tags),
            'design' => false, 'region' => $r->region];
        // 통합 피드 최신순과 같은 순서: created_at, id, 원본 유형 내림차순
        usort($rows, fn ($a, $b) => strcmp($b['at'], $a['at']) ?: (strcmp($b['id'], $a['id']) ?: strcmp($b['t'], $a['t'])));
        $more = count($rows) > self::POOL || $art->count() >= self::POOL || $posts->count() >= self::POOL;
        return array_slice($rows, 0, self::POOL);
    }

    /** 게시물별 노출 · 열기(앱 기록)와 좋아요 · 저장 · 댓글 수 */
    private function stats(array $candidates): array
    {
        $db = $this->db();
        $out = [];
        $blank = ['views' => 0, 'opens' => 0, 'likes' => 0, 'saves' => 0, 'comments' => 0];
        $ids = ['artwork' => [], 'post' => []];
        foreach ($candidates as $c) { $ids[$c['t']][] = $c['id']; $out[$c['t'].':'.$c['id']] = $blank; }
        $add = function (string $type, $rows, string $field) use (&$out) {
            foreach ($rows as $id => $n) if (isset($out[$type.':'.$id])) $out[$type.':'.$id][$field] += (int) $n;
        };
        foreach (['artwork', 'post'] as $type) {
            if (!$ids[$type]) continue;
            foreach ($db->table('starttoo_feed_stats')->where('target_type', $type)->whereIn('target_id', $ids[$type])->get() as $r) {
                $k = $type.':'.$r->target_id;
                if (isset($out[$k])) { $out[$k]['views'] += (int) $r->views; $out[$k]['opens'] += (int) $r->opens; }
            }
        }
        if ($a = $ids['artwork']) {
            foreach ($db->table('starttoo_artwork_interactions')->whereIn('artwork_id', $a)->groupBy('artwork_id', 'kind')
                ->selectRaw('artwork_id, kind, COUNT(*) as n')->get() as $r) {
                $k = 'artwork:'.$r->artwork_id;
                if (isset($out[$k]) && in_array($r->kind, ['like', 'save'], true)) $out[$k][$r->kind.'s'] += (int) $r->n;
            }
            $add('artwork', $db->table('starttoo_artwork_comments')->whereIn('artwork_id', $a)->whereNull('deleted_at')
                ->groupBy('artwork_id')->selectRaw('artwork_id, COUNT(*) as n')->pluck('n', 'artwork_id'), 'comments');
        }
        if ($p = $ids['post']) {
            $add('post', $db->table('starttoo_post_likes')->whereIn('post_id', $p)->groupBy('post_id')->selectRaw('post_id, COUNT(*) as n')->pluck('n', 'post_id'), 'likes');
            $add('post', $db->table('starttoo_post_saves')->whereIn('post_id', $p)->groupBy('post_id')->selectRaw('post_id, COUNT(*) as n')->pluck('n', 'post_id'), 'saves');
            $add('post', $db->table('starttoo_post_comments')->whereIn('post_id', $p)->whereNull('deleted_at')
                ->groupBy('post_id')->selectRaw('post_id, COUNT(*) as n')->pluck('n', 'post_id'), 'comments');
        }
        return $out;
    }

    /** 작가별 전체 글 수(첫 글 3개까지는 신규 작가로 더 보여준다) */
    private function authorCounts(array $users): array
    {
        if (!$users) return [];
        $db = $this->db();
        $out = [];
        foreach (['starttoo_artworks', 'starttoo_posts'] as $table) {
            foreach ($db->table($table)->whereIn('user_id', $users)->groupBy('user_id')->selectRaw('user_id, COUNT(*) as n')->pluck('n', 'user_id') as $u => $n) {
                $out[(int) $u] = ($out[(int) $u] ?? 0) + (int) $n;
            }
        }
        return $out;
    }

    /** 이 회원이 후보 중 이미 본 카드: 'type:id' => 본 횟수 */
    private function seen(int $viewer, array $candidates): array
    {
        $out = [];
        $byType = ['artwork' => [], 'post' => []];
        foreach ($candidates as $c) $byType[$c['t']][] = $c['id'];
        foreach ($byType as $type => $ids) {
            if (!$ids) continue;
            foreach ($this->db()->table('starttoo_feed_seen')->where('user_id', $viewer)->where('target_type', $type)
                ->whereIn('target_id', $ids)->get(['target_id', 'views']) as $r) $out[$type.':'.$r->target_id] = (int) $r->views;
        }
        return $out;
    }

    /**
     * 회원 취향: 최근 90일 저장 · 댓글 · 좋아요 · 열기 · 오래 본 카드의 스타일 · 태그 · 작가(14일마다 절반으로 줄어든다).
     * 가입 때 고른 관심 스타일은 기본값 0.5. 값은 0~1.
     */
    private function taste(?int $viewer): array
    {
        $taste = ['styles' => [], 'tags' => [], 'authors' => [], 'follows' => [], 'engaged' => [], 'region' => null];
        if ($viewer === null) return $taste;
        $db = $this->db();
        $since = now()->subDays(self::TASTE_DAYS);
        $signals = []; // [type, id, weight, at]
        foreach ($db->table('starttoo_artwork_interactions')->where('user_id', $viewer)->where('created_at', '>=', $since)
            ->orderByDesc('created_at')->limit(300)->get(['artwork_id', 'kind', 'created_at']) as $r) {
            $signals[] = ['artwork', (string) $r->artwork_id, $r->kind === 'save' ? 3 : 2, $r->created_at];
        }
        foreach ($db->table('starttoo_artwork_comments')->where('user_id', $viewer)->where('created_at', '>=', $since)
            ->orderByDesc('created_at')->limit(100)->get(['artwork_id', 'created_at']) as $r) $signals[] = ['artwork', (string) $r->artwork_id, 3, $r->created_at];
        foreach ([['starttoo_post_likes', 2], ['starttoo_post_saves', 3], ['starttoo_post_comments', 3]] as [$table, $w]) {
            foreach ($db->table($table)->where('user_id', $viewer)->where('created_at', '>=', $since)
                ->orderByDesc('created_at')->limit(200)->get(['post_id', 'created_at']) as $r) $signals[] = ['post', (string) $r->post_id, $w, $r->created_at];
        }
        foreach ($signals as $s) $taste['engaged'][$s[0].':'.$s[1]] = true;
        foreach ($db->table('starttoo_feed_events')->where('user_id', $viewer)->where('created_at', '>=', now()->subDays(30))
            ->where(fn ($q) => $q->where('event', 'open')->orWhere('ms', '>=', 3000))
            ->orderByDesc('id')->limit(500)->get(['target_type', 'target_id', 'event', 'created_at']) as $r) {
            $signals[] = [(string) $r->target_type, (string) $r->target_id, $r->event === 'open' ? 1 : 0.5, $r->created_at];
        }
        // 무빙 시청(최근 30일): 상담 · 피팅 버튼 3 · 끝까지 1 · 3초 이상 0.5 → 그 영상이 게시된 글의 스타일 · 태그 · 작가
        try {
            foreach ($db->table('starttoo_video_views as w')->join('starttoo_videos as v', 'v.id', '=', 'w.video_id')
                ->where('w.user_id', $viewer)->where('w.day', '>=', now()->subDays(30)->toDateString())->whereNotNull('v.published_id')
                ->where(fn ($q) => $q->where('w.watched_3s', '>', 0)->orWhere('w.completed', '>', 0)->orWhere('w.cta_clicks', '>', 0))
                ->orderByDesc('w.day')->limit(300)->get(['v.published_type', 'v.published_id', 'w.completed', 'w.cta_clicks', 'w.day']) as $r) {
                $signals[] = [(string) $r->published_type, (string) $r->published_id,
                    $r->cta_clicks > 0 ? 3 : ($r->completed > 0 ? 1 : 0.5), $r->day];
            }
        } catch (\Illuminate\Database\QueryException $e) {
            // 무빙 테이블 전(000062) 배포
        }
        $meta = [];
        $ids = ['artwork' => [], 'post' => []];
        foreach ($signals as $s) $ids[$s[0]][$s[1]] = true;
        if ($ids['artwork']) foreach ($db->table('starttoo_artworks')->whereIn('id', array_keys($ids['artwork']))->get(['id', 'user_id', 'styles', 'tags']) as $r) {
            $meta['artwork:'.$r->id] = [(int) $r->user_id, $this->list($r->styles), $this->list($r->tags)];
        }
        if ($ids['post']) foreach ($db->table('starttoo_posts')->whereIn('id', array_keys($ids['post']))->get(['id', 'user_id', 'tags']) as $r) {
            $meta['post:'.$r->id] = [(int) $r->user_id, [], $this->list($r->tags)];
        }
        $now = now()->getTimestamp();
        foreach ($signals as [$type, $id, $w, $at]) {
            $m = $meta[$type.':'.$id] ?? null;
            if (!$m || $m[0] === $viewer) continue;
            $w *= 0.5 ** (max(0, $now - Carbon::parse($at)->getTimestamp()) / 86400 / self::TASTE_HALF_LIFE_DAYS);
            $taste['authors'][$m[0]] = ($taste['authors'][$m[0]] ?? 0) + $w;
            foreach ($m[1] as $st) $taste['styles'][$st] = ($taste['styles'][$st] ?? 0) + $w;
            foreach ($m[2] as $tg) { $tg = mb_strtolower(ltrim($tg, '#')); $taste['tags'][$tg] = ($taste['tags'][$tg] ?? 0) + $w; }
        }
        foreach (['styles', 'tags', 'authors'] as $k) {
            $max = $taste[$k] ? max($taste[$k]) : 0;
            if ($max > 0) foreach ($taste[$k] as $key => $v) $taste[$k][$key] = $v / $max;
        }
        $user = $db->table('starttoo_users')->where('id', $viewer)->first(['interest_styles', 'interest_region']);
        foreach ($this->list($user?->interest_styles) as $st) $taste['styles'][$st] = max($taste['styles'][$st] ?? 0, 0.5);
        $region = $user?->interest_region;
        $taste['region'] = $region && $region !== 'all' ? (string) $region : null;
        foreach ($db->table('starttoo_follows')->where('user_id', $viewer)->pluck('target_user_id') as $u) $taste['follows'][(int) $u] = true;
        return $taste;
    }

    /**
     * 점수순 목록을 다시 섞는다. 조건을 만족하는 가장 높은 점수의 글을 고르고, 없으면 조건을 하나씩 푼다.
     * - 같은 작가: 최근 4개 안에 있으면 건너뜀(연속 5개 안에 1번)
     * - 같은 대표 스타일: 3연속 금지
     * - 10개 묶음마다 도안 최소 2개, 4·7번째는 새 글(48시간 · 노출 200회 미만), 10번째는 안 보던 스타일
     */
    private function diversify(array $scored, bool $hasTaste): array
    {
        $picked = [];
        $left = array_values($scored);
        while ($left) {
            $pos = count($picked);
            $inBlock = $pos % 10;
            $block = array_slice($picked, $pos - $inBlock);
            $designs = count(array_filter($block, fn ($c) => $c['design']));
            $need = max(0, 2 - $designs);
            $want = match (true) {
                $need > 0 && $need >= 10 - $inBlock => fn ($c) => $c['design'],
                $inBlock === 3 || $inBlock === 6 => fn ($c) => $c['explore'],
                $hasTaste && $inBlock === 9 => fn ($c) => $c['off'],
                default => null,
            };
            $recentAuthors = array_column(array_slice($picked, -4), 'user');
            $lastTwo = array_slice($picked, -2);
            $style = count($lastTwo) === 2 && ($lastTwo[0]['styles'][0] ?? null) !== null
                && ($lastTwo[0]['styles'][0] ?? null) === ($lastTwo[1]['styles'][0] ?? null) ? $lastTwo[0]['styles'][0] : null;
            $ok = fn ($c) => !in_array($c['user'], $recentAuthors, true) && ($style === null || ($c['styles'][0] ?? null) !== $style);
            $index = null;
            if ($want) foreach ($left as $i => $c) if ($ok($c) && $want($c)) { $index = $i; break; }
            if ($index === null) foreach ($left as $i => $c) if ($ok($c)) { $index = $i; break; }
            $index ??= 0;
            $picked[] = $left[$index];
            array_splice($left, $index, 1);
        }
        return $picked;
    }

    private function list(mixed $v): array
    {
        $v = is_string($v) ? json_decode($v, true) : $v;
        return is_array($v) ? array_values(array_filter($v, 'is_string')) : [];
    }
}
