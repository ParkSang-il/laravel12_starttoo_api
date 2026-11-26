<?php

namespace App\Services\Starttoo;

use App\Services\ProjectDatabase;
use Carbon\Carbon;
use Illuminate\Database\QueryException;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;

/**
 * Phase 5 인증 리뷰(5-01 목록 · 5-02 작성 · 5-03 상세).
 * - 시술 완료(completed) 예약의 고객만 쓸 수 있고 예약당 한 건(DB unique).
 * - 본인이 수정 · 삭제할 수 있고, 삭제하면 같은 예약으로 다시 쓸 수 있다.
 * - 작가는 리뷰마다 답변 1개(수정 · 삭제 가능). 다른 회원은 「도움이 됐어요」 1표.
 * - 운영자가 숨긴 리뷰 · 차단한 회원의 리뷰는 목록과 평점 집계에서 모두 뺀다.
 */
class Reviews
{
    /** 5-02 태그(디자인 순서) */
    public const TAGS = ['선이 깔끔해요', '상담이 친절해요', '시간을 잘 지켜요', '위생이 철저해요', '도안을 잘 다듬어줘요', '통증 배려'];
    public const MIN_LENGTH = 20;
    public const MAX_PHOTOS = 5;
    /** 목록에 남기는 작성자 상태: 정상 · 탈퇴(「탈퇴한 회원」으로 표시) */
    public const LISTED_STATUSES = ['active', 'withdrawn'];

    private function db() { return app(ProjectDatabase::class)->database(); }

    // ── 작성 · 수정 · 삭제(고객) ──────────────────

    /** 5-02 화면 열기: 쓸 수 있는 예약인지 + 대상 정보 + 태그 목록(수정이면 기존 내용) */
    public function form(int $viewer, int $bookingId): array
    {
        $b = $this->booking($viewer, $bookingId);
        $existing = $this->db()->table('starttoo_reviews')->where('booking_id', $bookingId)->first();
        return [
            'booking' => $this->target($b),
            'tags' => self::TAGS, 'min_length' => self::MIN_LENGTH, 'max_photos' => self::MAX_PHOTOS,
            'review' => $existing ? $this->format($existing, $viewer) : null,
        ];
    }

    public function create(int $viewer, array $input): array
    {
        $in = $this->validate($input, true);
        $b = $this->booking($viewer, (int) $in['booking_id']);
        $db = $this->db();
        if ($done = $db->table('starttoo_reviews')->where('customer_id', $viewer)->where('request_key', $in['request_key'])->first()) {
            return $this->format($done, $viewer);
        }
        if ($db->table('starttoo_reviews')->where('booking_id', $b->id)->exists()) {
            $this->fail(409, 'REVIEW_EXISTS', '이 예약에는 이미 리뷰를 썼어요.');
        }
        try {
            $id = $db->transaction(function () use ($db, $viewer, $b, $in) {
                $paths = array_values(app(ArtworkUploads::class)->claim($viewer, array_values($in['media_ids'] ?? [])));
                return $db->table('starttoo_reviews')->insertGetId([
                    'booking_id' => $b->id, 'customer_id' => $viewer, 'artist_id' => $b->artist_id, 'request_key' => $in['request_key'],
                    'rating' => (int) $in['rating'], 'tags' => json_encode(array_values($in['tags'] ?? []), JSON_UNESCAPED_UNICODE),
                    'content' => $in['content'], 'images' => json_encode($paths), 'work' => $this->work($b),
                    'created_at' => now(), 'updated_at' => now(),
                ]);
            }, 3);
        } catch (QueryException $e) {
            // 동시에 두 번 눌렀을 때: 먼저 저장된 리뷰를 돌려준다
            if ($done = $db->table('starttoo_reviews')->where('booking_id', $b->id)->where('customer_id', $viewer)->first()) return $this->format($done, $viewer);
            throw $e;
        }
        // 작가에게 「새 리뷰」 알림(실패해도 리뷰 등록은 그대로)
        $this->notify((int) $b->artist_id, $viewer, 'review_new', (int) $id);
        return $this->format($db->table('starttoo_reviews')->where('id', $id)->first(), $viewer);
    }

    private function notify(int $recipient, int $actor, string $kind, int $reviewId): void
    {
        try {
            app(Notifications::class)->emit($recipient, $actor, $kind, 'review', (string) $reviewId);
        } catch (\Throwable $e) {
            report($e);
        }
    }

