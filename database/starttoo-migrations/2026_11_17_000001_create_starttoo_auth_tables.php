<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('starttoo_users', function (Blueprint $table) {
            $table->id();
            $table->string('status', 20)->default('active');
            $table->string('display_name', 100)->nullable();
            $table->string('terms_version', 50)->nullable();
            $table->timestamp('profile_completed_at')->nullable();
            $table->timestamps();
        });
        Schema::create('starttoo_auth_identities', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained('starttoo_users')->cascadeOnDelete();
            $table->string('provider', 20);
            $table->string('subject', 255);
            $table->timestamps();
            $table->unique(['provider', 'subject']);
        });
        Schema::create('starttoo_auth_sessions', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignId('user_id')->constrained('starttoo_users')->cascadeOnDelete();
            $table->char('access_hash', 64)->unique();
            $table->char('refresh_hash', 64)->unique();
            $table->timestamp('access_expires_at');
            $table->timestamp('idle_expires_at');
            $table->timestamp('absolute_expires_at');
            $table->char('previous_refresh_hash', 64)->nullable()->index();
            $table->char('previous_request_hash', 64)->nullable();
            $table->text('previous_result')->nullable();
            $table->timestamp('retry_until')->nullable();
            $table->timestamp('revoked_at')->nullable();
            $table->timestamps();
        });
        Schema::create('starttoo_used_refresh_tokens', function (Blueprint $table) {
            $table->char('token_hash', 64)->primary();
            $table->foreignUuid('session_id')->constrained('starttoo_auth_sessions')->cascadeOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('starttoo_used_refresh_tokens');
        Schema::dropIfExists('starttoo_auth_sessions');
        Schema::dropIfExists('starttoo_auth_identities');
        Schema::dropIfExists('starttoo_users');
    }
};
