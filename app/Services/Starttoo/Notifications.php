<?php

namespace App\Services\Starttoo;

use App\Services\ProjectDatabase;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Validator;

class Notifications
{
    private function db() { return app(ProjectDatabase::class)->database(); }

    /** 호출부의 회원 잠금과 트랜잭션 안에서만 호출한다. 저장은 알림을 만들지 않는다. */
    /**
     * [$variant] 알림 세부(예약 이벤트 종류 등). 같은 대상에 여러 번 쌓여야 하면 variant를 다르게 준다
     * (중복 방지 키에 들어간다). [$push] false면 알림함에만 남긴다(예약 이벤트는 채팅 푸시가 이미 간다).
     */
    public function emit(int $recipient, int $actor, string $kind, string $type, string $target, ?int $comment = null, ?string $occurredAt = null,
        ?string $variant = null, bool $push = true, ?string $keyExtra = null): void
    {
        if ($recipient === $actor || app(Blocks::class)->between($recipient, $actor)) return;
        $parts = [$recipient, $actor, $kind, $type, $target, $comment];
        if ($variant !== null) $parts[] = $variant; // 예전 알림 키는 그대로 유지
        // 같은 대상 · 같은 종류라도 매번 따로 쌓아야 하면(예약 이벤트) 구분 값을 키에 더한다
        if ($keyExtra !== null) $parts[] = $keyExtra;
        $eventKey = hash('sha256', json_encode($parts, JSON_THROW_ON_ERROR));
        $inserted = $this->db()->table('starttoo_notifications')->insertOrIgnore([
            'user_id' => $recipient, 'actor_id' => $actor, 'kind' => $kind, 'target_type' => $type, 'target_id' => $target,
            'comment_id' => $comment, 'variant' => $variant, 'event_key' => $eventKey,
            'created_at' => $occurredAt ?? now(),
        ]);
        if ($inserted && $push && config('starttoo.push_enabled') && app(UserPreferences::class)->pushAllowed($recipient, $kind)) {
            $id = $this->db()->table('starttoo_notifications')->where('event_key', $eventKey)->value('id');
            $this->db()->table('starttoo_push_outbox')->insertOrIgnore(['notification_id' => $id, 'created_at' => now()]);
            $outboxId = (int) $this->db()->table('starttoo_push_outbox')->where('notification_id', $id)->value('id');
            // STARTTOO 원본 트랜잭션의 커밋 후 기존 공용 큐에 넣는다.
            // 큐 입력 장애가 소셜 동작을 실패시키지 않도록 발송함은 별도 복구할 수 있다.
            $this->db()->afterCommit(function () use ($outboxId) {
                try { \App\Jobs\QueueStarttooPush::dispatch($outboxId); }
                catch (\Throwable $e) { report($e); }
            });
        }
    }

    /** 안내 알림(심사 결과 등)의 종류. 보낸 회원이 없어 actor는 받는 회원 자신으로 기록한다. */
    /** fit_result: AI 피팅 · 타투 도구 결과(variant = 종류:상태). 목록에는 notices=2 를 보낸 앱에만 준다. */
    /** report_result: 내가 낸 신고의 처리 결과(variant = resolved · dismissed, 대상 = 신고 번호) */
    /** sanction: 운영 콘솔 신고 처리로 받은 제재(variant = warning · suspend · ban, 대상 = 제재 이력 번호) */
    /** ad_review: 작가 광고 심사 결과(variant = approved · rejected · changed, 대상 = 광고 번호). 목록에는 notices=6 을 보낸 앱에만 준다. */
    /** inquiry_answer: 1:1 문의에 운영자 답변(variant = answered, 대상 = 문의 번호). 목록에는 notices=7 을 보낸 앱에만 준다. */
    public const NOTICE_KINDS = ['artist_review', 'fit_result', 'report_result', 'sanction', 'ad_review', 'inquiry_answer'];

