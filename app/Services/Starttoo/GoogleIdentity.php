<?php

namespace App\Services\Starttoo;

use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Lcobucci\JWT\Encoding\JoseEncoder;
use Lcobucci\JWT\Signer\Key\InMemory;
use Lcobucci\JWT\Signer\Rsa\Sha256;
use Lcobucci\JWT\Token\Parser;
use Lcobucci\JWT\UnencryptedToken;
use Lcobucci\JWT\Validation\Constraint\SignedWith;
use Lcobucci\JWT\Validation\Validator;

class GoogleIdentity
{
    public function verify(string $proof): string
    {
        $clientId = config('starttoo.google_client_id');
        if (! $clientId) {
            $this->fail(503, 'AUTH_NOT_CONFIGURED', '로그인 설정을 확인하고 있어요. 잠시 후 다시 시도해주세요.');
        }
        try {
            $token = (new Parser(new JoseEncoder))->parse($proof);
            if (! $token instanceof UnencryptedToken || $token->headers()->get('alg') !== 'RS256') {
                $this->fail(401, 'INVALID_GOOGLE_TOKEN', 'Google 인증을 다시 진행해주세요.');
            }
            $kid = $token->headers()->get('kid');
            if (! is_string($kid) || strlen($kid) > 200) {
                $this->fail(401, 'INVALID_GOOGLE_TOKEN', 'Google 인증을 다시 진행해주세요.');
            }
            $keys = Cache::remember('starttoo:google:certificates:v1', 3600, fn () => $this->certificates());
            if (! isset($keys[$kid])) {
                // Google 서명 키 교체에 대응하되 알 수 없는 키로 인한 반복 조회를 제한한다.
                if (Cache::add('starttoo:google:certificates:refresh', true, 60)) {
                    $keys = $this->certificates();
                    Cache::put('starttoo:google:certificates:v1', $keys, 3600);
                }
            }
            if (! isset($keys[$kid]) || ! (new Validator)->validate($token,
                new SignedWith(new Sha256, InMemory::plainText($keys[$kid])))) {
                $this->fail(401, 'INVALID_GOOGLE_TOKEN', 'Google 인증을 다시 진행해주세요.');
            }
            $claims = $token->claims();
            $expiration = $claims->get('exp');
            $issued = $claims->get('iat');
            $subject = $claims->get('sub');
            if (! in_array($claims->get('iss'), ['accounts.google.com', 'https://accounts.google.com'], true)
                || ! $token->isPermittedFor($clientId)
                || ! $expiration instanceof \DateTimeImmutable || $expiration->getTimestamp() <= time()
                || ! $issued instanceof \DateTimeImmutable || $issued->getTimestamp() > time() + 60
                || ! is_string($subject) || $subject === '' || strlen($subject) > 255) {
                $this->fail(401, 'INVALID_GOOGLE_TOKEN', 'Google 인증을 다시 진행해주세요.');
            }

            return $subject;
        } catch (HttpResponseException $e) {
            throw $e;
        } catch (\Throwable $e) {
            // 인증 토큰과 외부 통신 오류의 원문은 응답이나 로그에 노출하지 않는다.
            $this->fail(401, 'INVALID_GOOGLE_TOKEN', 'Google 인증을 다시 진행해주세요.');
        }
    }

    private function certificates(): array
    {
        try {
            $response = Http::connectTimeout(3)->timeout(8)->get('https://www.googleapis.com/oauth2/v1/certs');
            $keys = $response->successful() ? $response->json() : null;
            if (! is_array($keys) || ! $keys || count(array_filter($keys, 'is_string')) !== count($keys)) {
                throw new \RuntimeException('Invalid key response');
            }

            return $keys;
        } catch (\Throwable $e) {
            $this->fail(503, 'GOOGLE_UNAVAILABLE', 'Google 인증을 확인하지 못했어요. 다시 시도해주세요.');
        }
    }

    private function fail(int $status, string $code, string $message): never
    {
        throw new HttpResponseException(response()->json(compact('code', 'message'), $status));
    }
}
