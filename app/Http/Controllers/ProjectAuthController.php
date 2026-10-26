<?php

namespace App\Http\Controllers;

use App\Services\PhoneChallengeService;
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
        $token = Auth::guard('api')->login($account);

        return response()->json(['data' => [
            'access_token' => $token, 'token_type' => 'Bearer',
            'expires_in' => Auth::guard('api')->factory()->getTTL() * 60,
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
        Auth::guard('api')->logout();

        return response()->noContent();
    }
}
