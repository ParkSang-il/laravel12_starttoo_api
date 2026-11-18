<?php

namespace App\Services\Starttoo;

use App\Services\ProjectDatabase;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Support\Facades\Validator;

class HiddenArtworks
{
    public function set(string $id, string $token, array $input, AuthSessions $sessions, Artworks $artworks): array
    {
        $viewer = (int) $artworks->ready($token, $sessions)['account']['id'];
        if (Validator::make($input, ['request_key' => ['required', 'uuid'], 'active' => ['required', 'boolean']])->fails()
            || !is_bool($input['active'] ?? null)) throw new HttpResponseException(response()->json(['code' => 'INVALID_SAFETY_REQUEST', 'message' => '숨김 요청을 확인해주세요.'], 422));
        $db = app(ProjectDatabase::class)->database();
        $author = $db->table('starttoo_artworks')->where('id', $id)->value('user_id');
        return $db->transaction(function () use ($db, $viewer, $author, $id, $input, $token, $sessions, $artworks) {
            $db->table('starttoo_users')->whereIn('id', array_filter([$viewer, $author]))->orderBy('id')->lockForUpdate()->get();
            $artworks->ready($token, $sessions);
            $requests = app(Blocks::class);
            $hash = hash('sha256', json_encode(['hide', $id, $input['active']], JSON_THROW_ON_ERROR));
            if ($result = $requests->retry($viewer, $input['request_key'], $hash)) return $result;
            $detail = $artworks->detail($id, $viewer);
            if ($detail['can_edit']) throw new HttpResponseException(response()->json(['code' => 'CANNOT_HIDE_SELF', 'message' => '본인 작품은 공개 설정에서 관리해주세요.'], 422));
            $key = ['user_id' => $viewer, 'artwork_id' => $id];
            if ($input['active']) $db->table('starttoo_hidden_artworks')->insertOrIgnore([...$key, 'created_at' => now()]);
            else $db->table('starttoo_hidden_artworks')->where($key)->delete();
            $result = ['artwork_id' => $id, 'hidden' => $input['active']];
            $requests->remember($viewer, $input['request_key'], $hash, $result);
            return $result;
        }, 3);
    }
}
