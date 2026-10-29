<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use App\Services\ProjectDatabase;

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

        if ($project->code === 'streetcat') {
            $now = now();
            $sessions = app(ProjectDatabase::class)->database()
                ->table('project_auth_sessions')
                ->where('id', hash('sha256', $guard->payload()->get('jti')))
                ->where('account_id', $account->id)
                ->where('last_seen_at', '>', $now->copy()->subDays(90));
            abort_unless((clone $sessions)->exists(), 401,
                '90일 동안 접속하지 않아 로그인이 만료됐습니다.');
            $sessions->update(['last_seen_at' => $now, 'updated_at' => $now]);
        }

        return $next($request);
    }
}
