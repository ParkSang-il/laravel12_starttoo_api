<?php

namespace App\Services\Starttoo;

use Illuminate\Support\Facades\Http;
use Lcobucci\JWT\Configuration;
use Lcobucci\JWT\Signer\Ecdsa\Sha256;
use Lcobucci\JWT\Signer\Key\InMemory;

/**
 * 탈퇴 시 Apple 로그인 연결 해지.
 * 앱이 탈퇴 직전 Apple 로그인을 다시 거쳐 받은 authorization code를
 * refresh token으로 바꾼 뒤 revoke API로 해지한다.
 * 키(.p8) 경로·Key ID가 없으면 해지를 건너뛰고 false를 돌려준다(탈퇴는 진행).
 */
class AppleRevocation
{
    public function revoke(string $authorizationCode): bool
    {
        $teamId = (string) config('starttoo.apple_team_id');
        $keyId = (string) config('starttoo.apple_key_id');
        $keyPath = (string) config('starttoo.apple_private_key');
        $clientId = (string) config('starttoo.apple_client_id');
        if ($teamId === '' || $keyId === '' || $keyPath === '' || ! is_readable($keyPath)) {
            report(new \RuntimeException('Apple revoke skipped: Sign in with Apple key is not configured.'));
            return false;
        }
        try {
            $secret = $this->clientSecret($teamId, $keyId, $keyPath, $clientId);
            $token = Http::asForm()->connectTimeout(3)->timeout(8)->post('https://appleid.apple.com/auth/token', [
                'client_id' => $clientId, 'client_secret' => $secret,
                'code' => $authorizationCode, 'grant_type' => 'authorization_code',
            ]);
            $refresh = $token->json('refresh_token');
            if (! $token->successful() || ! is_string($refresh)) {
                report(new \RuntimeException('Apple token exchange failed: HTTP '.$token->status()));
                return false;
            }
            $revoke = Http::asForm()->connectTimeout(3)->timeout(8)->post('https://appleid.apple.com/auth/revoke', [
                'client_id' => $clientId, 'client_secret' => $secret,
                'token' => $refresh, 'token_type_hint' => 'refresh_token',
            ]);
            if (! $revoke->successful()) {
                report(new \RuntimeException('Apple revoke failed: HTTP '.$revoke->status()));
            }
            return $revoke->successful();
        } catch (\Throwable $e) {
            // 키·토큰 원문은 남기지 않는다.
            report(new \RuntimeException('Apple revoke failed: '.class_basename($e)));
            return false;
        }
    }

    private function clientSecret(string $teamId, string $keyId, string $keyPath, string $clientId): string
    {
        $config = Configuration::forSymmetricSigner(new Sha256, InMemory::file($keyPath));
        $now = new \DateTimeImmutable;

        return $config->builder()
            ->issuedBy($teamId)
            ->issuedAt($now)
            ->expiresAt($now->modify('+5 minutes'))
            ->permittedFor('https://appleid.apple.com')
            ->relatedTo($clientId)
            ->withHeader('kid', $keyId)
            ->getToken($config->signer(), $config->signingKey())
            ->toString();
    }
}
