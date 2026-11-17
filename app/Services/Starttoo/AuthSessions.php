<?php

namespace App\Services\Starttoo;

use App\Services\ProjectDatabase;
use Illuminate\Database\Connection;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Str;

class AuthSessions
{
    private function db(): Connection
    {
        return app(ProjectDatabase::class)->database();
    }

    public function login(string $provider, string $subject): array
    {
        $db = $this->db();

        return $db->transaction(function () use ($db, $provider, $subject) {
            // 제공자와 고유 식별자의 유일 제약으로 중복 계정 연결을 차단한다.
            // 회원과 인증 연결은 같은 트랜잭션에서 함께 생성하거나 롤백한다.
            $identity = $db->table('starttoo_auth_identities')->where(compact('provider', 'subject'))->first();
            if (! $identity) {
                $userId = $db->table('starttoo_users')->insertGetId([
                    'status' => 'active', 'created_at' => now(), 'updated_at' => now(),
                ]);
                $db->table('starttoo_auth_identities')->insert([
                    'user_id' => $userId, 'provider' => $provider, 'subject' => $subject,
                    'created_at' => now(), 'updated_at' => now(),
                ]);
            } else {
                $userId = $identity->user_id;
            }
            $user = $db->table('starttoo_users')->where('id', $userId)->lockForUpdate()->first();
            $this->activeUser($user);
            $session = (object) ['id' => (string) Str::uuid(), 'user_id' => $userId,
                'idle_expires_at' => now()->addDays(30), 'absolute_expires_at' => now()->addDays(90)];
            [$tokens, $values] = $this->tokens($session);
            $db->table('starttoo_auth_sessions')->insert([
                ...$values, 'id' => $session->id, 'user_id' => $userId,
                'idle_expires_at' => $session->idle_expires_at,
                'absolute_expires_at' => $session->absolute_expires_at, 'created_at' => now(),
            ]);

            return [...$tokens, 'account' => $this->account($user)];
        }, 3);
    }

    public function refresh(string $proof, string $key): array
    {
        $hash = hash('sha256', $proof);
        $requestHash = hash('sha256', $key);
        $result = $this->db()->transaction(function () use ($hash, $requestHash) {
            $table = $this->db()->table('starttoo_auth_sessions');
            $session = (clone $table)->where(function ($q) use ($hash) {
                $q->where('refresh_hash', $hash)->orWhere('previous_refresh_hash', $hash);
            })->lockForUpdate()->first();
            if (! $session) {
                // 여러 번 회전한 뒤 오래된 토큰이 재사용되어도 해당 기기 세션을 폐기한다.
                $used = $this->db()->table('starttoo_used_refresh_tokens')->where('token_hash', $hash)->first();
                if ($used) {
                    $table->where('id', $used->session_id)->update(['revoked_at' => now(), 'previous_result' => null]);
                }

                return null;
            }
            if ($this->expired($session)) {
                return null;
            }
            $user = $this->db()->table('starttoo_users')->where('id', $session->user_id)->first();
            if (! $user || $user->status !== 'active') {
                $table->where('id', $session->id)->update(['revoked_at' => now()]);

                return null;
            }
            if ($session->previous_refresh_hash === $hash) {
                if ($session->previous_request_hash === $requestHash && $session->retry_until
                    && now()->lt(Carbon::parse($session->retry_until))) {
                    return json_decode(Crypt::decryptString($session->previous_result), true, 512, JSON_THROW_ON_ERROR);
                }
                // 세션 폐기를 먼저 커밋한 뒤 트랜잭션 밖에서 재인증 오류를 반환한다.
                $table->where('id', $session->id)->update(['revoked_at' => now(), 'previous_result' => null]);

                return null;
            }
            $this->db()->table('starttoo_used_refresh_tokens')->insert(['token_hash' => $hash, 'session_id' => $session->id]);
            [$tokens, $values] = $this->tokens($session);
            $result = [...$tokens, 'account' => $this->account($user)];
            $table->where('id', $session->id)->update([
                ...$values, 'previous_refresh_hash' => $hash, 'previous_request_hash' => $requestHash,
                'previous_result' => Crypt::encryptString(json_encode($result, JSON_THROW_ON_ERROR)),
                'retry_until' => now()->addSeconds(30),
            ]);

            return $result;
        }, 3);
        if ($result === null) {
            $this->fail(401, 'SESSION_EXPIRED', '로그인이 만료됐어요. 다시 로그인해주세요.');
        }

        return $result;
    }

    public function authenticate(string $token, bool $touch = false): array
    {
        $session = $token === '' ? null : $this->db()->table('starttoo_auth_sessions')->where('access_hash', hash('sha256', $token))->first();
        if (! $session || $this->expired($session)) {
            $this->fail(401, 'SESSION_EXPIRED', '다시 로그인해주세요.');
        }
        $user = $this->db()->table('starttoo_users')->where('id', $session->user_id)->first();
        $this->activeUser($user);
        if (now()->gte(Carbon::parse($session->access_expires_at))) {
            $this->fail(401, 'ACCESS_EXPIRED', '로그인을 갱신해주세요.');
        }
        if ($touch) {
            $this->db()->table('starttoo_auth_sessions')->where('id', $session->id)->whereNull('revoked_at')->update([
                'idle_expires_at' => min(now()->addDays(30), Carbon::parse($session->absolute_expires_at)),
                'updated_at' => now(),
            ]);
        }

        return ['session' => $session, 'account' => $this->account($user)];
    }

    public function logout(string $token): void
    {
        $this->db()->table('starttoo_auth_sessions')->where('access_hash', hash('sha256', $token))
            ->update(['revoked_at' => now(), 'previous_result' => null, 'updated_at' => now()]);
    }

    private function tokens(object $session): array
    {
        $access = bin2hex(random_bytes(32));
        $refresh = bin2hex(random_bytes(48));
        $expires = min(now()->addMinutes(15), Carbon::parse($session->idle_expires_at), Carbon::parse($session->absolute_expires_at));

        return [[
            'access_token' => $access, 'refresh_token' => $refresh,
            'access_expires_at' => $expires->toIso8601String(), 'token_type' => 'Bearer',
        ], [
            'access_hash' => hash('sha256', $access), 'refresh_hash' => hash('sha256', $refresh),
            'access_expires_at' => $expires, 'updated_at' => now(),
        ]];
    }

    private function expired(object $session): bool
    {
        return $session->revoked_at !== null || now()->gte(Carbon::parse($session->idle_expires_at))
            || now()->gte(Carbon::parse($session->absolute_expires_at));
    }

    private function activeUser(?object $user): void
    {
        if (! $user || $user->status !== 'active') {
            $this->fail(403, 'ACCOUNT_SUSPENDED', '이용할 수 없는 계정이에요. 고객지원에 문의해주세요.');
        }
    }

    private function account(object $user): array
    {
        return ['id' => $user->id, 'display_name' => $user->display_name,
            'next_action' => $user->terms_version !== config('starttoo.terms_version') ? 'terms'
                : ($user->profile_completed_at === null ? 'profile' : 'home')];
    }

    private function fail(int $status, string $code, string $message): never
    {
        throw new HttpResponseException(response()->json(compact('code', 'message'), $status));
    }
}