    /**
     * 예약 알림(알림함 「예약 · 상담」 탭, 예약 알림 설정을 따른다). 대상 = 예약 번호.
     * booking_event: 예약 요청 · 확정 · 변경 · 취소 · 완료(variant = 이벤트). 채팅 푸시가 이미 가서 알림함에만 남긴다.
     * booking_remind: 시술 24시간 전 리마인더(variant = 시작 시각, 시각이 바뀌면 다시 알림). 푸시를 보낸다.
     */
    public const BOOKING_KINDS = ['booking_event', 'booking_remind'];

    /** notices=4 를 보낸 앱에만 목록에 주는 종류(예약 · 신고 결과) */
    public const LEVEL4_KINDS = ['booking_event', 'booking_remind', 'report_result'];

    /** 이전 버전 앱이 모르는 알림 종류(notices=1을 보낸 앱에만 목록에 준다). */
    public const NEW_KINDS = ['artist_review', 'shop_news', 'fit_result', 'review_new', 'review_reply', 'comm_comment', 'comm_like', 'comm_reply',
        'booking_event', 'booking_remind', 'report_result', 'sanction', 'ad_review', 'inquiry_answer'];

    /**
     * Phase 5 알림(소셜 알림 설정을 따른다). 목록에는 notices=3 을 보낸 앱에만 준다.
     * review_new: 내 상점에 새 리뷰(대상 = 리뷰) · review_reply: 내 리뷰에 작가 답변(대상 = 리뷰)
     * comm_comment: 내 커뮤니티 글에 새 댓글(대상 = 글, comment_id = 댓글). kind 칸이 16자라 줄여 쓴다.
     * comm_like: 내 커뮤니티 글에 새 좋아요(대상 = 글)
     * comm_reply: 내 커뮤니티 댓글에 새 답글(대상 = 글, comment_id = 답글)
     */
    public const PHASE5_KINDS = ['review_new', 'review_reply', 'comm_comment', 'comm_like', 'comm_reply'];

    /**
     * 안내 알림. 소셜 알림 설정과 관계없이 보낸다(계정·심사 안내).
     * 호출부 트랜잭션이 커밋된 뒤 푸시 큐에 넣는다.
     */
    public function notice(int $recipient, string $kind, string $type, string $target, string $variant): void
    {
        $eventKey = hash('sha256', json_encode([$recipient, $kind, $type, $target, $variant, (string) microtime(true), random_int(0, PHP_INT_MAX)], JSON_THROW_ON_ERROR));
        $id = $this->db()->table('starttoo_notifications')->insertGetId([
            'user_id' => $recipient, 'actor_id' => $recipient, 'kind' => $kind, 'target_type' => $type, 'target_id' => $target,
            'variant' => $variant, 'event_key' => $eventKey, 'created_at' => now(),
        ]);
        if (!config('starttoo.push_enabled')) return;
        $this->db()->table('starttoo_push_outbox')->insertOrIgnore(['notification_id' => $id, 'created_at' => now()]);
        $outboxId = (int) $this->db()->table('starttoo_push_outbox')->where('notification_id', $id)->value('id');
        $this->db()->afterCommit(function () use ($outboxId) {
            try { \App\Jobs\QueueStarttooPush::dispatch($outboxId); }
            catch (\Throwable $e) { report($e); }
        });
    }

    public function withdraw(int $actor, string $kind, string $type, string $target, ?int $comment = null): void
    {
        $q = $this->db()->table('starttoo_notifications')->where('actor_id', $actor)->where('kind', $kind)->where('target_type', $type)->where('target_id', $target);
        if ($comment !== null) $q->where('comment_id', $comment);
        $q->delete();
    }

