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
