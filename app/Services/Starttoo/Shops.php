<?php

namespace App\Services\Starttoo;

use App\Services\ProjectDatabase;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;

/**
 * 2-07~2-11 고객용 아티스트 상점.
 * 승인된 정상 아티스트의 상점만 보이며, 차단·운영자 숨김·비공개 작품은 제외한다.
 * 상세 주소는 받지 않는다(지역까지만 공개).
 */
class Shops
{
    private function db() { return app(ProjectDatabase::class)->database(); }

    /** 공개 상점 확인. 없거나 볼 수 없으면 404. */
    public function visibleShop(int $owner, ?int $viewer): object
    {
        $shop = $this->db()->table('starttoo_shops as s')->join('starttoo_users as u', 'u.id', '=', 's.user_id')
            ->where('s.user_id', $owner)->where('u.status', 'active')->where('u.moderation_hidden', false)
            ->where('u.artist_status', 'approved')
            ->first(['s.*', 'u.nickname', 'u.avatar_path']);
        if (!$shop || ($viewer !== null && app(Blocks::class)->between($viewer, $owner))) {
            $this->fail(404, 'SHOP_NOT_FOUND', '상점을 찾을 수 없어요.');
        }
        return $shop;
    }

    /** 2-07 상점 홈 + 2-08 정보. */
    public function show(int $owner, ?int $viewer): array
    {
        $shop = $this->visibleShop($owner, $viewer);
        $base = $this->portfolioQuery($owner, $viewer);
        return [
            ...app(Studios::class)->view($shop),
            'nickname' => $shop->nickname,
            'avatar_url' => app(MediaUrls::class)->url($shop->avatar_path, 300),
            'business_hours' => $shop->business_hours, 'guide' => $shop->guide,
            'counts' => ['works' => (clone $base)->where('a.kind', 'work')->count(),
                'designs' => (clone $base)->where('a.kind', 'design')->count()],
            'social' => app(Follows::class)->state($owner, $viewer),
            'own' => $viewer === $owner,
            'pinned_news' => $this->newsRows($owner)->where('pinned', true)->orderByDesc('id')->limit(1)->get()
                ->map(fn ($n) => $this->newsView($n))->first(),
        ];
    }

    /** 2-09 포트폴리오: 공개·상점 노출 작품/도안. kind=all|work|design. */
    public function portfolio(int $owner, ?int $viewer, array $input): array
    {
        $this->visibleShop($owner, $viewer);
        if (Validator::make($input, ['kind' => ['nullable', Rule::in(['all', 'work', 'design'])], 'cursor' => ['nullable', 'string', 'max:200']])->fails()) {
            $this->fail(422, 'INVALID_SHOP_QUERY', '목록 조건을 확인해주세요.');
        }
        $q = $this->portfolioQuery($owner, $viewer);
        if (($input['kind'] ?? 'all') !== 'all') $q->where('a.kind', $input['kind']);
        if (!empty($input['cursor'])) {
            [$at, $id] = array_pad(explode('|', base64_decode($input['cursor'], true) ?: ''), 2, null);
            if (!$at || !$id) $this->fail(422, 'INVALID_SHOP_QUERY', '목록을 다시 불러와주세요.');
            $q->where(fn ($w) => $w->where('a.created_at', '<', $at)->orWhere(fn ($s) => $s->where('a.created_at', $at)->where('a.id', '<', $id)));
        }
        $rows = $q->orderByDesc('a.created_at')->orderByDesc('a.id')->limit(31)->get(['a.id', 'a.kind', 'a.title', 'a.description', 'a.price', 'a.design_status', 'a.body_part', 'a.size_text', 'a.created_at']);
        $page = $rows->take(30);
        $last = $page->last();
        return ['items' => $this->cards($page), 'next_cursor' => $rows->count() > 30 ? base64_encode($last->created_at.'|'.$last->id) : null];
    }

    /** 2-11 소식: 공지·휴무(작성)와 새 도안(자동)을 시간순으로 합친다. */
    public function news(int $owner, ?int $viewer, array $input): array
    {
        $this->visibleShop($owner, $viewer);
        $before = isset($input['before']) && strtotime((string) $input['before']) ? Carbon::parse($input['before']) : null;
        $manual = $this->newsRows($owner)->when($before, fn ($q) => $q->where('created_at', '<', $before))
            ->orderByDesc('created_at')->limit(20)->get()->map(fn ($n) => $this->newsView($n));
        // 같은 날 올린 도안을 한 소식으로 묶는다.
        $designs = $this->portfolioQuery($owner, $viewer)->where('a.kind', 'design')
            ->when($before, fn ($q) => $q->where('a.created_at', '<', $before))
            ->orderByDesc('a.created_at')->limit(40)->get(['a.id', 'a.kind', 'a.title', 'a.description', 'a.price', 'a.design_status', 'a.body_part', 'a.size_text', 'a.created_at']);
        $grouped = $designs->groupBy(fn ($d) => Carbon::parse($d->created_at)->format('Y-m-d'))->map(fn ($group) => [
            'id' => 'design-'.Carbon::parse($group->first()->created_at)->format('Ymd'), 'kind' => 'new_design', 'title' => null,
            'body' => '새 도안 '.$group->count().'개를 올렸어요.', 'pinned' => false,
            'created_at' => Carbon::parse($group->first()->created_at)->toIso8601String(),
            'designs' => $this->cards($group->take(3)),
        ])->values();
        $items = $manual->concat($grouped)->sortByDesc('created_at')->values()->take(20);
        $pinned = $items->where('pinned', true)->values();
        $items = $pinned->concat($items->where('pinned', false))->values();
        return ['items' => $items->all(), 'next_before' => $items->count() >= 20 ? $items->last()['created_at'] : null,
            'social' => app(Follows::class)->state($owner, $viewer)];
    }

