<?php

namespace App\Providers;

use App\Services\ProjectDatabase;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->scoped(ProjectDatabase::class, fn () => new ProjectDatabase);
    }

    public function boot(): void
    {
        // IP limits span projects: changing project codes must not reset the quota.
        RateLimiter::for('phone-send', fn (Request $request) =>
            Limit::perMinute(5)->by('phone-send:'.$request->ip()));
        RateLimiter::for('phone-verify', fn (Request $request) =>
            Limit::perMinute(20)->by('phone-verify:'.$request->ip()));
        RateLimiter::for('project-api', fn (Request $request) =>
            Limit::perMinute(120)->by('account:'.$request->route('projectCode').':'.$request->user()->id));
    }
}
