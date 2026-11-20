<?php

namespace App\Services\Starttoo;

use App\Services\ProjectDatabase;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;

/**
 * 회원 탈퇴.
 * - 데이터는 지우지 않는다. 회원 상태를 withdrawn으로 바꿔 작품·게시물을 비공개로 전환한다
 *   (공개 조회는 active 회원만 보여 준다). 작품은 공개 상태도 hidden으로 바꾼다.
 * - 댓글은 남기고 작성자를 「탈퇴한 회원」으로 보여 준다.
 * - 모든 기기 로그인·푸시 기기를 끊고, 소셜 연결을 해제해 같은 계정으로 새로 가입할 수 있게 한다.
 */
class Withdrawals
{
    public const REASONS = ['rarely_used', 'no_content', 'privacy', 'new_account', 'inconvenient', 'other'];

    public function withdraw(string $token, array $input, AuthSessions $sessions): void
    {
        if (Validator::make($input, [
            'reason' => ['required', 'string', Rule::in(self::REASONS)],
            'note' => ['nullable', 'string', 'max:300'],
            'apple_authorization_code' => ['nullable', 'string', 'max:2000'],
        ])->fails()) {
            $this->fail(422, 'INVALID_WITHDRAWAL', '탈퇴 사유를 확인해주세요.');
        }
        $userId = (int) $sessions->authenticate($token)['account']['id'];
        $db = app(ProjectDatabase::class)->database();

        // Apple 연결 해지는 외부 요청이라 트랜잭션 밖에서 먼저 시도한다. 실패해도 탈퇴는 진행한다.
        $hasApple = $db->table('starttoo_auth_identities')->where('user_id', $userId)
            ->where('provider', 'apple')->whereNull('withdrawn_at')->exists();
        if ($hasApple && is_string($input['apple_authorization_code'] ?? null) && $input['apple_authorization_code'] !== '') {
            app(AppleRevocation::class)->revoke($input['apple_authorization_code']);
        }

        $db->transaction(function () use ($db, $userId, $input) {
            $user = $db->table('starttoo_users')->where('id', $userId)->lockForUpdate()->first();
            if (! $user || $user->status === 'withdrawn') return;
            $now = now();
            $db->table('starttoo_users')->where('id', $userId)->update([
                'status' => 'withdrawn', 'withdrawn_at' => $now,
                'withdraw_reason' => $input['reason'],
                'withdraw_note' => isset($input['note']) ? trim((string) $input['note']) ?: null : null,
                'updated_at' => $now,
            ]);
            // 작품은 작성자 공개 상태도 비공개로 바꾼다. 게시물은 작성자 상태로 비공개 처리된다.
            $db->table('starttoo_artworks')->where('user_id', $userId)->where('visibility', 'public')
                ->update(['visibility' => 'hidden', 'updated_at' => $now]);
            // 같은 소셜 계정으로 새로 가입할 수 있게 연결 식별자를 바꿔 둔다(원래 값은 보관).
            foreach ($db->table('starttoo_auth_identities')->where('user_id', $userId)->whereNull('withdrawn_at')->get() as $identity) {
                $db->table('starttoo_auth_identities')->where('id', $identity->id)->update([
                    'subject' => substr("withdrawn:{$userId}:{$identity->id}:".$identity->subject, 0, 255),
                    'withdrawn_at' => $now, 'updated_at' => $now,
                ]);
            }
            $db->table('starttoo_push_devices')->where('user_id', $userId)->delete();
            $db->table('starttoo_auth_sessions')->where('user_id', $userId)->whereNull('revoked_at')
                ->update(['revoked_at' => $now, 'previous_result' => null, 'updated_at' => $now]);
        });
    }

    private function fail(int $status, string $code, string $message): never
    {
        throw new HttpResponseException(response()->json(compact('code', 'message'), $status));
    }
}
