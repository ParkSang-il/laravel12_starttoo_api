<?php

namespace App\Http\Controllers;

use App\Services\Starttoo\AuthSessions;
use App\Services\Starttoo\TermsConsents;
use Illuminate\Http\Request;

class StarttooTermsController extends Controller
{
    public function index(Request $request, AuthSessions $sessions, TermsConsents $terms)
    {
        $token = $request->bearerToken() ?? '';
        $sessions->authenticate($token);
        $data = $terms->catalog();
        $data['account'] = $sessions->authenticate($token, true)['account'];
        return response()->json(['data' => $data])->header('Cache-Control', 'no-store');
    }

    public function store(Request $request, AuthSessions $sessions, TermsConsents $terms)
    {
        $token = $request->bearerToken() ?? '';
        $sessions->authenticate($token);
        $input = $request->validate([
            'version' => ['required', 'string', 'max:50'],
            'revision' => ['required', 'string', 'regex:/^[a-f0-9]{64}$/D'],
            'consents' => ['required', 'array'],
            'request_key' => ['required', 'uuid'],
        ]);
        return response()->json(['data' => $terms->accept($token, $input, $sessions)])
            ->header('Cache-Control', 'no-store');
    }
}