    private function visible(int $viewer)
    {
        $q = $this->db()->table('starttoo_notifications as n')->join('starttoo_users as actor', 'actor.id', '=', 'n.actor_id')
            ->where('n.user_id', $viewer)->where('actor.status', 'active')->where('actor.moderation_hidden', false);
        app(Blocks::class)->filter($q, 'n.actor_id', $viewer);
        return $q->where(function ($targets) use ($viewer) {
            // 안내 알림: 받는 회원 자신이 기록한 심사 안내
            $targets->where(fn ($notice) => $notice->whereIn('n.kind', self::NOTICE_KINDS)->whereColumn('n.actor_id', 'n.user_id'));
            // 팔로우한 아티스트의 상점 소식
            $targets->orWhere(function ($news) use ($viewer) {
                $news->where('n.kind', 'shop_news')->where('n.target_type', 'shop_news')
                    ->whereExists(fn ($s) => $s->selectRaw('1')->from('starttoo_shop_news as sn')->whereColumn('sn.id', 'n.target_id')->whereColumn('sn.user_id', 'n.actor_id'))
                    ->whereExists(fn ($f) => $f->selectRaw('1')->from('starttoo_follows as snf')->where('snf.user_id', $viewer)->whereColumn('snf.target_user_id', 'n.actor_id'));
            });
            $targets->orWhere(function ($follow) use ($viewer) {
                $follow->where('n.kind', 'follow')->where('n.target_type', 'user')->where('n.target_id', (string) $viewer)
                    ->whereExists(fn ($f) => $f->selectRaw('1')->from('starttoo_follows as f')->whereColumn('f.user_id', 'n.actor_id')->where('f.target_user_id', $viewer));
            });
            // 예약: 내가 고객이거나 작가인 예약의 이벤트 · 리마인더(상대가 보낸 것)
            $targets->orWhere(function ($booking) use ($viewer) {
                $booking->whereIn('n.kind', self::BOOKING_KINDS)->where('n.target_type', 'booking')
                    ->whereExists(fn ($b) => $b->selectRaw('1')->from('starttoo_bookings as nb')->whereRaw('nb.id = CAST(n.target_id AS UNSIGNED)')
                        ->where(fn ($who) => $who->where('nb.customer_id', $viewer)->orWhere('nb.artist_id', $viewer)));
            });
            // 리뷰: 새 리뷰(작가에게) · 작가 답변(리뷰 쓴 고객에게). 숨긴 리뷰 · 지운 답변은 안 보인다.
            $targets->orWhere(function ($review) use ($viewer) {
                $review->whereIn('n.kind', ['review_new', 'review_reply'])->where('n.target_type', 'review')
                    ->whereExists(function ($r) use ($viewer) {
                        $r->selectRaw('1')->from('starttoo_reviews as nr')->whereRaw('nr.id = CAST(n.target_id AS UNSIGNED)')
                            ->where('nr.moderation_hidden', false)
                            ->where(function ($who) use ($viewer) {
                                $who->where(fn ($new) => $new->where('n.kind', 'review_new')->where('nr.artist_id', $viewer)->whereColumn('nr.customer_id', 'n.actor_id'))
                                    ->orWhere(fn ($reply) => $reply->where('n.kind', 'review_reply')->where('nr.customer_id', $viewer)
                                        ->whereColumn('nr.artist_id', 'n.actor_id')->whereNotNull('nr.reply'));
                            });
                    });
            });
            // 커뮤니티: 내 글에 달린 댓글. 지운 · 숨긴 댓글과 글은 안 보인다.
            $targets->orWhere(function ($community) use ($viewer) {
                $community->where('n.kind', 'comm_comment')->where('n.target_type', 'community')
                    ->whereExists(function ($c) use ($viewer) {
                        $c->selectRaw('1')->from('starttoo_community_comments as ncc')
                            ->join('starttoo_community_posts as ncp', 'ncp.id', '=', 'ncc.post_id')
                            ->whereColumn('ncc.id', 'n.comment_id')->whereRaw('ncc.post_id = CAST(n.target_id AS UNSIGNED)')
                            ->whereColumn('ncc.user_id', 'n.actor_id')->where('ncc.moderation_hidden', false)
                            ->where('ncp.user_id', $viewer)->where('ncp.moderation_hidden', false);
                    });
            });
            // 커뮤니티: 내 댓글에 달린 답글. 지운 · 숨긴 답글 · 댓글 · 글은 안 보인다.
            $targets->orWhere(function ($community) use ($viewer) {
                $community->where('n.kind', 'comm_reply')->where('n.target_type', 'community')
                    ->whereExists(function ($c) use ($viewer) {
                        $c->selectRaw('1')->from('starttoo_community_comments as nrc')
                            ->join('starttoo_community_comments as nrp', 'nrp.id', '=', 'nrc.parent_id')
                            ->join('starttoo_community_posts as nrpost', 'nrpost.id', '=', 'nrc.post_id')
                            ->whereColumn('nrc.id', 'n.comment_id')->whereRaw('nrc.post_id = CAST(n.target_id AS UNSIGNED)')
                            ->whereColumn('nrc.user_id', 'n.actor_id')->where('nrc.moderation_hidden', false)
                            ->where('nrp.user_id', $viewer)->where('nrp.moderation_hidden', false)
                            ->where('nrpost.moderation_hidden', false);
                    });
            });
            // 커뮤니티: 내 글에 눌린 좋아요. 취소한 좋아요 · 숨긴 글은 안 보인다.
            $targets->orWhere(function ($community) use ($viewer) {
                $community->where('n.kind', 'comm_like')->where('n.target_type', 'community')
                    ->whereExists(function ($l) use ($viewer) {
                        $l->selectRaw('1')->from('starttoo_community_likes as ncl2')
                            ->join('starttoo_community_posts as nlp', 'nlp.id', '=', 'ncl2.post_id')
                            ->whereRaw('ncl2.post_id = CAST(n.target_id AS UNSIGNED)')->whereColumn('ncl2.user_id', 'n.actor_id')
                            ->where('nlp.user_id', $viewer)->where('nlp.moderation_hidden', false);
                    });
            });
            foreach (['artwork' => 'a', 'post' => 'p'] as $type => $alias) {
                $targets->orWhere(function ($content) use ($viewer, $type, $alias) {
                    $source = $type === 'artwork' ? app(Artworks::class) : app(Posts::class);
                    $accessible = $source->feedQuery($viewer, 'public')->selectRaw('1')->whereColumn($alias.'.id', 'n.target_id');
                    $content->where('n.target_type', $type)->whereExists($accessible)->where(function ($events) use ($viewer, $type) {
                        $events->where(function ($like) use ($type) {
                            $table = $type === 'artwork' ? 'starttoo_artwork_interactions' : 'starttoo_post_likes';
                            $like->where('n.kind', 'like')->whereExists(function ($i) use ($type, $table) {
                                $i->selectRaw('1')->from($table.' as li')->whereColumn('li.'.$type.'_id', 'n.target_id')->whereColumn('li.user_id', 'n.actor_id');
                                if ($type === 'artwork') $i->where('li.kind', 'like');
                            });
                        })->orWhere(function ($newPost) use ($viewer, $type) {
                            $contents = $type === 'post' ? 'starttoo_posts' : 'starttoo_artworks';
                            $newPost->where('n.kind', 'new_post')
                                ->whereExists(fn ($p) => $p->selectRaw('1')->from($contents.' as npt')->whereColumn('npt.id', 'n.target_id')->whereColumn('npt.user_id', 'n.actor_id'))
                                ->whereExists(fn ($f) => $f->selectRaw('1')->from('starttoo_follows as npf')->where('npf.user_id', $viewer)->whereColumn('npf.target_user_id', 'n.actor_id'));
                        })->orWhere(function ($comment) use ($viewer, $type) {
                            $table = 'starttoo_'.$type.'_comments';
                            $comment->whereIn('n.kind', ['comment', 'reply', 'comment_like'])->whereExists(function ($c) use ($viewer, $type, $table) {
                                $c->selectRaw('1')->from($table.' as nc')->whereColumn('nc.id', 'n.comment_id')->whereColumn('nc.'.$type.'_id', 'n.target_id')
                                    ->whereNull('nc.deleted_at')->where('nc.moderation_hidden', false)
                                    ->where(function ($event) use ($type) {
                                        $event->where(fn ($original) => $original->whereIn('n.kind', ['comment', 'reply'])->whereColumn('nc.user_id', 'n.actor_id'))
                                            ->orWhere(function ($like) use ($type) {
                                                $likes = $type === 'post' ? 'starttoo_post_comment_likes' : 'starttoo_comment_likes';
                                                $like->where('n.kind', 'comment_like')->whereColumn('nc.user_id', 'n.user_id')
                                                    ->whereExists(fn ($l) => $l->selectRaw('1')->from($likes.' as ncl')->whereColumn('ncl.comment_id', 'nc.id')->whereColumn('ncl.user_id', 'n.actor_id'));
                                            });
                                    })
                                    ->where(function ($root) use ($viewer, $type, $table) {
                                        $root->whereNull('nc.parent_id')->orWhereExists(function ($parent) use ($viewer, $type, $table) {
                                            $parent->selectRaw('1')->from($table.' as pc')->join('starttoo_users as pu', 'pu.id', '=', 'pc.user_id')
                                                ->whereColumn('pc.id', 'nc.parent_id')->whereColumn('pc.'.$type.'_id', 'n.target_id')->whereNull('pc.parent_id')
                                                ->where('pc.moderation_hidden', false)->where('pu.status', 'active')->where('pu.moderation_hidden', false);
                                            app(Blocks::class)->filter($parent, 'pc.user_id', $viewer);
                                        });
                                    });
                            });
                        });
                    });
                });
            }
        });
    }