    /** 수정: 평점 · 태그 · 내용 · 사진(keep = 남길 기존 사진 순번, media_ids = 새 사진) */
    public function update(int $viewer, int $id, array $input): array
    {
        $in = $this->validate($input, false);
        $r = $this->own($viewer, $id);
        $old = json_decode((string) $r->images, true) ?: [];
        $keep = [];
        foreach (array_unique(array_map('intval', $in['keep'] ?? [])) as $i) {
            if (isset($old[$i])) $keep[] = $old[$i];
        }
        if (count($keep) + count($in['media_ids'] ?? []) > self::MAX_PHOTOS) {
            $this->fail(422, 'INVALID_REVIEW', '사진은 '.self::MAX_PHOTOS.'장까지 넣을 수 있어요.');
        }
        $removed = array_values(array_diff($old, $keep));
        $this->db()->transaction(function () use ($viewer, $id, $in, $keep) {
            $new = array_values(app(ArtworkUploads::class)->claim($viewer, array_values($in['media_ids'] ?? [])));
            $this->db()->table('starttoo_reviews')->where('id', $id)->update([
                'rating' => (int) $in['rating'], 'tags' => json_encode(array_values($in['tags'] ?? []), JSON_UNESCAPED_UNICODE),
                'content' => $in['content'], 'images' => json_encode([...$keep, ...$new]), 'updated_at' => now(),
            ]);
        }, 3);
        $this->forget($removed);
        return $this->format($this->db()->table('starttoo_reviews')->where('id', $id)->first(), $viewer);
    }

    /** 삭제(본인). 같은 예약으로 다시 쓸 수 있다. */
    public function delete(int $viewer, int $id): array
    {
        $r = $this->own($viewer, $id);
        $this->db()->table('starttoo_reviews')->where('id', $id)->delete();
        $this->forget(json_decode((string) $r->images, true) ?: []);
        return ['deleted' => true, 'booking_id' => (int) $r->booking_id];
    }

    // ── 작가 답변 · 도움이 됐어요 ──────────────────

    /** 작가 답변 등록 · 수정. 내용을 비우면 답변 삭제. */
    public function reply(int $viewer, int $id, array $input): array
    {
        $r = $this->visible($id, $viewer);
        if ((int) $r->artist_id !== $viewer) $this->fail(403, 'REVIEW_FORBIDDEN', '이 리뷰의 작가만 답변할 수 있어요.');
        $content = trim((string) ($input['content'] ?? ''));
        if (mb_strlen($content) > 1000) $this->fail(422, 'INVALID_REVIEW', '답변은 1000자까지 쓸 수 있어요.');
        $this->db()->table('starttoo_reviews')->where('id', $id)->update([
            'reply' => $content === '' ? null : $content, 'replied_at' => $content === '' ? null : now(),
        ]);
        // 답변을 처음 달 때만 고객에게 알림. 답변을 지우면 알림도 거둔다(다시 달면 다시 알림).
        if ($content === '') {
            try { app(Notifications::class)->withdraw($viewer, 'review_reply', 'review', (string) $id); } catch (\Throwable $e) { report($e); }
        } elseif ($r->reply === null) {
            $this->notify((int) $r->customer_id, $viewer, 'review_reply', $id);
        }
        return $this->format($this->db()->table('starttoo_reviews')->where('id', $id)->first(), $viewer);
    }

    /** 「도움이 됐어요」 켜기/끄기. 회원당 한 표, 본인 리뷰 · 본인 상점 리뷰에는 못 누른다. */
    public function helpful(int $viewer, int $id, array $input): array
    {
        $r = $this->visible($id, $viewer);
        if ((int) $r->customer_id === $viewer || (int) $r->artist_id === $viewer) {
            $this->fail(422, 'REVIEW_OWN', '내 리뷰 · 내 상점 리뷰에는 누를 수 없어요.');
        }
        $active = filter_var($input['active'] ?? true, FILTER_VALIDATE_BOOLEAN);
        $this->db()->transaction(function () use ($viewer, $id, $active) {
            $this->db()->table('starttoo_reviews')->where('id', $id)->lockForUpdate()->first();
            if ($active) {
                $this->db()->table('starttoo_review_helpfuls')->insertOrIgnore(['review_id' => $id, 'user_id' => $viewer, 'created_at' => now()]);
            } else {
                $this->db()->table('starttoo_review_helpfuls')->where('review_id', $id)->where('user_id', $viewer)->delete();
            }
            $this->db()->table('starttoo_reviews')->where('id', $id)->update([
                'helpful_count' => $this->db()->table('starttoo_review_helpfuls')->where('review_id', $id)->count(),
            ]);
        }, 3);
        return $this->format($this->db()->table('starttoo_reviews')->where('id', $id)->first(), $viewer);
    }

