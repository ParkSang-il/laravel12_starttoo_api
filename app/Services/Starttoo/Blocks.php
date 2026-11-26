<?php

namespace App\Services\Starttoo;

use App\Services\ProjectDatabase;
use Illuminate\Database\Query\Builder;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Support\Facades\Validator;

class Blocks
{
    /** 로그인한 회원과 양방향 차단 관계인 작성자/회원을 제외한다. */
    public function filter(Builder $query, string $userColumn, ?int $viewer): Builder
    {
        if ($viewer === null) return $query;
        return $query->whereNotExists(function ($q) use ($viewer, $userColumn) {
            $q->selectRaw('1')->from('starttoo_user_blocks as sb')->where(function ($q) use ($viewer, $userColumn) {
                $q->where(fn ($q) => $q->where('sb.user_id', $viewer)->whereColumn('sb.blocked_user_id', $userColumn))
                    ->orWhere(fn ($q) => $q->where('sb.blocked_user_id', $viewer)->whereColumn('sb.user_id', $userColumn));
            });
        });
    }

    public function between(?int $viewer, int $target): bool
    {
        if ($viewer === null || $viewer === $target) return false;
        return app(ProjectDatabase::class)->database()->table('starttoo_user_blocks')->where(function ($q) use ($viewer, $target) {
            $q->where(fn ($q) => $q->where('user_id', $viewer)->where('blocked_user_id', $target))
                ->orWhere(fn ($q) => $q->where('user_id', $target)->where('blocked_user_id', $viewer));
        })->exists();
    }

    /** 목록용: $ids 중 로그인한 회원과 차단 관계인 회원 번호(한 번 조회) */
    public function blockedAmong(?int $viewer, array $ids): array
    {
        $ids = array_values(array_unique(array_filter(array_map('intval', $ids), fn ($id) => $id > 0 && $id !== $viewer)));
        if ($viewer === null || !$ids) return [];
        $rows = app(ProjectDatabase::class)->database()->table('starttoo_user_blocks')->where(function ($q) use ($viewer, $ids) {
            $q->where(fn ($q) => $q->where('user_id', $viewer)->whereIn('blocked_user_id', $ids))
                ->orWhere(fn ($q) => $q->where('blocked_user_id', $viewer)->whereIn('user_id', $ids));
        })->get(['user_id', 'blocked_user_id']);
        return $rows->map(fn ($r) => (int) $r->user_id === $viewer ? (int) $r->blocked_user_id : (int) $r->user_id)->unique()->values()->all();
    }

    public function set(int $target, string $token, array $input, AuthSessions $sessions, Artworks $artworks): array
    {
        $viewer = (int) $artworks->ready($token, $sessions)['account']['id'];
        if ($viewer === $target) $this->fail(422, 'CANNOT_BLOCK_SELF', '본인은 차단할 수 없어요.');
        if (Validator::make($input, ['active' => ['required', 'boolean'], 'request_key' => ['required', 'uuid']])->fails()
            || !is_bool($input['active'] ?? null)) $this->fail(422, 'INVALID_SAFETY_REQUEST', '차단 요청을 확인해주세요.');
        $db = app(ProjectDatabase::class)->database();
        $hash = hash('sha256', json_encode(['block', $target, $input['active']], JSON_THROW_ON_ERROR));
        return $db->transaction(function () use ($db, $viewer, $target, $token, $input, $sessions, $artworks, $hash) {
            $users = $db->table('starttoo_users')->whereIn('id', [$viewer, $target])->orderBy('id')->lockForUpdate()->get();
            $artworks->ready($token, $sessions);
            if (!$users->contains(fn ($u) => (int) $u->id === $target)) $this->fail(404, 'PROFILE_NOT_FOUND', '프로필을 찾을 수 없어요.');
            if ($result = $this->retry($viewer, $input['request_key'], $hash)) return $result;
            $key = ['user_id' => $viewer, 'blocked_user_id' => $target];
            $before = $db->table('starttoo_user_blocks')->where($key)->exists();
            if ($input['active']) {
                $db->table('starttoo_user_blocks')->insertOrIgnore([...$key, 'created_at' => now()]);
                $db->table('starttoo_follows')->where(function ($q) use ($viewer, $target) {
                    $q->where(fn ($q) => $q->where('user_id', $viewer)->where('target_user_id', $target))
                        ->orWhere(fn ($q) => $q->where('user_id', $target)->where('target_user_id', $viewer));
                })->delete();
            } else $db->table('starttoo_user_blocks')->where($key)->delete();
            if ($before !== $input['active']) $db->table('starttoo_safety_audits')->insert([
                'actor_type' => 'member', 'actor_user_id' => $viewer, 'target_type' => 'user', 'target_id' => (string) $target,
                'action' => $input['active'] ? 'block' : 'unblock', 'changes' => json_encode(['blocked' => [$before, $input['active']]], JSON_THROW_ON_ERROR),
            ]);
            $result = ['user_id' => $target, 'viewer_id' => $viewer, 'blocked' => $input['active']];
            $this->remember($viewer, $input['request_key'], $hash, $result);
            $sessions->authenticate($token, true);
            return $result;
        }, 3);
    }

