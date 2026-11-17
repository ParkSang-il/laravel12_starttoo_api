<?php

namespace App\Services\Starttoo;

use App\Services\ProjectDatabase;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;

class ArtworkInteractions
{
    public function states(array $ids, ?int $viewer): array
    {
        if (!$ids) return [];
        $db = app(ProjectDatabase::class)->database();
        $counts = $db->table('starttoo_artwork_interactions')->whereIn('artwork_id', $ids)->where('kind', 'like')
            ->selectRaw('artwork_id, COUNT(*) as total')->groupBy('artwork_id')->pluck('total', 'artwork_id');
        $mine = $viewer === null ? collect() : $db->table('starttoo_artwork_interactions')->where('user_id', $viewer)
            ->whereIn('artwork_id', $ids)->get()->groupBy('artwork_id');
        $result = [];
        foreach ($ids as $id) {
            $kinds = $mine->get($id, collect())->pluck('kind')->all();
            $result[$id] = ['liked' => in_array('like', $kinds, true), 'saved' => in_array('save', $kinds, true), 'like_count' => (int) ($counts[$id] ?? 0)];
        }
        return $result;
    }

    public function set(string $id, string $token, array $input, AuthSessions $sessions, Artworks $artworks): array
    {
        $identity = $artworks->ready($token, $sessions);
        if (Validator::make($input, ['request_key' => ['required', 'uuid'], 'kind' => ['required', Rule::in(['like', 'save'])], 'active' => ['required', 'boolean']])->fails()
            || !is_bool($input['active'] ?? null)) $this->fail(422, 'INVALID_INTERACTION', '요청 내용을 확인해주세요.');
        $db = app(ProjectDatabase::class)->database();
        $userId = $identity['account']['id'];
        $hash = hash('sha256', json_encode([$id, $input['kind'], $input['active']], JSON_THROW_ON_ERROR));
        return $db->transaction(function () use ($db, $id, $token, $input, $sessions, $userId, $hash) {
            // 같은 회원의 재시도 키와 작품별 변경을 직렬화한다.
            $db->table('starttoo_users')->where('id', $userId)->lockForUpdate()->first();
            $row = $db->table('starttoo_artworks')->where('id', $id)->lockForUpdate()->first();
            $sessions->authenticate($token);
            $request = $db->table('starttoo_interaction_requests')->where('user_id', $userId)->where('request_key', $input['request_key'])->first();
            if ($request) {
                if ($request->payload_hash !== $hash) $this->fail(409, 'ARTWORK_REQUEST_CONFLICT', '요청이 변경됐어요.');
                return json_decode($request->response_payload, true, 512, JSON_THROW_ON_ERROR);
            }
            if (!$row || $row->visibility !== 'public' || !$db->table('starttoo_users')->where('id', $row->user_id)->where('status', 'active')->exists()) {
                $this->fail(404, 'ARTWORK_NOT_FOUND', '작품을 찾을 수 없어요.');
            }
            $key = ['user_id' => $userId, 'artwork_id' => $id, 'kind' => $input['kind']];
            if ($input['active']) $db->table('starttoo_artwork_interactions')->insertOrIgnore([...$key, 'created_at' => now(), 'updated_at' => now()]);
            else $db->table('starttoo_artwork_interactions')->where($key)->delete();
            $result = $this->states([$id], $userId)[$id];
            $db->table('starttoo_interaction_requests')->insert(['user_id' => $userId, 'request_key' => $input['request_key'], 'payload_hash' => $hash,
                'response_payload' => json_encode($result, JSON_THROW_ON_ERROR)]);
            $sessions->authenticate($token, true);
            return $result;
        }, 3);
    }

    private function fail(int $status, string $code, string $message): never
    {
        throw new HttpResponseException(response()->json(compact('code', 'message'), $status));
    }
}