    // ── 조회 ─────────────────────────────────────

    /** 상점 평점 요약(상점 홈 · 5-01). 숨김 · 차단 리뷰 제외. */
    public function summary(int $artist, ?int $viewer): array
    {
        $rows = $this->shopQuery($artist, $viewer)->selectRaw('r.rating, count(*) as n')->groupBy('r.rating')->pluck('n', 'rating');
        $total = (int) $rows->sum();
        $sum = 0;
        $dist = [];
        foreach ([5, 4, 3, 2, 1] as $n) {
            $count = (int) ($rows[$n] ?? 0);
            $sum += $n * $count;
            $dist[(string) $n] = $total ? (int) round($count / $total * 100) : 0;
        }
        return ['average' => $total ? round($sum / $total, 1) : 0, 'total' => $total, 'distribution' => $dist];
    }

    /** 5-01 상점 리뷰 목록. sort: latest 최신순 · photo 사진 리뷰 · low 평점 낮은순 */
    public function shop(int $artist, ?int $viewer, array $input): array
    {
        app(Shops::class)->visibleShop($artist, $viewer);
        $sort = in_array($input['sort'] ?? 'latest', ['latest', 'photo', 'low'], true) ? ($input['sort'] ?? 'latest') : 'latest';
        $page = max(1, (int) ($input['page'] ?? 1));
        $q = $this->shopQuery($artist, $viewer);
        if ($sort === 'photo') $q->whereRaw("JSON_LENGTH(COALESCE(r.images, '[]')) > 0");
        $sort === 'low' ? $q->orderBy('r.rating')->orderByDesc('r.id') : $q->orderByDesc('r.id');
        $rows = $q->select('r.*')->offset(($page - 1) * 20)->limit(21)->get();
        return [
            'summary' => $this->summary($artist, $viewer),
            'items' => $this->formatMany($rows->take(20), $viewer),
            'has_more' => $rows->count() > 20,
        ];
    }

    /** 내가 쓴 리뷰(MY 「내 리뷰」) */
    public function mine(int $viewer, array $input): array
    {
        $page = max(1, (int) ($input['page'] ?? 1));
        $rows = $this->db()->table('starttoo_reviews')->where('customer_id', $viewer)->orderByDesc('id')
            ->offset(($page - 1) * 20)->limit(21)->get();
        return ['items' => $this->formatMany($rows->take(20), $viewer), 'has_more' => $rows->count() > 20];
    }

    /** 목록용 format: 회원 · 상점 이름 · 「도움이 됐어요」 여부를 목록 전체에서 한 번씩만 조회한다. */
    public function formatMany(iterable $rows, ?int $viewer): array
    {
        $rows = collect($rows)->values();
        if ($rows->isEmpty()) return [];
        $db = $this->db();
        $userIds = $rows->pluck('customer_id')->merge($rows->pluck('artist_id'))->map(fn ($v) => (int) $v)->unique()->values()->all();
        $ctx = [
            'users' => $db->table('starttoo_users')->whereIn('id', $userIds)->get(['id', 'nickname', 'avatar_path', 'status'])->keyBy('id')->all(),
            'shops' => $db->table('starttoo_shops')->whereIn('user_id', $rows->pluck('artist_id')->unique()->all())->pluck('studio_name', 'user_id')->all(),
            'helped' => $viewer === null ? [] : array_flip($db->table('starttoo_review_helpfuls')->where('user_id', $viewer)
                ->whereIn('review_id', $rows->pluck('id')->all())->pluck('review_id')->map(fn ($v) => (int) $v)->all()),
        ];
        return $rows->map(fn ($r) => $this->format($r, $viewer, false, $ctx))->all();
    }

    /** 5-03 상세 */
    public function detail(int $id, ?int $viewer): array
    {
        return $this->format($this->visible($id, $viewer), $viewer, true);
    }

    /** 예약 화면용: 예약 번호 → 리뷰 번호(없으면 null) */
    public function idForBooking(int $bookingId): ?int
    {
        $id = $this->db()->table('starttoo_reviews')->where('booking_id', $bookingId)->value('id');
        return $id === null ? null : (int) $id;
    }

