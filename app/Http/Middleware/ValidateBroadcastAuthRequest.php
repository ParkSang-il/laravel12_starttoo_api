<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class ValidateBroadcastAuthRequest
{
    public function handle(Request $request, Closure $next): Response
    {
        $request->validate([
            'socket_id' => ['required', 'string', 'max:64', 'regex:/\A\d+\.\d+\z/'],
            'channel_name' => ['required', 'string', 'max:200', 'regex:/\Aprivate-[-a-zA-Z0-9_=@,.;]+\z/'],
        ]);

        return $next($request);
    }
}
