<?php

namespace App\Http\Controllers;

use App\Services\Starttoo\Activities;
use App\Services\Starttoo\AuthSessions;
use Illuminate\Http\Request;

class StarttooActivityController extends Controller
{
    public function index(Request $request, Activities $activities, AuthSessions $sessions)
    {
        $identity = $sessions->authenticate($request->bearerToken() ?? '', true);
        $data = $identity['account']['next_action'] === 'home'
            ? $activities->listing((int) $identity['account']['id'], $request->query())
            : ['items' => [], 'next_cursor' => null];
        return response()->json(['data' => [...$data, 'account' => $identity['account']]])->header('Cache-Control', 'no-store');
    }
}
