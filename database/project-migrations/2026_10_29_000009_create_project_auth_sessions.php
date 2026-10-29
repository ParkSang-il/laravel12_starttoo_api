<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('project_auth_sessions', function (Blueprint $table) {
            $table->char('id', 64)->primary();
            $table->foreignId('account_id')->constrained('project_accounts')->cascadeOnDelete();
            $table->timestamp('last_seen_at')->index();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('project_auth_sessions');
    }
};
