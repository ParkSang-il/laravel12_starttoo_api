<?php

namespace App\Http\Controllers;

use App\Services\Starttoo\ArtistApplications;
use App\Services\Starttoo\AuthSessions;
use App\Services\Starttoo\IdentityVerifications;
use Illuminate\Http\Request;

/** 2-12·2-13 아티스트 전환 신청. 가입을 마친 회원만 이용한다. */
class StarttooArtistController extends Controller
{
    public function show(Request $request, ArtistApplications $applications, AuthSessions $sessions)
    {
        return $this->json($applications->show($this->member($request, $sessions)));
    }

    public function submit(Request $request, ArtistApplications $applications, AuthSessions $sessions)
    {
        return $this->json($applications->submit($this->member($request, $sessions), $request->all()));
    }

    public function identity(Request $request, IdentityVerifications $identity, AuthSessions $sessions)
    {
        return $this->json($identity->verify($this->member($request, $sessions), $request->all()));
    }

    private function member(Request $request, AuthSessions $sessions): int
    {
        $account = $sessions->authenticate((string) $request->bearerToken(), true)['account'];
        if (($account['next_action'] ?? null) !== 'home') {
            abort(response()->json(['code' => 'ARTIST_SIGNUP_REQUIRED', 'message' => '가입을 완료한 뒤 신청해주세요.'], 409));
        }
        return (int) $account['id'];
    }

    private function json(array $data)
    {
        return response()->json(['data' => $data])->header('Cache-Control', 'no-store');
    }
}
