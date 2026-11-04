<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('project_accounts', function (Blueprint $table) {
            $table->string('avatar_url', 2048)->nullable();
            $table->string('avatar_thumbnail_url', 2048)->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('project_accounts', function (Blueprint $table) {
            $table->dropColumn(['avatar_url', 'avatar_thumbnail_url']);
        });
    }
};
