<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('starttoo_follows', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained('starttoo_users')->cascadeOnDelete();
            $table->foreignId('target_user_id')->constrained('starttoo_users')->cascadeOnDelete();
            $table->timestamp('created_at')->useCurrent();
            $table->unique(['user_id', 'target_user_id']);
            $table->index(['target_user_id', 'id']);
            $table->index(['user_id', 'id']);
        });
        Schema::create('starttoo_follow_requests', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained('starttoo_users')->cascadeOnDelete();
            $table->uuid('request_key');
            $table->char('payload_hash', 64);
            $table->json('response_payload');
            $table->timestamp('created_at')->useCurrent();
            $table->unique(['user_id', 'request_key']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('starttoo_follow_requests');
        Schema::dropIfExists('starttoo_follows');
    }
};
