<?php

namespace App\Http\Controllers;

use App\Services\Starttoo\Artworks;
use App\Services\Starttoo\ArtworkEdits;
use App\Services\Starttoo\ArtworkInteractions;
use App\Services\Starttoo\ArtworkUploads;
use App\Services\Starttoo\AuthSessions;
use Illuminate\Http\Request;

class StarttooArtworkController extends Controller
{
    public function index(Request $request, Artworks $artworks, AuthSessions $sessions)
    {
        if (!$request->bearerToken() && $request->query('scope', 'public') === 'public' && !config('starttoo.guest_browsing_enabled')) {
            return response()->json(['code' => 'GUEST_BROWSING_DISABLED', 'message' => '로그인 후 작품을 확인해주세요.'], 503);
        }
        $identity = $request->bearerToken() ? $sessions->authenticate($request->bearerToken(), true) : null;
        return response()->json(['data' => [...$artworks->feed($request->query(), isset($identity['account']['id']) ? (int) $identity['account']['id'] : null), 'account' => $identity['account'] ?? null]])
            ->header('Cache-Control', 'no-store');
    }

    public function options(Request $request, Artworks $artworks, AuthSessions $sessions)
    {
        $identity = $artworks->ready($request->bearerToken() ?? '', $sessions);
        return response()->json(['data' => [...$artworks->options(), 'account' => $identity['account']]])->header('Cache-Control', 'no-store');
    }

    public function interaction(Request $request, string $artwork, ArtworkInteractions $interactions, Artworks $artworks, AuthSessions $sessions)
    {
        return response()->json(['data' => $interactions->set($artwork, $request->bearerToken() ?? '', $request->all(), $sessions, $artworks)])
            ->header('Cache-Control', 'no-store');
    }

    /** multipart의 payload(JSON 문자열) 또는 JSON 본문. 객체가 아니면 null. */
    private function input(Request $request): ?array
    {
        $payload = $request->input('payload');
        try {
            $input = is_string($payload) ? json_decode($payload, true, 512, JSON_THROW_ON_ERROR) : $request->json()->all();
        } catch (\JsonException $e) {
            return null;
        }
        return is_array($input) && ($input === [] || !array_is_list($input)) ? $input : null;
    }

    /** 사진 1장 선업로드: multipart payload {request_key}, 파일 photo. */
    public function upload(Request $request, ArtworkUploads $uploads, Artworks $artworks, AuthSessions $sessions)
    {
        $input = $this->input($request);
        if ($input === null) {
            return response()->json(['code' => 'INVALID_ARTWORK_PHOTOS', 'message' => '사진 업로드 요청을 확인해주세요.'], 422);
        }
        return response()->json(['data' => $uploads->upload($request->bearerToken() ?? '', $input, $request->file('photo'), $sessions, $artworks)])
            ->header('Cache-Control', 'no-store');
    }

    /** 작품 등록: JSON 본문, 사진은 media_ids. */
    public function store(Request $request, Artworks $artworks, AuthSessions $sessions)
    {
        $token = $request->bearerToken() ?? '';
        $artworks->ready($token, $sessions);
        $input = $this->input($request);
        if ($input === null) {
            return response()->json(['code' => 'INVALID_ARTWORK', 'message' => '작품 입력 정보를 확인해주세요.'], 422);
        }
        return response()->json(['data' => $artworks->create($token, $input, $sessions)])->header('Cache-Control', 'no-store');
    }

    /** 작성자 작품 삭제. */
    public function destroy(Request $request, string $artwork, Artworks $artworks, AuthSessions $sessions)
    {
        return response()->json(['data' => $artworks->delete($artwork, $request->bearerToken() ?? '', $sessions)])
            ->header('Cache-Control', 'no-store');
    }

    public function show(Request $request, string $artwork, Artworks $artworks, AuthSessions $sessions)
    {
        if (!$request->bearerToken() && !config('starttoo.guest_browsing_enabled')) {
            return response()->json(['code' => 'GUEST_BROWSING_DISABLED', 'message' => '로그인 후 작품을 확인해주세요.'], 503);
        }
        $identity = $request->bearerToken() ? $sessions->authenticate($request->bearerToken(), true) : null;
        return response()->json(['data' => $artworks->detail($artwork, isset($identity['account']['id']) ? (int) $identity['account']['id'] : null)])->header('Cache-Control', 'no-store');
    }

    public function update(Request $request, string $artwork, ArtworkEdits $edits, Artworks $artworks, AuthSessions $sessions)
    {
        $token = $request->bearerToken() ?? '';
        $artworks->ready($token, $sessions);
        $input = $this->input($request);
        if ($input === null) {
            return response()->json(['code' => 'INVALID_ARTWORK', 'message' => '작품 입력을 확인해주세요.'], 422);
        }
        return response()->json(['data' => $edits->save($artwork, $token, $input, $sessions, $artworks)])
            ->header('Cache-Control', 'no-store');
    }
}
