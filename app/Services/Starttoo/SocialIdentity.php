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

/**
 * 카카오·네이버·Apple 로그인 증명을 서버에서 검증하고 제공자 고유 ID(subject)를 돌려준다.
 * 토큰 원문과 외부 응답 원문은 응답·로그에 남기지 않는다.
 */
class SocialIdentity
{
    /**
     * 카카오 액세스 토큰: 토큰 정보 API로 우리 앱(app_id)에서 발급된 토큰인지 확인한다.
     * 반환: [subject, 가입용 프로필(nickname, image)]
     */
    public function kakao(string $accessToken): array
    {
        $appId = (string) config('starttoo.kakao_app_id');
        if ($appId === '') {
            $this->fail(503, 'AUTH_NOT_CONFIGURED', '카카오 로그인을 준비하고 있어요. 잠시 후 다시 시도해주세요.');
        }
        $info = $this->getJson('https://kapi.kakao.com/v1/user/access_token_info', $accessToken, 'KAKAO');
        $id = $info['id'] ?? null;
        if ((string) ($info['app_id'] ?? '') !== $appId || ! is_numeric($id)) {
            $this->fail(401, 'INVALID_KAKAO_TOKEN', '카카오 인증을 다시 진행해주세요.');
        }
        // 프로필은 가입 편의용이라 실패해도 로그인은 진행한다.
        $profile = [];
        try {
            $me = Http::connectTimeout(3)->timeout(5)->withToken($accessToken)->acceptJson()
                ->get('https://kapi.kakao.com/v2/user/me')->json();
            $kakao = is_array($me) ? ($me['kakao_account']['profile'] ?? []) : [];
            $profile = [
                'nickname' => $kakao['nickname'] ?? null,
                'image' => ($kakao['is_default_image'] ?? false) ? null : ($kakao['profile_image_url'] ?? null),
            ];
        } catch (\Throwable) {
        }

        return [(string) $id, $profile];
    }

    /** 네이버 액세스 토큰: 회원 프로필 API의 고유 ID를 사용한다. 반환: [subject, 가입용 프로필] */
    public function naver(string $accessToken): array
    {
        if (! config('starttoo.naver_enabled')) {
            $this->fail(503, 'AUTH_NOT_CONFIGURED', '네이버 로그인을 준비하고 있어요. 잠시 후 다시 시도해주세요.');
        }
        $me = $this->getJson('https://openapi.naver.com/v1/nid/me', $accessToken, 'NAVER');
        $id = $me['response']['id'] ?? null;
        if (($me['resultcode'] ?? null) !== '00' || ! is_string($id) || $id === '' || strlen($id) > 255) {
            $this->fail(401, 'INVALID_NAVER_TOKEN', '네이버 인증을 다시 진행해주세요.');
        }

        return [$id, [
            'nickname' => $me['response']['nickname'] ?? null,
            'image' => $me['response']['profile_image'] ?? null,
        ]];
    }

    /** Apple identity token(JWT): Apple 공개키 서명, 발급자, 대상(번들 ID), 만료를 확인한다. */
    public function apple(string $identityToken): string
    {
        $clientId = (string) config('starttoo.apple_client_id');
        $invalid = fn () => $this->fail(401, 'INVALID_APPLE_TOKEN', 'Apple 인증을 다시 진행해주세요.');
        try {
            $token = (new Parser(new JoseEncoder))->parse($identityToken);
            if (! $token instanceof UnencryptedToken || $token->headers()->get('alg') !== 'RS256') $invalid();
            $kid = $token->headers()->get('kid');
            if (! is_string($kid) || strlen($kid) > 200) $invalid();
            $keys = Cache::remember('starttoo:apple:keys:v1', 3600, fn () => $this->appleKeys());
            if (! isset($keys[$kid]) && Cache::add('starttoo:apple:keys:refresh', true, 60)) {
                // Apple 키 교체에 대응하되 알 수 없는 키로 인한 반복 조회는 1분에 한 번으로 제한한다.
                $keys = $this->appleKeys();
                Cache::put('starttoo:apple:keys:v1', $keys, 3600);
            }
            if (! isset($keys[$kid]) || ! (new Validator)->validate($token,
                new SignedWith(new Sha256, InMemory::plainText($keys[$kid])))) $invalid();
            $claims = $token->claims();
            $expiration = $claims->get('exp');
            $subject = $claims->get('sub');
            if ($claims->get('iss') !== 'https://appleid.apple.com'
                || ! $token->isPermittedFor($clientId)
                || ! $expiration instanceof \DateTimeImmutable || $expiration->getTimestamp() <= time()
                || ! is_string($subject) || $subject === '' || strlen($subject) > 255) $invalid();

            return $subject;
        } catch (HttpResponseException $e) {
            throw $e;
        } catch (\Throwable) {
            $invalid();
        }
    }