    public function listing(string $token, array $input, AuthSessions $sessions, Artworks $artworks): array
    {
        $account = $artworks->ready($token, $sessions)['account'];
        if (Validator::make($input, ['page' => ['sometimes', 'integer', 'min:1', 'max:100000']])->fails()) $this->fail(422, 'INVALID_SAFETY_REQUEST', '목록 조회 조건을 확인해주세요.');
        $page = (int) ($input['page'] ?? 1);
        $db = app(ProjectDatabase::class)->database();
        $total = $db->table('starttoo_user_blocks')->where('user_id', $account['id'])->count();
        $rows = $db->table('starttoo_user_blocks as b')->join('starttoo_users as u', 'u.id', '=', 'b.blocked_user_id')
            ->where('b.user_id', $account['id'])->select('u.id', 'u.nickname', 'u.avatar_path', 'u.status', 'u.moderation_hidden', 'b.created_at')
            ->orderByDesc('b.id')->offset(($page - 1) * 20)->limit(21)->get();
        $reported = $db->table('starttoo_reports')->where('reporter_id', $account['id'])
            ->whereIn('subject_user_id', $rows->pluck('id'))->distinct()->pluck('subject_user_id')->map(fn ($id) => (int) $id)->all();
        return ['account' => $account, 'total' => $total, 'page' => $page, 'has_more' => $rows->count() > 20,
            'items' => $rows->take(20)->map(fn ($u) => ['id' => (int) $u->id,
                'blocked_at' => $u->created_at,
                'reported' => in_array((int) $u->id, $reported, true),
                'nickname' => $u->status === 'active' && !$u->moderation_hidden ? ($u->nickname ?? '회원') : '이용할 수 없는 회원',
                'avatar_url' => $u->status === 'active' && !$u->moderation_hidden ? app(MediaUrls::class)->url($u->avatar_path, 300) : null])->values()->all()];
    }

    public function retry(int $viewer, string $key, string $hash): ?array
    {
        $row = app(ProjectDatabase::class)->database()->table('starttoo_safety_requests')->where('user_id', $viewer)->where('request_key', $key)->first();
        if (!$row) return null;
        if ($row->payload_hash !== $hash) $this->fail(409, 'SAFETY_REQUEST_CONFLICT', '요청 내용이 변경됐어요. 다시 확인해주세요.');
        return json_decode($row->response_payload, true, 512, JSON_THROW_ON_ERROR);
    }

    public function remember(int $viewer, string $key, string $hash, array $result): void
    {
        app(ProjectDatabase::class)->database()->table('starttoo_safety_requests')->insert(['user_id' => $viewer, 'request_key' => $key,
            'payload_hash' => $hash, 'response_payload' => json_encode($result, JSON_THROW_ON_ERROR)]);
    }

    private function fail(int $status, string $code, string $message): never
    {
        throw new HttpResponseException(response()->json(compact('code', 'message'), $status));
    }
}
