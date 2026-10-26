<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('group_places', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->unsignedBigInteger('project_id');
            $table->uuid('group_id');
            $table->string('name', 80);
            $table->string('note', 500)->default('');
            $table->decimal('latitude', 10, 7)->nullable();
            $table->decimal('longitude', 10, 7)->nullable();
            $table->unsignedSmallInteger('interval_hours')->default(24);
            $table->unsignedInteger('version')->default(1);
            $table->unsignedBigInteger('created_by');
            $table->unsignedBigInteger('updated_by');
            $table->timestamps();
            $table->softDeletes();
            $table->unique(['group_id', 'id']);
            $table->foreign(['project_id', 'group_id'])->references(['project_id', 'id'])
                ->on('care_groups')->restrictOnDelete();
            $table->foreign(['project_id', 'created_by'])->references(['project_id', 'id'])
                ->on('project_accounts')->restrictOnDelete();
            $table->foreign(['project_id', 'updated_by'])->references(['project_id', 'id'])
                ->on('project_accounts')->restrictOnDelete();
            $table->index(['group_id', 'deleted_at', 'id']);
        });

        Schema::create('group_place_permissions', function (Blueprint $table) {
            $table->unsignedBigInteger('project_id');
            $table->uuid('group_id');
            $table->uuid('place_id');
            $table->unsignedBigInteger('account_id');
            $table->string('access', 10);
            $table->unsignedBigInteger('granted_by');
            $table->timestamps();
            $table->primary(['place_id', 'account_id']);
            $table->foreign(['project_id', 'group_id'])->references(['project_id', 'id'])
                ->on('care_groups')->restrictOnDelete();
            $table->foreign(['group_id', 'place_id'])->references(['group_id', 'id'])
                ->on('group_places')->restrictOnDelete();
            $table->foreign(['group_id', 'account_id'])->references(['group_id', 'account_id'])
                ->on('group_memberships')->restrictOnDelete();
            $table->foreign(['project_id', 'granted_by'])->references(['project_id', 'id'])
                ->on('project_accounts')->restrictOnDelete();
            $table->index(['group_id', 'account_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('group_place_permissions');
        Schema::dropIfExists('group_places');
    }
};
