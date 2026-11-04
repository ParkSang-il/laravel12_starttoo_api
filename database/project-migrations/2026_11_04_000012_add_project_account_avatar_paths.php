<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('project_accounts', function (Blueprint $table) {
            $table->string('avatar_disk', 32)->nullable();
            $table->string('avatar_path', 1024)->nullable();
            $table->string('avatar_thumbnail_path', 1024)->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('project_accounts', fn (Blueprint $table) => $table->dropColumn([
            'avatar_disk', 'avatar_path', 'avatar_thumbnail_path',
        ]));
    }
};
