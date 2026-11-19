<?php

namespace App\Http\Controllers;

use App\Services\ProjectDatabase;
use App\Services\Starttoo\AuthSessions;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Crypt;

class StarttooPushController extends Controller
{
    public function store(Request $request, AuthSessions $sessions)
    {
        $input = $request->validate(['installation_id' => ['required', 'uuid'], 'enabled' => ['required', 'boolean'],
            'platform' => ['required', 'in:android,ios'], 'token' => ['required_if:enabled,true', 'nullable', 'string', 'min:20', 'max:4096']]);
        $identity = $sessions->authenticate($request->bearerToken() ?? '', true);
        $db = app(ProjectDatabase::class)->database();
        $db->transaction(function () use ($db, $sessions, $request, $input, $identity) {
            $db->table('starttoo_users')->where('id', $identity['account']['id'])->lockForUpdate()->first();
            $identity = $sessions->authenticate($request->bearerToken() ?? '', true);
            if (!$input['enabled']) {
                $db->table('starttoo_push_devices')->where('installation_id', $input['installation_id'])->where('session_id', $identity['session']->id)->delete();
                return;
            }
            if ($identity['account']['next_action'] !== 'home') abort(409, '가입을 완료해주세요.');
            $hash = hash('sha256', $input['token']);
            // 회원/토큰 변경은 이전 수신 기기 작업을 FK로 함께 제거한다.
            $db->table('starttoo_push_devices')->where(function ($q) use ($input, $hash) {
                $q->where('installation_id', $input['installation_id'])->orWhere('token_hash', $hash);
            })->where(function ($q) use ($identity, $hash, $input) {
                $q->where('session_id', '!=', $identity['session']->id)->orWhere('token_hash', '!=', $hash)->orWhere('installation_id', '!=', $input['installation_id']);
            })->delete();
            $db->table('starttoo_push_devices')->updateOrInsert(['installation_id' => $input['installation_id']], [
                'user_id' => (int) $identity['account']['id'], 'session_id' => $identity['session']->id,
                'token_hash' => $hash, 'token_ciphertext' => Crypt::encryptString($input['token']),
                'platform' => $input['platform'], 'updated_at' => now(),
            ]);
        }, 3);
        return response()->json(['data' => ['registered' => (bool) $input['enabled']]])->header('Cache-Control', 'no-store');
    }
}
