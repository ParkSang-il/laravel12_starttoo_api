<?php

namespace App\Http\Middleware;

use App\Models\Project;
use App\Services\ProjectDatabase;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

class ResolveProject
{
    public function handle(Request $request, Closure $next)
    {
        abort_if($request->hasAny(['project_id', 'project_code', 'database', 'connection']),
            422, '프로젝트는 URL 경로로만 지정하세요.');
        $code = (string) $request->route('projectCode');

        return app(ProjectDatabase::class)->run($code, function () use ($request, $next, $code) {
            // Avoid retaining an account fetched from a previous project's database.
            Auth::forgetGuards();
            try {
                $project = Project::where('code', $code)->where('is_active', true)->firstOrFail();
                $request->attributes->set('project', $project);

                return $next($request);
            } finally {
                Auth::forgetGuards();
                $request->attributes->remove('project');
            }
        });
    }
}