    // ── 스튜디오(작성자) ──────────────────────────
    public function myNews(int $owner): array
    {
        return ['items' => $this->newsRows($owner)->orderByDesc('pinned')->orderByDesc('id')->limit(50)->get()
            ->map(fn ($n) => $this->newsView($n))->all()];
    }

    public function createNews(int $owner, array $input): array
    {
        foreach (['title', 'body'] as $f) if (is_string($input[$f] ?? null)) $input[$f] = trim($input[$f]);
        $v = Validator::make($input, [
            'kind' => ['required', Rule::in(['notice', 'holiday'])],
            'title' => ['nullable', 'string', 'max:40'], 'body' => ['required', 'string', 'min:2', 'max:500'],
            'pinned' => ['sometimes', 'boolean'],
        ]);
        if ($v->fails()) $this->fail(422, 'INVALID_SHOP_NEWS', '소식 내용을 확인해주세요.');
        $input = $v->validated();
        return $this->db()->transaction(function () use ($owner, $input) {
            if ($this->db()->table('starttoo_shop_news')->where('user_id', $owner)->where('created_at', '>', now()->subDay())->count() >= 10) {
                $this->fail(429, 'SHOP_NEWS_LIMIT', '소식은 하루 10개까지 올릴 수 있어요.');
            }
            if (!empty($input['pinned'])) $this->db()->table('starttoo_shop_news')->where('user_id', $owner)->update(['pinned' => false]);
            $id = $this->db()->table('starttoo_shop_news')->insertGetId([
                'user_id' => $owner, 'kind' => $input['kind'], 'title' => ($input['title'] ?? '') ?: null,
                'body' => $input['body'], 'pinned' => !empty($input['pinned']), 'created_at' => now(),
            ]);
            // 팔로워에게 소식 알림(분할 발송)
            app(FollowerNotifications::class)->created($owner, 'shop_news', (string) $id);
            return $this->newsView($this->db()->table('starttoo_shop_news')->where('id', $id)->first());
        });
    }

    public function deleteNews(int $owner, int $id): void
    {
        $this->db()->table('starttoo_shop_news')->where('user_id', $owner)->where('id', $id)->delete();
    }

    private function newsRows(int $owner) { return $this->db()->table('starttoo_shop_news')->where('user_id', $owner); }

    private function newsView(object $n): array
    {
        return ['id' => (string) $n->id, 'kind' => $n->kind, 'title' => $n->title, 'body' => $n->body,
            'pinned' => (bool) $n->pinned, 'created_at' => Carbon::parse($n->created_at)->toIso8601String(), 'designs' => []];
    }

    private function portfolioQuery(int $owner, ?int $viewer)
    {
        $q = $this->db()->table('starttoo_artworks as a')->where('a.user_id', $owner)
            ->where('a.visibility', 'public')->where('a.moderation_hidden', false)->where('a.show_in_shop', true)
            ->whereExists(fn ($i) => $i->selectRaw('1')->from('starttoo_artwork_images as i')->whereColumn('i.artwork_id', 'a.id'));
        if ($viewer !== null && $viewer !== $owner) {
            $q->whereNotExists(fn ($h) => $h->selectRaw('1')->from('starttoo_hidden_artworks as h')->whereColumn('h.artwork_id', 'a.id')->where('h.user_id', $viewer));
        }
        return $q;
    }

    private function cards($rows): array
    {
        $ids = collect($rows)->pluck('id')->all();
        $images = $ids ? $this->db()->table('starttoo_artwork_images')->whereIn('artwork_id', $ids)->orderBy('position')
            ->get(['artwork_id', 'path'])->groupBy('artwork_id') : collect();
        $likes = $ids ? $this->db()->table('starttoo_artwork_interactions')->whereIn('artwork_id', $ids)->where('kind', 'like')
            ->selectRaw('artwork_id, COUNT(*) as total')->groupBy('artwork_id')->pluck('total', 'artwork_id') : collect();
        $parts = collect(app(Artworks::class)->options()['body_parts'])->pluck('label', 'id');
        return collect($rows)->map(function ($r) use ($images, $likes, $parts) {
            $first = optional($images->get($r->id))->first();
            return ['id' => $r->id, 'kind' => $r->kind, 'title' => $r->title, 'description' => $r->description,
                'price' => $r->price === null ? null : (int) $r->price,
                'design_status' => $r->kind === 'design' ? $r->design_status : null,
                'body_part' => isset($r->body_part) ? ($parts[$r->body_part] ?? null) : null,
                'size_text' => $r->size_text ?? null,
                'like_count' => (int) ($likes[$r->id] ?? 0),
                'image_url' => $first ? app(MediaUrls::class)->url($first->path, 500) : null];
        })->values()->all();
    }

    private function fail(int $status, string $code, string $message): never
    {
        throw new HttpResponseException(response()->json(compact('code', 'message'), $status));
    }
}
