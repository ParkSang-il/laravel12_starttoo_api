<?php

namespace App\Http\Controllers;

use App\Services\Starttoo\AuthSessions;
use App\Services\Starttoo\Shops;
use App\Services\Starttoo\Studios;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Http\Request;

/** 2-07~2-11 고객용 상점과 스튜디오 소식 관리. */
class StarttooShopController extends Controller
{
    public function show(Request $request, int $user, Shops $shops, AuthSessions $sessions)
    {
        return $this->json($shops->show($user, $this->viewer($request, $sessions)));
    }

    public function portfolio(Request $request, int $user, Shops $shops, AuthSessions $sessions)
    {
        return $this->json($shops->portfolio($user, $this->viewer($request, $sessions), $request->query()));
    }

    public function news(Request $request, int $user, Shops $shops, AuthSessions $sessions)
    {
        return $this->json($shops->news($user, $this->viewer($request, $sessions), $request->query()));
    }

    public function myNews(Request $request, Shops $shops, Studios $studios, AuthSessions $sessions)
    {
        return $this->json($shops->myNews($this->artist($request, $sessions, $studios)));
    }

    public function createNews(Request $request, Shops $shops, Studios $studios, AuthSessions $sessions)
    {
        return $this->json($shops->createNews($this->artist($request, $sessions, $studios), $request->all()));
    }

    public function deleteNews(Request $request, int $news, Shops $shops, Studios $studios, AuthSessions $sessions)
    {
        $shops->deleteNews($this->artist($request, $sessions, $studios), $news);
        return response()->noContent()->header('Cache-Control', 'no-store');
    }

    private function viewer(Request $request, AuthSessions $sessions): ?int
    {
        if (!$request->bearerToken()) {
            if (!config('starttoo.guest_browsing_enabled')) {
                throw new HttpResponseException(response()->json(['code' => 'GUEST_BROWSING_DISABLED', 'message' => '로그인 후 상점을 확인해주세요.'], 503));
            }
            return null;
        }
        return (int) $sessions->authenticate($request->bearerToken(), true)['account']['id'];
    }

    private function artist(Request $request, AuthSessions $sessions, Studios $studios): int
    {
        $userId = (int) $sessions->authenticate((string) $request->bearerToken(), true)['account']['id'];
        $studios->shop($userId);
        return $userId;
    }

    private function json(array $data)
    {
        return response()->json(['data' => $data])->header('Cache-Control', 'no-store');
    }
}