    public function unread(int $viewer): int { return $this->visible($viewer)->whereNull('n.read_at')->count(); }

    public function delivery(int $viewer, int $id): ?object
    {
        return $this->visible($viewer)->where('n.id', $id)->whereNull('n.read_at')->first(['n.*']);
    }

    public function open(int $viewer, int $id): array
    {
        $n = $this->visible($viewer)->where('n.id', $id)->first(['n.*']);
        if (!$n) $this->fail(404, 'NOTIFICATION_NOT_FOUND', '더 이상 확인할 수 없는 알림이에요.');
        app(PushAudit::class)->opened('notification', (string) $id, $viewer);
        return [...$this->read($viewer, $id), 'target_type' => $n->target_type, 'target_id' => $n->target_id,
            'actor_id' => (int) $n->actor_id, 'kind' => $n->kind, 'variant' => $n->variant,
            // 승인된 작가면 앱이 프로필을 거치지 않고 바로 상점을 연다
            'actor_is_artist' => $this->db()->table('starttoo_users')->where('id', $n->actor_id)->value('artist_status') === 'approved'];
    }

    public function listing(int $viewer, array $input): array
    {
        if (Validator::make($input, ['filter' => ['nullable', 'in:all,reservation,social,notice'], 'cursor' => ['nullable', 'string', 'max:512'],
            // 5: 운영 제재 안내(sanction)까지 처리하는 앱
            'notices' => ['nullable', 'in:0,1,2,3,4,5,6,7']])->fails()) $this->fail(422, 'INVALID_NOTIFICATIONS', '알림 조회 조건을 확인해주세요.');
        $filter = $input['filter'] ?? 'all';
        // 안내 알림을 모르는 이전 앱은 목록 전체를 버리므로 notices=1을 보낸 앱에만 준다.
        // AI 결과 알림(fit_result)은 notices=2 를 보낸 앱에만 준다.
        // 리뷰 · 커뮤니티 알림(PHASE5_KINDS)은 notices=3 을 보낸 앱에만 준다.
        $level = (int) ($input['notices'] ?? '0');
        $notices = $level >= 1;
        $q = $this->visible($viewer);
        if ($level < 2) $q->where('n.kind', '<>', 'fit_result');
        if ($level < 3) $q->whereNotIn('n.kind', self::PHASE5_KINDS);
        // 예약 · 신고 결과 알림은 notices=4 를 보낸 앱에만 준다.
        if ($level < 4) $q->whereNotIn('n.kind', self::LEVEL4_KINDS);
        // 운영 제재 안내(sanction)는 notices=5 를 보낸 앱에만 준다.
        if ($level < 5) $q->where('n.kind', '<>', 'sanction');
        // 광고 심사 결과(ad_review)는 notices=6 을 보낸 앱에만 준다.
        if ($level < 6) $q->where('n.kind', '<>', 'ad_review');
        // 1:1 문의 답변(inquiry_answer)은 notices=7 을 보낸 앱에만 준다.
        if ($level < 7) $q->where('n.kind', '<>', 'inquiry_answer');
        if ($filter === 'reservation') $q->whereIn('n.kind', self::BOOKING_KINDS);
        elseif ($filter === 'notice') $notices ? $q->whereIn('n.kind', self::NOTICE_KINDS) : $q->whereRaw('1 = 0');
        elseif ($filter === 'social') $q->whereNotIn('n.kind', $notices ? [...self::NOTICE_KINDS, ...self::BOOKING_KINDS] : self::NEW_KINDS);
        elseif (!$notices) $q->whereNotIn('n.kind', self::NEW_KINDS);
        if (!empty($input['cursor'])) {
            try { $c = json_decode(base64_decode(strtr($input['cursor'], '-_', '+/'), true) ?: '', true, 8, JSON_THROW_ON_ERROR); }
            catch (\JsonException $e) { $this->fail(422, 'INVALID_NOTIFICATIONS', '알림 목록을 다시 불러와주세요.'); }
            if (!is_array($c) || ($c['user'] ?? null) !== $viewer || ($c['filter'] ?? null) !== $filter || !is_int($c['id'] ?? null) || $c['id'] < 1) $this->fail(422, 'INVALID_NOTIFICATIONS', '알림 목록을 다시 불러와주세요.');
            $q->where('n.id', '<', $c['id']);
        }
        $rows = $q->orderByDesc('n.id')->limit(21)->get(['n.*', 'actor.nickname', 'actor.display_name', 'actor.artist_status']);
        $page = $rows->take(20);
        return ['items' => $page->map(fn ($n) => [
            'id' => (int) $n->id, 'kind' => $n->kind,
            'category' => in_array($n->kind, self::NOTICE_KINDS, true) ? 'notice' : 'social',
            'variant' => $n->variant,
            'actor_name' => $n->nickname ?: $n->display_name,
            'actor_id' => (int) $n->actor_id, 'target_type' => $n->target_type, 'target_id' => $n->target_id,
            'actor_is_artist' => $n->artist_status === 'approved',
            'comment_id' => $n->comment_id === null ? null : (int) $n->comment_id,
            'read' => $n->read_at !== null, 'created_at' => Carbon::parse($n->created_at)->toIso8601String(),
        ])->all(), 'unread_count' => $this->unread($viewer),
            'next_cursor' => $rows->count() > 20 ? rtrim(strtr(base64_encode(json_encode(['user' => $viewer, 'filter' => $filter, 'id' => (int) $page->last()->id], JSON_THROW_ON_ERROR)), '+/', '-_'), '=') : null];
    }

    public function read(int $viewer, int $id): array
    {
        if (!$this->visible($viewer)->where('n.id', $id)->exists()) $this->fail(404, 'NOTIFICATION_NOT_FOUND', '더 이상 확인할 수 없는 알림이에요.');
        $this->db()->table('starttoo_notifications')->where('user_id', $viewer)->where('id', $id)->whereNull('read_at')->update(['read_at' => now()]);
        return ['id' => $id, 'unread_count' => $this->unread($viewer)];
    }

    private function fail(int $status, string $code, string $message): never
    {
        throw new HttpResponseException(response()->json(compact('code', 'message'), $status));
    }
}
