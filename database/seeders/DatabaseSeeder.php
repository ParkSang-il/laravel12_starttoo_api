<?php

namespace Database\Seeders;

use App\Models\Project;
use App\Services\ProjectDatabase;
use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    public function run(): void
    {
        app(ProjectDatabase::class)->run('streetcat', function () {
            Project::firstOrCreate(['code' => 'streetcat'], ['name' => '스트릿캣']);
        });
    }
}
