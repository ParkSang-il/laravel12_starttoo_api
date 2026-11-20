<?php

namespace App\Http\Controllers;

use App\Services\Starttoo\AuthSessions;
use App\Services\Starttoo\GoogleIdentity;
use App\Services\Starttoo\SignupProfileSeed;
use App\Services\Starttoo\SocialIdentity;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Http\Request;

class StarttooAuthController extends Controller
{
    public function google(Request $request, GoogleIdentity $google, AuthSessions $sessions)
    {
        $input = $request->validate(['id_token' => ['required', 'string', 'max:10000']]);
        $subject = $google->verify($input['id_token']);
        try {
            $data = $sessions->login('google', $subject);
        } catch (UniqueConstraintViolationException $e) {
            // 최초 로그인 동시 요청의 중복 삽입은 롤백한 뒤 기존 계정으로 다시 조회한다.
            $data = $sessions->login('google', $subject);
        }

        return response()->json(['data' => $data])->header('Cache-Control', 'no-store');
    }

    public function kakao(Request $request, SocialIdentity $identity, AuthSessions $sessions)
    {
        $input = $request->validate(['access_token' => ['required', 'string', 'max:4000']]);

        [$subject, $profile] = $identity->kakao($input['access_token']);

        return $this->loginAs($sessions, 'kakao', $subject, $profile);
    }

    public function naver(Request $request, SocialIdentity $identity, AuthSessions $sessions)
    {
        $input = $request->validate(['access_token' => ['required', 'string', 'max:4000']]);

        [$subject, $profile] = $identity->naver($input['access_token']);

        return $this->loginAs($sessions, 'naver', $subject, $profile);
    }

    public function apple(Request $request, SocialIdentity $identity, AuthSessions $sessions)
    {
        $input = $request->validate(['identity_token' => ['required', 'string', 'max:10000']]);

        return $this->loginAs($sessions, 'apple', $identity->apple($input['identity_token']));
    }

    private function loginAs(AuthSessions $sessions, string $provider, string $subject, array $profile = [])
    {
        try {
            $data = $sessions->login($provider, $subject);
        } catch (UniqueConstraintViolationException) {
            // 최초 로그인 동시 요청의 중복 삽입은 롤백한 뒤 기존 계정으로 다시 조회한다.
            $data = $sessions->login($provider, $subject);
        }
        // 가입을 마치지 않은 회원이면 소셜 프로필 사진·닉네임을 가입 화면 기본값으로 준비한다.
        if ($profile && ($data['account']['next_action'] ?? 'home') !== 'home') {
            app(SignupProfileSeed::class)->apply((int) $data['account']['id'], $profile);
        }

        return response()->json(['data' => $data])->header('Cache-Control', 'no-store');
    }

    public function refresh(Request $request, AuthSessions $sessions)
    {
        $input = $request->validate([
            'refresh_token' => ['required', 'string', 'size:96'],
            'request_key' => ['required', 'uuid'],
        ]);

        return response()->json(['data' => $sessions->refresh($input['refresh_token'], $input['request_key'])])
            ->header('Cache-Control', 'no-store');
    }

    public function me(Request $request, AuthSessions $sessions)
    {
        $identity = $sessions->authenticate($request->bearerToken() ?? '', true);

        return response()->json(['data' => $identity['account']])->header('Cache-Control', 'no-store');
    }

    public function logout(Request $request, AuthSessions $sessions)
    {
        $sessions->logout($request->bearerToken() ?? '');

        return response()->noContent();
    }
}