    // ── 내부 ─────────────────────────────────────

    private function shopQuery(int $artist, ?int $viewer)
    {
        $q = $this->db()->table('starttoo_reviews as r')->join('starttoo_users as u', 'u.id', '=', 'r.customer_id')
            ->where('r.artist_id', $artist)->where('r.moderation_hidden', false)
            // 탈퇴한 회원의 리뷰는 남기고 작성자를 「탈퇴한 회원」으로 보여 준다(이용 정지 회원은 뺀다).
            ->whereIn('u.status', self::LISTED_STATUSES)->where('u.moderation_hidden', false);
        app(Blocks::class)->filter($q, 'r.customer_id', $viewer);
        return $q;
    }

    /** 시술 완료한 내 예약인지 확인 */
    private function booking(int $viewer, int $bookingId): object
    {
        $b = $this->db()->table('starttoo_bookings')->where('id', $bookingId)->first();
        if (!$b || (int) $b->customer_id !== $viewer) $this->fail(404, 'BOOKING_NOT_FOUND', '예약을 찾을 수 없어요.');
        if ($b->status !== 'completed') $this->fail(409, 'REVIEW_NOT_ALLOWED', '시술을 마친 예약만 리뷰를 쓸 수 있어요.');
        return $b;
    }

    /** 볼 수 있는 리뷰(숨김 · 차단 · 탈퇴 제외). 본인 · 해당 작가는 숨김이어도 본다. */
    private function visible(int $id, ?int $viewer): object
    {
        $r = $this->db()->table('starttoo_reviews')->where('id', $id)->first();
        if (!$r) $this->fail(404, 'REVIEW_NOT_FOUND', '리뷰를 찾을 수 없어요.');
        $party = $viewer !== null && ((int) $r->customer_id === $viewer || (int) $r->artist_id === $viewer);
        if (!$party) {
            $author = $this->db()->table('starttoo_users')->where('id', $r->customer_id)->first(['status', 'moderation_hidden']);
            if ($r->moderation_hidden || !$author || !in_array($author->status, self::LISTED_STATUSES, true) || $author->moderation_hidden
                || app(Blocks::class)->between($viewer, (int) $r->customer_id) || app(Blocks::class)->between($viewer, (int) $r->artist_id)) {
                $this->fail(404, 'REVIEW_NOT_FOUND', '리뷰를 찾을 수 없어요.');
            }
        }
        return $r;
    }

    private function own(int $viewer, int $id): object
    {
        $r = $this->db()->table('starttoo_reviews')->where('id', $id)->first();
        if (!$r || (int) $r->customer_id !== $viewer) $this->fail(404, 'REVIEW_NOT_FOUND', '리뷰를 찾을 수 없어요.');
        return $r;
    }

    private function validate(array $input, bool $creating): array
    {
        if (is_string($input['content'] ?? null)) $input['content'] = trim($input['content']);
        $v = Validator::make($input, [
            'booking_id' => [$creating ? 'required' : 'nullable', 'integer', 'min:1'],
            'request_key' => [$creating ? 'required' : 'nullable', 'uuid'],
            'rating' => ['required', 'integer', 'between:1,5'],
            'tags' => ['sometimes', 'array', 'max:'.count(self::TAGS)],
            'tags.*' => ['distinct', Rule::in(self::TAGS)],
            'content' => ['required', 'string', 'min:'.self::MIN_LENGTH, 'max:2000'],
            'media_ids' => ['sometimes', 'array', 'max:'.self::MAX_PHOTOS],
            'media_ids.*' => ['uuid', 'distinct'],
            'keep' => ['sometimes', 'array', 'max:'.self::MAX_PHOTOS],
            'keep.*' => ['integer', 'min:0'],
        ]);
        if ($v->fails()) {
            $this->fail(422, 'INVALID_REVIEW', mb_strlen((string) ($input['content'] ?? '')) < self::MIN_LENGTH
                ? '리뷰 내용을 '.self::MIN_LENGTH.'자 이상 적어주세요.' : '리뷰 내용을 확인해주세요.');
        }
        return $v->validated();
    }

