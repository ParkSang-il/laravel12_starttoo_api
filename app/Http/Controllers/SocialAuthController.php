<?php

namespace App\Http\Controllers;

use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Laravel\Socialite\Facades\Socialite;
use PHPOpenSourceSaver\JWT\Facades\JWTAuth;

class SocialAuthController extends Controller
{
    /**
     * 소셜 로그인 리다이렉트 (구글)
     *
     * @return \Symfony\Component\HttpFoundation\RedirectResponse
     */
    public function redirectToGoogle()
    {
        return Socialite::driver('google')->stateless()->redirect();
    }

    /**
     * 소셜 로그인 콜백 (구글)
     *
     * @return JsonResponse
     */
    public function handleGoogleCallback()
    {
        try {
            $socialUser = Socialite::driver('google')->stateless()->user();
            return $this->handleSocialUser($socialUser, 'google');
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => '구글 로그인 중 오류가 발생했습니다.',
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    /**
     * 소셜 로그인 리다이렉트 (카카오)
     *
     * @return \Symfony\Component\HttpFoundation\RedirectResponse
     */
    public function redirectToKakao()
    {
        return Socialite::driver('kakao')->stateless()->redirect();
    }

    /**
     * 소셜 로그인 콜백 (카카오)
     *
     * @return JsonResponse
     */
    public function handleKakaoCallback()
    {
        try {
            $socialUser = Socialite::driver('kakao')->stateless()->user();
            return $this->handleSocialUser($socialUser, 'kakao');
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => '카카오 로그인 중 오류가 발생했습니다.',
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    /**
     * 소셜 로그인 리다이렉트 (인스타그램)
     *
     * @return \Symfony\Component\HttpFoundation\RedirectResponse
     */
    public function redirectToInstagram()
    {
        return Socialite::driver('instagram')->stateless()->redirect();
    }

    /**
     * 소셜 로그인 콜백 (인스타그램)
     *
     * @return JsonResponse
     */
    public function handleInstagramCallback()
    {
        try {
            $socialUser = Socialite::driver('instagram')->stateless()->user();
            return $this->handleSocialUser($socialUser, 'instagram');
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => '인스타그램 로그인 중 오류가 발생했습니다.',
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    /**
     * 소셜 사용자 정보 처리 및 JWT 토큰 발급
     *
     * @param \Laravel\Socialite\Contracts\User $socialUser
     * @param string $provider
     * @return JsonResponse
     */
    private function handleSocialUser($socialUser, string $provider): JsonResponse
    {
        try {
            // 소셜 로그인으로 이미 가입한 사용자 찾기
            $user = User::where('provider', $provider)
                ->where('provider_id', $socialUser->getId())
                ->first();

            if (!$user) {
                // 이메일로 기존 사용자 찾기 (일반 회원가입으로 가입한 경우)
                $user = User::where('email', $socialUser->getEmail())->first();

                if ($user) {
                    // 기존 사용자가 있으면 소셜 로그인 정보 업데이트
                    $user->update([
                        'provider' => $provider,
                        'provider_id' => $socialUser->getId(),
                        'avatar' => $socialUser->getAvatar(),
                        'email_verified_at' => now(),
                    ]);
                } else {
                    // 새 사용자 생성
                    $user = User::create([
                        'name' => $socialUser->getName() ?? $socialUser->getNickname() ?? 'User',
                        'email' => $socialUser->getEmail(),
                        'username' => $this->generateUsername($socialUser->getEmail(), $socialUser->getNickname()),
                        'provider' => $provider,
                        'provider_id' => $socialUser->getId(),
                        'avatar' => $socialUser->getAvatar(),
                        'profile_image' => $socialUser->getAvatar(),
                        'email_verified_at' => now(),
                        'is_verified' => true,
                    ]);
                }
            } else {
                // 소셜 로그인 사용자 정보 업데이트
                $user->update([
                    'avatar' => $socialUser->getAvatar(),
                    'profile_image' => $socialUser->getAvatar() ?? $user->profile_image,
                    'name' => $socialUser->getName() ?? $socialUser->getNickname() ?? $user->name,
                ]);
            }

            // JWT 토큰 생성
            $token = JWTAuth::fromUser($user);

            return response()->json([
                'success' => true,
                'message' => ucfirst($provider) . ' 로그인 성공',
                'data' => [
                    'user' => $user,
                    'token' => $token,
                    'token_type' => 'Bearer',
                ],
            ], 200);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => '사용자 처리 중 오류가 발생했습니다.',
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    /**
     * 고유한 사용자명 생성
     *
     * @param string|null $email
     * @param string|null $nickname
     * @return string
     */
    private function generateUsername(?string $email, ?string $nickname): string
    {
        $base = $nickname ?? explode('@', $email)[0] ?? 'user';
        $base = preg_replace('/[^a-zA-Z0-9_]/', '', $base); // 특수문자 제거
        $base = strtolower($base);
        
        $username = $base;
        $counter = 1;
        
        // 고유한 사용자명 생성
        while (User::where('username', $username)->exists()) {
            $username = $base . '_' . $counter;
            $counter++;
        }
        
        return $username;
    }
}

