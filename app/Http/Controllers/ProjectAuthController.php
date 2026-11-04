<?php

namespace App\Http\Controllers;

use App\Services\PhoneChallengeService;
use App\Services\ProjectDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

class ProjectAuthController extends Controller
{
    public function requestCode(Request $request, PhoneChallengeService $service)
    {
        $data = $request->validate(['phone' => ['required', 'string', 'regex:/^010[0-9]{8}$/D']]);

        return response()->json(['data' => $service->issue(
            $request->attributes->get('project'), $data['phone'])], 201);
    }

    public function verify(Request $request, PhoneChallengeService $service)
    {
        $data = $request->validate([
            'challenge_id' => ['required', 'uuid'],
            'code' => ['required', 'string', 'regex:/^[0-9]{6}$/D'],
        ]);
        $account = $service->consume($request->attributes->get('project'),
            $data['challenge_id'], $data['code']);
        $persistent = $request->attributes->get('project')->code === 'streetcat';
        $guard = Auth::guard('api');
        // The JWT payload factory is shared within a worker. Reset its claims for each login.
        $guard->factory()->setDefaultClaims(['iss', 'iat', 'exp', 'nbf', 'jti'])
            ->setTTL($persistent ? null : config('jwt.ttl'));
        $token = $guard->login($account);
        if ($persistent) {
            app(ProjectDatabase::class)->database()->table('project_auth_sessions')->insert([
                'id' => hash('sha256', $guard->payload()->get('jti')),
                'account_id' => $account->id,
                'last_seen_at' => now(),
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }

        return response()->json(['data' => [
            'access_token' => $token, 'token_type' => 'Bearer',
            'expires_in' => $persistent ? null : $guard->factory()->getTTL() * 60,
            'account' => $account,
        ]]);
    }

    public function me(Request $request)
    {
        return response()->json(['data' => Auth::guard('api')->user()]);
    }

    public function updateProfile(Request $request)
    {
        $account = Auth::guard('api')->user();
        $name = $request->input('display_name');
        if ($account->nickname_tag !== null && is_string($name) && preg_match('/#([0-9]+)$/u', $name, $match)) {
            if ($match[1] !== (string) $account->nickname_tag) {
                throw \Illuminate\Validation\ValidationException::withMessages(['display_name' => '#번호는 변경할 수 없습니다.']);
            }
            // Compatibility with older apps that edited the previously combined name.
            $request->merge(['display_name' => substr($name, 0, -strlen($match[0]))]);
        }
        $data = $request->validate(['display_name' => ['required', 'string', 'max:50'],
            'nickname_tag' => ['prohibited'], 'nickname' => ['prohibited']]);
        $account->fill($data)->save();

        return response()->json(['data' => $account]);
    }

    public function uploadAvatar(Request $request, \App\Services\ProfileAvatar $avatars)
    {
        $request->validate(['photo' => ['required', 'file', 'image', 'mimes:jpg,jpeg,png,webp',
            'max:5120', 'dimensions:max_width=4096,max_height=4096']], [
            'photo.required' => '사진을 선택해 주세요.',
            'photo.image' => '이미지 파일만 등록할 수 있습니다.',
            'photo.mimes' => 'JPG, PNG, WebP 사진을 선택해 주세요.',
            'photo.max' => '사진은 5MB 이하로 등록해 주세요.',
            'photo.dimensions' => '사진은 가로·세로 4096px 이하로 등록해 주세요.',
        ]);
        return response()->json(['data' => $avatars->upload(Auth::guard('api')->user(), $request->file('photo'))]);
    }

    public function deleteAvatar(\App\Services\ProfileAvatar $avatars)
    {
        return response()->json(['data' => $avatars->delete(Auth::guard('api')->user())]);
    }

    public function logout()
    {
        if (request()->attributes->get('project')->code === 'streetcat') {
            app(ProjectDatabase::class)->database()->table('project_auth_sessions')
                ->where('id', hash('sha256', Auth::guard('api')->payload()->get('jti')))
                ->delete();
        } else {
            Auth::guard('api')->logout();
        }

        return response()->noContent();
    }
}
