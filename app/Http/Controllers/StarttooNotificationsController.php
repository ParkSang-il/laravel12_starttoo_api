<?php

namespace App\Http\Controllers;

use App\Services\Starttoo\AuthSessions;
use App\Services\Starttoo\Notifications;
use App\Services\Starttoo\UserPreferences;
use Illuminate\Http\Request;

class StarttooNotificationsController extends Controller
{
    public function handle(Request $request, AuthSessions $sessions, Notifications $notifications, UserPreferences $preferences, ?string $notification = null)
    {
        $account = $sessions->authenticate($request->bearerToken() ?? '', true)['account'];
        if ($account['next_action'] !== 'home') return response()->json(['data' => ['account' => $account]])->header('Cache-Control', 'no-store');
        $user = (int) $account['id'];
        $data = match ($request->route()->getName()) {
            'starttoo.notifications' => $notifications->listing($user, $request->query()),
            'starttoo.notifications.unread' => ['unread_count' => $notifications->unread($user)],
            'starttoo.notifications.read' => $notifications->read($user, (int) $notification),
            'starttoo.notifications.open' => $notifications->open($user, (int) $notification),
            'starttoo.preferences.get' => $preferences->get($user),
            'starttoo.preferences.set' => $preferences->change($user, $request->all()),
        };
        $sessions->authenticate($request->bearerToken() ?? '', true);
        return response()->json(['data' => [...$data, 'account' => $account]])->header('Cache-Control', 'no-store');
    }
}
