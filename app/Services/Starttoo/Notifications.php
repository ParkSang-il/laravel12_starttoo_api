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
    public function emit(int $recipient, int $actor, string $kind, string $type, string $target, ?int $comment = null, ?string $occurredAt = null): void
    {
        if ($recipient === $actor || app(Blocks::class)->between($recipient, $actor)) return;
        $eventKey = hash('sha256', json_encode([$recipient, $actor, $kind, $type, $target, $comment], JSON_THROW_ON_ERROR));
        $inserted = $this->db()->table('starttoo_notifications')->insertOrIgnore([
            'user_id' => $recipient, 'actor_id' => $actor, 'kind' => $kind, 'target_type' => $type, 'target_id' => $target,
            'comment_id' => $comment, 'event_key' => $eventKey,
            'created_at' => $occurredAt ?? now(),
        ]);
        if ($inserted && config('starttoo.push_enabled') && app(UserPreferences::class)->get($recipient)['values']['social']) {
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
            $targets->where(function ($follow) use ($viewer) {
                $follow->where('n.kind', 'follow')->where('n.target_type', 'user')->where('n.target_id', (string) $viewer)
                    ->whereExists(fn ($f) => $f->selectRaw('1')->from('starttoo_follows as f')->whereColumn('f.user_id', 'n.actor_id')->where('f.target_user_id', $viewer));
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
            'actor_id' => (int) $n->actor_id, 'kind' => $n->kind];
    }

    public function listing(int $viewer, array $input): array
    {
        if (Validator::make($input, ['filter' => ['nullable', 'in:all,reservation,social,notice'], 'cursor' => ['nullable', 'string', 'max:512']])->fails()) $this->fail(422, 'INVALID_NOTIFICATIONS', '알림 조회 조건을 확인해주세요.');
        $filter = $input['filter'] ?? 'all';
        $q = $this->visible($viewer);
        if (in_array($filter, ['reservation', 'notice'], true)) $q->whereRaw('1 = 0');
        if (!empty($input['cursor'])) {
            try { $c = json_decode(base64_decode(strtr($input['cursor'], '-_', '+/'), true) ?: '', true, 8, JSON_THROW_ON_ERROR); }
            catch (\JsonException $e) { $this->fail(422, 'INVALID_NOTIFICATIONS', '알림 목록을 다시 불러와주세요.'); }
            if (!is_array($c) || ($c['user'] ?? null) !== $viewer || ($c['filter'] ?? null) !== $filter || !is_int($c['id'] ?? null) || $c['id'] < 1) $this->fail(422, 'INVALID_NOTIFICATIONS', '알림 목록을 다시 불러와주세요.');
            $q->where('n.id', '<', $c['id']);
        }
        $rows = $q->orderByDesc('n.id')->limit(21)->get(['n.*', 'actor.nickname', 'actor.display_name']);
        $page = $rows->take(20);
        return ['items' => $page->map(fn ($n) => [
            'id' => (int) $n->id, 'kind' => $n->kind, 'category' => 'social',
            'actor_name' => $n->nickname ?: $n->display_name,
            'actor_id' => (int) $n->actor_id, 'target_type' => $n->target_type, 'target_id' => $n->target_id,
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
