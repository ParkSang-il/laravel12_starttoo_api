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
    /** 내가 신고한 내역 */
    public function reports(Request $request, Reports $reports, AuthSessions $sessions, Artworks $artworks)
    {
        return response()->json(['data' => $reports->mine($request->bearerToken() ?? '', $request->query(), $sessions, $artworks)])->header('Cache-Control', 'no-store');
    }
    public function block(Request $request, string $user, Blocks $blocks, AuthSessions $sessions, Artworks $artworks)
    {
        return response()->json(['data' => $blocks->set((int) $user, $request->bearerToken() ?? '', $request->all(), $sessions, $artworks)])->header('Cache-Control', 'no-store');
    }
    public function hide(Request $request, string $artwork, \App\Services\Starttoo\HiddenArtworks $hidden, AuthSessions $sessions, Artworks $artworks)
    {
        return response()->json(['data' => $hidden->set($artwork, $request->bearerToken() ?? '', $request->all(), $sessions, $artworks)])->header('Cache-Control', 'no-store');
    }
    public function index(Request $request, Blocks $blocks, AuthSessions $sessions, Artworks $artworks)
    {
        return response()->json(['data' => $blocks->listing($request->bearerToken() ?? '', $request->query(), $sessions, $artworks)])->header('Cache-Control', 'no-store');
    }
}
