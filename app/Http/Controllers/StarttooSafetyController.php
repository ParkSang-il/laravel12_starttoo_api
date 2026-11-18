<?php

namespace App\Http\Controllers;

use App\Services\Starttoo\Artworks;
use App\Services\Starttoo\AuthSessions;
use App\Services\Starttoo\Blocks;
use App\Services\Starttoo\Reports;
use Illuminate\Http\Request;

class StarttooSafetyController extends Controller
{
    public function report(Request $request, Reports $reports, AuthSessions $sessions, Artworks $artworks)
    {
        return response()->json(['data' => $reports->submit($request->bearerToken() ?? '', $request->all(), $sessions, $artworks)])->header('Cache-Control', 'no-store');
    }
    public function block(Request $request, string $user, Blocks $blocks, AuthSessions $sessions, Artworks $artworks)
    {
        return response()->json(['data' => $blocks->set((int) $user, $request->bearerToken() ?? '', $request->all(), $sessions, $artworks)])->header('Cache-Control', 'no-store');
    }
    public function index(Request $request, Blocks $blocks, AuthSessions $sessions, Artworks $artworks)
    {
        return response()->json(['data' => $blocks->listing($request->bearerToken() ?? '', $request->query(), $sessions, $artworks)])->header('Cache-Control', 'no-store');
    }
}
