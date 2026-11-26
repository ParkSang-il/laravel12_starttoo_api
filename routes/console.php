<?php

use App\Models\Project;
use App\Services\ProjectDatabase;
use Illuminate\Support\Facades\Artisan;

Artisan::command('project:migrate {code=streetcat} {--pretend} {--force}', function () {
    $connection = app(ProjectDatabase::class)->configuredConnection($this->argument('code'));

    return $this->call('migrate', [
        '--database' => $connection,
        '--path' => 'database/project-migrations',
        '--pretend' => $this->option('pretend'),
        '--force' => $this->option('force'),
    ]);
})->purpose('Migrate only the allowlisted project database, without legacy tables');

Artisan::command('project:create {code=streetcat} {name?}', function () {
    $code = $this->argument('code');

    return app(ProjectDatabase::class)->run($code, function () use ($code) {
        $name = $this->argument('name') ?: config('platform.projects.'.$code.'.name');
        $project = Project::firstOrCreate(['code' => $code], ['name' => $name]);
        $this->info($project->wasRecentlyCreated ? '프로젝트를 등록했습니다.' : '이미 등록된 프로젝트입니다.');

        return 0;
    });
})->purpose('Register a project inside its own previously migrated database');

Artisan::command('project:seed-nicknames {code=streetcat}', function () {
    app(ProjectDatabase::class)->run($this->argument('code'), function () {
        app(\Database\Seeders\NicknameWordSeeder::class)->run();
    });
    $this->info('닉네임 단어를 등록했습니다.');
    return 0;
})->purpose('Seed nickname words only in the allowlisted project database');

Artisan::command('project:purge-group-media {code=streetcat} {--limit=500}', function () {
    $limit = max(1, min(1000, (int) $this->option('limit')));
    $count = app(ProjectDatabase::class)->run($this->argument('code'), fn () => app(\App\Services\GroupMediaService::class)->purge($limit));
    $this->info('Purged media count: '.$count);
    return 0;
});
\Illuminate\Support\Facades\Schedule::command('project:purge-group-media streetcat')->hourly()->withoutOverlapping();

// 갱신 응답 복구 기한이 지난 암호문과 만료된 세션을 정리한다.
Artisan::command('starttoo:purge-auth', function () {
    app(ProjectDatabase::class)->run('starttoo', function () {
        $sessions = app(ProjectDatabase::class)->database()->table('starttoo_auth_sessions');
        (clone $sessions)->where('retry_until', '<=', now())->update(['previous_result' => null]);
        (clone $sessions)->where(function ($query) {
            $query->where('absolute_expires_at', '<=', now())->orWhere('idle_expires_at', '<=', now());
        })->delete();
    });
    $this->info('만료된 STARTTOO 인증 정보를 정리했습니다.');
    return 0;
});
\Illuminate\Support\Facades\Schedule::command('starttoo:purge-auth')->everyMinute()->withoutOverlapping();
require __DIR__.'/starttoo_console.php';
require __DIR__.'/starttoo_push_console.php';
require __DIR__.'/starttoo_admin_console.php';

Artisan::command('starttoo:push-send', function () {
    app(ProjectDatabase::class)->run('starttoo', fn () => app(\App\Services\Starttoo\PushSender::class)->recover());
    return 0;
});
\Illuminate\Support\Facades\Schedule::command('starttoo:push-send')->everyMinute()->withoutOverlapping(10);
