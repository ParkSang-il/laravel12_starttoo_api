<?php

namespace App\Http\Controllers;

use App\Services\Starttoo\AuthSessions;
use App\Services\Starttoo\Profiles;
use Illuminate\Http\Request;

class StarttooProfileController extends Controller
{
    public function show(Request $request, AuthSessions $sessions, Profiles $profiles)
    {
        return response()->json(['data' => $profiles->show($request->bearerToken() ?? '', $sessions)])
            ->header('Cache-Control', 'no-store');
    }

    public function store(Request $request, AuthSessions $sessions, Profiles $profiles)
    {
        $token = $request->bearerToken() ?? '';
        $sessions->authenticate($token);
        // 사진이 있으면 multipart, 사진이 없으면 기존 JSON 요청도 허용한다.
        $input = $request->all();
        if ($request->exists('payload')) {
            try {
                $payload = $request->input('payload');
                $input = is_string($payload) ? json_decode($payload, true, 512, JSON_THROW_ON_ERROR) : null;
            } catch (\JsonException $e) {
                $input = null;
            }
            if (!is_array($input) || array_is_list($input)) {
                return response()->json(['code' => 'INVALID_PROFILE', 'message' => '프로필 입력을 확인해주세요.'], 422);
            }
        }
        $photo = $request->file('avatar');
        if (($photo !== null && !$photo instanceof \Illuminate\Http\UploadedFile)
            || ($request->exists('avatar') && $photo === null)) {
            return response()->json(['code' => 'INVALID_PROFILE_PHOTO', 'message' => '사진 파일을 확인해주세요.'], 422);
        }
        return response()->json(['data' => $profiles->save($token, $input, $sessions, $photo)])
            ->header('Cache-Control', 'no-store');
    }
}
