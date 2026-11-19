<?php

namespace App\Http\Controllers;

use App\Services\Starttoo\AuthSessions;
use App\Services\Starttoo\PushCampaigns;
use Illuminate\Http\Request;

class StarttooCampaignController extends Controller
{
    public function open(Request $request, string $campaign, AuthSessions $sessions, PushCampaigns $campaigns)
    {
        $account = $sessions->authenticate($request->bearerToken() ?? '', true)['account'];
        if ($account['next_action'] !== 'home') return response()->json(['data' => ['account' => $account]])->header('Cache-Control', 'no-store');
        return response()->json(['data' => [...$campaigns->open($campaign, (int) $account['id']), 'account' => $account]])->header('Cache-Control', 'no-store');
    }
}
