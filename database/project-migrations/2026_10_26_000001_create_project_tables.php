<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('projects', function (Blueprint $table) {
            $table->id();
            $table->string('code', 50)->unique();
            $table->string('name', 100);
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });

        Schema::create('project_accounts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('project_id')->constrained('projects')->restrictOnDelete();
            $table->string('phone', 20);
            $table->string('display_name', 50)->nullable();
            $table->string('status', 20)->default('active');
            $table->timestamp('phone_verified_at');
            $table->timestamps();
            $table->unique(['project_id', 'phone']);
            // Referencing tables can enforce both account and project identity.
            $table->unique(['project_id', 'id']);
        });

        Schema::create('project_phone_challenges', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignId('project_id')->constrained('projects')->restrictOnDelete();
            $table->string('phone', 20);
            $table->string('code_hash');
            $table->unsignedTinyInteger('attempts')->default(0);
            $table->timestamp('expires_at');
            $table->timestamp('consumed_at')->nullable();
            $table->timestamps();
            $table->index(['project_id', 'phone', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('project_phone_challenges');
        Schema::dropIfExists('project_accounts');
        Schema::dropIfExists('projects');
    }
};
