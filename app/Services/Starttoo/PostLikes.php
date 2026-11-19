<?php

namespace App\Services\Starttoo;

use App\Services\ProjectDatabase;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Support\Facades\Validator;

class PostLikes
{
    public function states(array $ids, ?int $viewer): array
    {
        if (!$ids) return [];
        $db = app(ProjectDatabase::class)->database();
        $counts = $db->table('starttoo_post_likes')->whereIn('post_id', $ids)
            ->groupBy('post_id')->selectRaw('post_id, COUNT(*) as total')->pluck('total', 'post_id');
        $mine = $viewer === null ? [] : $db->table('starttoo_post_likes')->whereIn('post_id', $ids)
            ->where('user_id', $viewer)->pluck('post_id')->all();
        $saved = $viewer === null ? [] : $db->table('starttoo_post_saves')->whereIn('post_id', $ids)
            ->where('user_id', $viewer)->pluck('post_id')->all();
        $likers = app(ContentLikers::class)->recent('post', $ids, $viewer);
        $result = [];
        foreach ($ids as $id) $result[$id] = ['liked' => in_array($id, $mine, true), 'saved' => in_array($id, $saved, true), 'like_count' => (int) ($counts[$id] ?? 0),
            'recent_likers' => $likers[$id] ?? []];
        return $result;
    }

    public function set(string $id, string $token, array $input, AuthSessions $sessions): array
    {
        $posts = app(Posts::class);
        $viewer = (int) $posts->ready($token, $sessions)['account']['id'];
        if (Validator::make($input, [
            'request_key' => ['required', 'uuid'], 'kind' => ['required', 'in:like,save'], 'active' => ['required', 'boolean'],
        ])->fails() || !is_bool($input['active'] ?? null)) $this->fail(422, 'INVALID_INTERACTION', '좋아요 요청을 확인해주세요.');
        // 기존 좋아요 재시도 해시는 유지하고 저장은 별도 네임스페이스로 구분한다.
        $hash = hash('sha256', json_encode($input['kind'] === 'like' ? [$id, $input['active']] : ['save', $id, $input['active']], JSON_THROW_ON_ERROR));
        $db = app(ProjectDatabase::class)->database();
        $author = $db->table('starttoo_posts')->where('id', $id)->value('user_id');
        return $db->transaction(function () use ($db, $posts, $id, $token, $input, $sessions, $viewer, $author, $hash) {
            $db->table('starttoo_users')->whereIn('id', array_unique(array_filter([$viewer, $author])))->orderBy('id')->lockForUpdate()->get();
            $posts->ready($token, $sessions);
            $db->table('starttoo_posts')->where('id', $id)->lockForUpdate()->first();
            $detail = $posts->detail($id, $viewer);
            if ($detail['moderation_hidden']) $this->fail(404, 'POST_NOT_FOUND', '게시물을 찾을 수 없어요.');
            $retry = $db->table('starttoo_post_like_requests')->where('user_id', $viewer)->where('request_key', $input['request_key'])->first();
            if ($retry) {
                if ($retry->payload_hash !== $hash) $this->fail(409, 'POST_REQUEST_CONFLICT', '좋아요 요청 내용이 변경됐어요.');
                return json_decode($retry->response_payload, true, 512, JSON_THROW_ON_ERROR);
            }
            $key = ['user_id' => $viewer, 'post_id' => $id];
            $table = $input['kind'] === 'like' ? 'starttoo_post_likes' : 'starttoo_post_saves';
            if ($input['active']) $inserted = $db->table($table)->insertOrIgnore([...$key, 'created_at' => now()]);
            else $db->table($table)->where($key)->delete();
            if ($input['kind'] === 'like') {
                if ($input['active'] && $inserted) app(Notifications::class)->emit((int) $author, $viewer, 'like', 'post', $id);
                if (!$input['active']) app(Notifications::class)->withdraw($viewer, 'like', 'post', $id);
            }
            $result = $this->states([$id], $viewer)[$id];
            $db->table('starttoo_post_like_requests')->insert([
                'user_id' => $viewer, 'request_key' => $input['request_key'], 'payload_hash' => $hash,
                'response_payload' => json_encode($result, JSON_THROW_ON_ERROR),
            ]);
            $sessions->authenticate($token, true);
            return $result;
        }, 3);
    }

    private function fail(int $status, string $code, string $message): never
    {
        throw new HttpResponseException(response()->json(compact('code', 'message'), $status));
    }
}
