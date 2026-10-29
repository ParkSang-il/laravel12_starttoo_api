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
        $data = $request->validate(['display_name' => ['required', 'string', 'max:50']]);
        $account = Auth::guard('api')->user();
        $account->fill($data)->save();

        return response()->json(['data' => $account]);
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
