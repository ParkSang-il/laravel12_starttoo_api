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
        return response()->json(['data' => $profiles->save($request->bearerToken() ?? '', $request->all(), $sessions)])
            ->header('Cache-Control', 'no-store');
    }
}
