<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('care_groups', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignId('project_id')->constrained('projects')->restrictOnDelete();
            $table->unsignedBigInteger('created_by');
            $table->string('name', 80);
            $table->string('description', 500)->default('');
            $table->char('region_code', 10);
            $table->string('region_label', 80);
            $table->boolean('is_discoverable')->default(false);
            $table->boolean('accepting_requests')->default(false);
            $table->timestamps();
            $table->unique(['project_id', 'id']);
            $table->foreign(['project_id', 'created_by'])->references(['project_id', 'id'])
                ->on('project_accounts')->restrictOnDelete();
            $table->index(['project_id', 'region_code', 'is_discoverable']);
        });

        Schema::create('group_memberships', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('project_id');
            $table->uuid('group_id');
            $table->unsignedBigInteger('account_id');
            $table->string('role', 20);
            $table->string('status', 20)->default('active');
            $table->timestamps();
            $table->unique(['group_id', 'account_id']);
            $table->foreign(['project_id', 'group_id'])->references(['project_id', 'id'])
                ->on('care_groups')->restrictOnDelete();
            $table->foreign(['project_id', 'account_id'])->references(['project_id', 'id'])
                ->on('project_accounts')->restrictOnDelete();
        });

        Schema::create('group_invitations', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->unsignedBigInteger('project_id');
            $table->uuid('group_id');
            $table->unsignedBigInteger('created_by');
            $table->char('token_hash', 64)->unique();
            $table->char('phone_hash', 64)->nullable();
            $table->unsignedSmallInteger('max_uses')->default(1);
            $table->unsignedSmallInteger('used_count')->default(0);
            $table->timestamp('expires_at');
            $table->timestamp('revoked_at')->nullable();
            $table->timestamps();
            $table->unique(['group_id', 'id']);
            $table->foreign(['project_id', 'group_id'])->references(['project_id', 'id'])
                ->on('care_groups')->restrictOnDelete();
            $table->foreign(['project_id', 'created_by'])->references(['project_id', 'id'])
                ->on('project_accounts')->restrictOnDelete();
        });

        Schema::create('group_join_requests', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('project_id');
            $table->uuid('group_id');
            $table->unsignedBigInteger('account_id');
            $table->uuid('invitation_id')->nullable();
            $table->string('message', 500);
            $table->string('availability', 200)->default('');
            $table->string('experience', 300)->default('');
            $table->string('status', 20)->default('pending');
            $table->unsignedInteger('version')->default(1);
            $table->unsignedBigInteger('reviewed_by')->nullable();
            $table->string('review_note', 300)->nullable();
            $table->timestamp('submitted_at');
            $table->timestamp('reviewed_at')->nullable();
            $table->timestamps();
            $table->unique(['group_id', 'account_id']);
            $table->foreign(['project_id', 'group_id'])->references(['project_id', 'id'])
                ->on('care_groups')->restrictOnDelete();
            $table->foreign(['project_id', 'account_id'])->references(['project_id', 'id'])
                ->on('project_accounts')->restrictOnDelete();
            $table->foreign(['group_id', 'invitation_id'])->references(['group_id', 'id'])
                ->on('group_invitations')->restrictOnDelete();
            $table->foreign(['project_id', 'reviewed_by'])->references(['project_id', 'id'])
                ->on('project_accounts')->restrictOnDelete();
            $table->index(['group_id', 'status', 'submitted_at']);
        });

        Schema::create('group_audit_events', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('project_id');
            $table->uuid('group_id');
            $table->unsignedBigInteger('actor_id');
            $table->string('event', 40);
            $table->json('metadata');
            $table->timestamp('created_at');
            $table->foreign(['project_id', 'group_id'])->references(['project_id', 'id'])
                ->on('care_groups')->restrictOnDelete();
            $table->foreign(['project_id', 'actor_id'])->references(['project_id', 'id'])
                ->on('project_accounts')->restrictOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('group_audit_events');
        Schema::dropIfExists('group_join_requests');
        Schema::dropIfExists('group_invitations');
        Schema::dropIfExists('group_memberships');
        Schema::dropIfExists('care_groups');
    }
};