    private function getJson(string $url, string $accessToken, string $provider): array
    {
        try {
            $response = Http::connectTimeout(3)->timeout(8)->withToken($accessToken)->acceptJson()->get($url);
        } catch (\Throwable) {
            $this->fail(503, $provider.'_UNAVAILABLE', '로그인 확인이 지연되고 있어요. 다시 시도해주세요.');
        }
        if ($response->status() >= 500) {
            $this->fail(503, $provider.'_UNAVAILABLE', '로그인 확인이 지연되고 있어요. 다시 시도해주세요.');
        }
        $json = $response->successful() ? $response->json() : null;
        if (! is_array($json)) {
            $this->fail(401, 'INVALID_'.$provider.'_TOKEN', '인증을 다시 진행해주세요.');
        }

        return $json;
    }

    /** Apple JWKS(RSA n, e)를 kid → PEM 공개키로 바꾼다. */
    private function appleKeys(): array
    {
        try {
            $response = Http::connectTimeout(3)->timeout(8)->get('https://appleid.apple.com/auth/keys');
            $keys = [];
            foreach (($response->successful() ? $response->json('keys') : null) ?? [] as $jwk) {
                if (($jwk['kty'] ?? null) === 'RSA' && is_string($jwk['kid'] ?? null)
                    && is_string($jwk['n'] ?? null) && is_string($jwk['e'] ?? null)) {
                    $keys[$jwk['kid']] = $this->rsaPem($jwk['n'], $jwk['e']);
                }
            }
            if (! $keys) throw new \RuntimeException('no keys');

            return $keys;
        } catch (\Throwable) {
            $this->fail(503, 'APPLE_UNAVAILABLE', 'Apple 인증을 확인하지 못했어요. 다시 시도해주세요.');
        }
    }

    private function rsaPem(string $n, string $e): string
    {
        $b64 = fn (string $v) => base64_decode(strtr($v, '-_', '+/').str_repeat('=', (4 - strlen($v) % 4) % 4), true);
        $len = function (int $l): string {
            if ($l < 0x80) return chr($l);
            $bytes = ltrim(pack('N', $l), "\0");
            return chr(0x80 | strlen($bytes)).$bytes;
        };
        $int = function (string $bin) use ($len): string {
            if (ord($bin[0]) > 0x7F) $bin = "\0".$bin;
            return "\x02".$len(strlen($bin)).$bin;
        };
        $rsa = $int($b64($n)).$int($b64($e));
        $rsa = "\x30".$len(strlen($rsa)).$rsa;
        $algo = "\x30\x0D\x06\x09\x2A\x86\x48\x86\xF7\x0D\x01\x01\x01\x05\x00";
        $bit = "\x03".$len(strlen($rsa) + 1)."\x00".$rsa;
        $spki = "\x30".$len(strlen($algo) + strlen($bit)).$algo.$bit;

        return "-----BEGIN PUBLIC KEY-----\n".chunk_split(base64_encode($spki), 64, "\n")."-----END PUBLIC KEY-----\n";
    }

    private function fail(int $status, string $code, string $message): never
    {
        throw new HttpResponseException(response()->json(compact('code', 'message'), $status));
    }
}
