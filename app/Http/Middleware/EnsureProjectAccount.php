<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

class EnsureProjectAccount
{
    public function handle(Request $request, Closure $next)
    {
        $guard = Auth::guard('api');
        $account = $guard->user();
        $project = $request->attributes->get('project');

        abort_unless($account && $account->status === 'active'
            && (int) $account->project_id === (int) $project->id
            && (int) $guard->payload()->get('project_id') === (int) $project->id
            && $guard->payload()->get('project_code') === $project->code,
            403, '해당 프로젝트에 접근할 수 없습니다.');

        return $next($request);
    }
}
