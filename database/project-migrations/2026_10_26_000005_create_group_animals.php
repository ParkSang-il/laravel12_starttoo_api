<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('group_animals', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->unsignedBigInteger('project_id');
            $table->uuid('group_id');
            $table->string('name', 80);
            $table->string('coat', 80)->default('');
            $table->string('sex', 10)->default('unknown');
            $table->boolean('neutered')->default(false);
            $table->string('life_status', 20)->default('active');
            $table->string('note', 500)->default('');
            $table->unsignedInteger('version')->default(1);
            $table->unsignedBigInteger('created_by');
            $table->unsignedBigInteger('updated_by');
            $table->timestamps();
            $table->unique(['group_id', 'id']);
            $table->foreign(['project_id', 'group_id'])->references(['project_id', 'id'])->on('care_groups')->restrictOnDelete();
            $table->foreign(['project_id', 'created_by'])->references(['project_id', 'id'])->on('project_accounts')->restrictOnDelete();
            $table->foreign(['project_id', 'updated_by'])->references(['project_id', 'id'])->on('project_accounts')->restrictOnDelete();
        });
        Schema::create('group_animal_places', function (Blueprint $table) {
            $table->uuid('group_id');
            $table->uuid('animal_id');
            $table->uuid('place_id');
            $table->timestamp('created_at');
            $table->primary(['animal_id', 'place_id']);
            $table->foreign(['group_id', 'animal_id'])->references(['group_id', 'id'])->on('group_animals')->restrictOnDelete();
            $table->foreign(['group_id', 'place_id'])->references(['group_id', 'id'])->on('group_places')->restrictOnDelete();
            $table->index(['place_id', 'animal_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('group_animal_places');
        Schema::dropIfExists('group_animals');
    }
};
