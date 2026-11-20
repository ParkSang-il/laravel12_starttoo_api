<?php

namespace App\Http\Controllers;

use App\Services\Starttoo\AuthSessions;
use App\Services\Starttoo\Collections;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Http\Request;

/** 1-14 저장 컬렉션 API. 본인 컬렉션은 me, 다른 회원은 회원 번호로 조회한다. */
class StarttooCollectionsController extends Controller
{
    public function index(Request $request, string $user, Collections $collections, AuthSessions $sessions)
    {
        $viewer = $this->viewer($request, $sessions, $user === 'me');
        $owner = $user === 'me' ? $viewer : (int) $user;

        return $this->json($collections->index($owner, $viewer, $request->query()));
    }

    public function store(Request $request, Collections $collections, AuthSessions $sessions)
    {
        return $this->json($collections->create($this->viewer($request, $sessions, true), $request->all()), 201);
    }

    public function update(Request $request, int $collection, Collections $collections, AuthSessions $sessions)
    {
        return $this->json($collections->update($this->viewer($request, $sessions, true), $collection, $request->all()));
    }

    public function destroy(Request $request, int $collection, Collections $collections, AuthSessions $sessions)
    {
        $collections->delete($this->viewer($request, $sessions, true), $collection);

        return response()->noContent()->header('Cache-Control', 'no-store');
    }

    public function item(Request $request, int $collection, Collections $collections, AuthSessions $sessions)
    {
        return $this->json($collections->put($this->viewer($request, $sessions, true), $collection, $request->all()));
    }

    private function viewer(Request $request, AuthSessions $sessions, bool $required): ?int
    {
        if (! $request->bearerToken()) {
            if ($required || ! config('starttoo.guest_browsing_enabled')) {
                throw new HttpResponseException(response()->json(['code' => 'SESSION_EXPIRED', 'message' => '로그인이 필요해요.'], 401));
            }
            return null;
        }

        return (int) $sessions->authenticate($request->bearerToken(), true)['account']['id'];
    }

    private function json(array $data, int $status = 200)
    {
        return response()->json(['data' => $data], $status)->header('Cache-Control', 'no-store');
    }
}
