<?php

namespace App\Http\Controllers;

use App\Services\Starttoo\Artworks;
use App\Services\Starttoo\AuthSessions;
use Illuminate\Http\Request;

class StarttooArtworkController extends Controller
{
    public function index(Request $request, Artworks $artworks, AuthSessions $sessions)
    {
        $identity = $sessions->authenticate($request->bearerToken() ?? '', true);
        return response()->json(['data' => [...$artworks->feed($request->query()), 'account' => $identity['account']]])
            ->header('Cache-Control', 'no-store');
    }

    public function options(Request $request, Artworks $artworks, AuthSessions $sessions)
    {
        $identity = $artworks->ready($request->bearerToken() ?? '', $sessions);
        return response()->json(['data' => [...$artworks->options(), 'account' => $identity['account']]])->header('Cache-Control', 'no-store');
    }

    public function store(Request $request, Artworks $artworks, AuthSessions $sessions)
    {
        $token = $request->bearerToken() ?? '';
        $artworks->ready($token, $sessions);
        try {
            $payload = $request->input('payload');
            $input = is_string($payload) ? json_decode($payload, true, 512, JSON_THROW_ON_ERROR) : null;
        } catch (\JsonException $e) {
            $input = null;
        }
        if (!is_array($input) || array_is_list($input)) {
            return response()->json(['code' => 'INVALID_ARTWORK', 'message' => '작품 입력 정보를 확인해주세요.'], 422);
        }
        $files = $request->file('photos', []);
        if (!is_array($files)) {
            return response()->json(['code' => 'INVALID_ARTWORK_PHOTOS', 'message' => '작품 사진을 확인해주세요.'], 422);
        }
        return response()->json(['data' => $artworks->create($token, $input, $files, $sessions)])->header('Cache-Control', 'no-store');
    }

    public function show(Request $request, string $artwork, Artworks $artworks, AuthSessions $sessions)
    {
        $sessions->authenticate($request->bearerToken() ?? '', true);
        return response()->json(['data' => $artworks->detail($artwork)])->header('Cache-Control', 'no-store');
    }
}