    /** 작업 요약: 「블랙워크 뱀 · 팔뚝」 */
    private function work(object $b): string
    {
        $request = $this->db()->table('starttoo_consultation_requests')->where('case_id', $b->case_id)->orderBy('id')->first();
        $parts = collect(app(Artworks::class)->options()['body_parts'])->pluck('label', 'id');
        $part = $request && $request->body_part ? ($parts[$request->body_part] ?? null) : null;
        return mb_substr(implode(' · ', array_filter([(string) $b->subject, $part])), 0, 120);
    }

    /** 5-02 대상 정보 */
    private function target(object $b): array
    {
        $artist = $this->db()->table('starttoo_users')->where('id', $b->artist_id)->first(['nickname', 'avatar_path']);
        $shop = $this->db()->table('starttoo_shops')->where('user_id', $b->artist_id)->value('studio_name');
        return [
            'id' => (int) $b->id, 'artist_id' => (int) $b->artist_id,
            'artist_nickname' => $artist->nickname ?? '', 'studio_name' => $shop,
            'avatar_url' => app(MediaUrls::class)->url($artist->avatar_path ?? null, 300),
            'starts_at' => Carbon::parse($b->starts_at)->toIso8601String(), 'work' => $this->work($b),
        ];
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

    /** $ctx: formatMany 가 미리 모은 회원(users) · 상점 이름(shops) · 도움이 됐어요(helped). 없으면 한 건씩 조회. */
    public function format(object $r, ?int $viewer, bool $detail = false, ?array $ctx = null): array
    {
        $db = $this->db();
        $author = $ctx ? ($ctx['users'][(int) $r->customer_id] ?? null)
            : $db->table('starttoo_users')->where('id', $r->customer_id)->first(['nickname', 'avatar_path', 'status']);
        $withdrawn = ($author->status ?? null) !== 'active';
        $artist = $ctx ? ($ctx['users'][(int) $r->artist_id] ?? null)
            : $db->table('starttoo_users')->where('id', $r->artist_id)->first(['nickname', 'avatar_path']);
        $urls = app(MediaUrls::class);
        $images = json_decode((string) $r->images, true) ?: [];
        $out = [
            'id' => (int) $r->id, 'booking_id' => (int) $r->booking_id,
            'rating' => (int) $r->rating, 'tags' => json_decode((string) $r->tags, true) ?: [], 'content' => $r->content,
            'images' => array_map(fn ($p) => $urls->url($p, 1200), $images),
            'thumbs' => array_map(fn ($p) => $urls->url($p, 300), $images),
            'work' => $r->work,
            'author' => ['id' => (int) $r->customer_id, 'nickname' => $withdrawn ? '탈퇴한 회원' : ($author->nickname ?? ''),
                'avatar_url' => $withdrawn ? null : $urls->url($author->avatar_path ?? null, 300)],
            'artist' => ['id' => (int) $r->artist_id, 'nickname' => $artist->nickname ?? '',
                'studio_name' => $ctx ? ($ctx['shops'][(int) $r->artist_id] ?? null)
                    : $db->table('starttoo_shops')->where('user_id', $r->artist_id)->value('studio_name')],
            'reply' => $r->reply, 'replied_at' => $r->replied_at ? Carbon::parse($r->replied_at)->toIso8601String() : null,
            'helpful_count' => (int) $r->helpful_count,
            'helped' => $viewer !== null && ($ctx ? isset($ctx['helped'][(int) $r->id])
                : $db->table('starttoo_review_helpfuls')->where('review_id', $r->id)->where('user_id', $viewer)->exists()),
            'mine' => $viewer !== null && (int) $r->customer_id === $viewer,
            'can_reply' => $viewer !== null && (int) $r->artist_id === $viewer,
            'hidden' => (bool) $r->moderation_hidden,
            'edited' => Carbon::parse($r->updated_at)->gt(Carbon::parse($r->created_at)->addSeconds(5)),
            'created_at' => Carbon::parse($r->created_at)->toIso8601String(),
        ];
        if ($detail) {
            // 5-03 「리뷰 3개」: 작성자가 쓴 공개 리뷰 수
            $out['author']['review_count'] = $db->table('starttoo_reviews')->where('customer_id', $r->customer_id)->where('moderation_hidden', false)->count();
            $booking = $db->table('starttoo_bookings')->where('id', $r->booking_id)->first(['duration']);
            $out['duration'] = $booking->duration ?? null;
        }
        return $out;
    }

    private function fail(int $status, string $code, string $message): never
    {
        throw new HttpResponseException(response()->json(compact('code', 'message'), $status));
    }
}
