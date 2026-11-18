<?php

namespace App\Services\Starttoo;

use App\Services\ProjectDatabase;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;

class Follows
{
    public function following(array $ids, ?int $viewer): array
    {
        if (!$ids || $viewer === null) return [];
        $query = app(ProjectDatabase::class)->database()->table('starttoo_follows')->join('starttoo_users as u', 'u.id', '=', 'target_user_id')
            ->where('u.status', 'active')->where('u.moderation_hidden', false)
            ->where('user_id', $viewer)->whereIn('target_user_id', $ids);
        return app(Blocks::class)->filter($query, 'u.id', $viewer)->pluck('target_user_id')->map(fn ($id) => (int) $id)->all();
    }

    public function state(int $target, ?int $viewer): array
    {
        $db = app(ProjectDatabase::class)->database();
        $active = fn ($query) => app(Blocks::class)->filter($query->where('u.status', 'active')->where('u.moderation_hidden', false)->whereNotNull('u.profile_completed_at'), 'u.id', $viewer);
        $followers = $active($db->table('starttoo_follows as f')->join('starttoo_users as u', 'u.id', '=', 'f.user_id'))
            ->where('f.target_user_id', $target)->count();
        $following = $active($db->table('starttoo_follows as f')->join('starttoo_users as u', 'u.id', '=', 'f.target_user_id'))
            ->where('f.user_id', $target)->count();
        return ['user_id' => $target, 'viewer_id' => $viewer,
            'following' => in_array($target, $this->following([$target], $viewer), true),
            'follower_count' => $followers, 'following_count' => $following];
    }

    public function set(int $target, string $token, array $input, AuthSessions $sessions, Artworks $artworks): array
    {
        $identity = $artworks->ready($token, $sessions);
        $viewer = (int) $identity['account']['id'];
        if (Validator::make($input, ['active' => ['required', 'boolean'], 'request_key' => ['required', 'uuid']])->fails()
            || !is_bool($input['active'] ?? null)) $this->fail(422, 'INVALID_FOLLOW', '팔로우 요청을 확인해주세요.');
        if ($viewer === $target) $this->fail(422, 'CANNOT_FOLLOW_SELF', '본인은 팔로우할 수 없어요.');
        $hash = hash('sha256', json_encode([$target, $input['active']], JSON_THROW_ON_ERROR));
        $db = app(ProjectDatabase::class)->database();
        return $db->transaction(function () use ($db, $target, $viewer, $token, $input, $sessions, $artworks, $hash) {
            // 반대 방향의 동시 팔로우도 같은 순서로 회원을 잠근다.
            $db->table('starttoo_users')->whereIn('id', [$viewer, $target])->orderBy('id')->lockForUpdate()->get();
            $artworks->ready($token, $sessions);
            app(PublicProfiles::class)->user($target, $viewer);
            $retry = $db->table('starttoo_follow_requests')->where('user_id', $viewer)->where('request_key', $input['request_key'])->first();
            if ($retry) {
                if ($retry->payload_hash !== $hash) $this->fail(409, 'FOLLOW_REQUEST_CONFLICT', '팔로우 요청 내용이 변경됐어요.');
                return json_decode($retry->response_payload, true, 512, JSON_THROW_ON_ERROR);
            }
            $key = ['user_id' => $viewer, 'target_user_id' => $target];
            if ($input['active']) $db->table('starttoo_follows')->insertOrIgnore([...$key, 'created_at' => now()]);
            else $db->table('starttoo_follows')->where($key)->delete();
            $state = $this->state($target, $viewer);
            $db->table('starttoo_follow_requests')->insert(['user_id' => $viewer, 'request_key' => $input['request_key'], 'payload_hash' => $hash,
                'response_payload' => json_encode($state, JSON_THROW_ON_ERROR)]);
            $sessions->authenticate($token, true);
            return $state;
        }, 3);
    }

    public function listing(int $owner, ?int $viewer, array $input): array
    {
        $profile = app(PublicProfiles::class)->user($owner, $viewer);
        if (Validator::make($input, ['tab' => ['sometimes', Rule::in(['followers', 'following'])],
            'q' => ['nullable', 'string', 'max:50'], 'cursor' => ['nullable', 'string', 'max:512']])->fails()) {
            $this->fail(422, 'INVALID_FOLLOW_FILTER', '팔로우 목록 조회 조건을 확인해주세요.');
        }
        $cursor = null;
        if (!empty($input['cursor'])) {
            try {
                $cursor = json_decode(base64_decode(strtr($input['cursor'], '-_', '+/'), true) ?: '', true, 512, JSON_THROW_ON_ERROR);
                if (!is_array($cursor) || Validator::make($cursor, ['id' => ['required', 'integer', 'min:1'], 'd' => ['required', Rule::in(['next', 'previous'])]])->fails()) throw new \InvalidArgumentException();
            } catch (\Throwable $e) { $this->fail(422, 'INVALID_FOLLOW_FILTER', '팔로우 목록 조회 조건을 확인해주세요.'); }
        }
        $followers = ($input['tab'] ?? 'followers') === 'followers';
        $db = app(ProjectDatabase::class)->database();
        $query = $db->table('starttoo_follows as f')->join('starttoo_users as u', 'u.id', '=', $followers ? 'f.user_id' : 'f.target_user_id')
            ->where($followers ? 'f.target_user_id' : 'f.user_id', $owner)->where('u.status', 'active')->whereNotNull('u.profile_completed_at');
        $query->where('u.moderation_hidden', false);
        app(Blocks::class)->filter($query, 'u.id', $viewer);
        $search = trim($input['q'] ?? '');
        if ($search !== '') $query->where('u.nickname', 'like', '%'.addcslashes($search, '\\%_').'%');
        $backward = ($cursor['d'] ?? 'next') === 'previous';
        if ($cursor) $query->where('f.id', $backward ? '>' : '<', $cursor['id']);
        $rows = $query->select('f.id as follow_id', 'u.id', 'u.nickname', 'u.bio', 'u.avatar_path')
            ->orderBy('f.id', $backward ? 'asc' : 'desc')->limit(21)->get();
        $more = $rows->count() > 20;
        $page = $rows->take(20)->values();
        if ($backward) $page = $page->reverse()->values();
        $mine = $this->following($page->pluck('id')->all(), $viewer);
        $encode = fn ($row, $d) => $row ? rtrim(strtr(base64_encode(json_encode(['id' => (int) $row->follow_id, 'd' => $d], JSON_THROW_ON_ERROR)), '+/', '-_'), '=') : null;
        return ['owner_id' => $owner, 'nickname' => $profile->nickname, 'viewer_id' => $viewer,
            'social' => $this->state($owner, $viewer),
            'items' => $page->map(fn ($u) => ['id' => (int) $u->id, 'nickname' => $u->nickname, 'bio' => $u->bio ?? '',
                'avatar_url' => app(MediaUrls::class)->url($u->avatar_path, 300),
                'following' => in_array((int) $u->id, $mine, true), 'is_me' => $viewer === (int) $u->id])->all(),
            'next_cursor' => ($backward || $more) ? $encode($page->last(), 'next') : null,
            'previous_cursor' => ($backward ? $more : $cursor !== null) ? $encode($page->first(), 'previous') : null];
    }

    private function fail(int $status, string $code, string $message): never
    {
        throw new HttpResponseException(response()->json(compact('code', 'message'), $status));
    }
}
