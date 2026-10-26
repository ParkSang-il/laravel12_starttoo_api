<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('group_animals', function (Blueprint $table) {
            $table->unsignedBigInteger('source_account_id')->nullable();
            $table->string('source_local_id', 80)->nullable();
            $table->unique(['group_id', 'source_account_id', 'source_local_id'], 'group_animals_import_source_unique');
            $table->foreign(['project_id', 'source_account_id'], 'group_animals_source_account_fk')
                ->references(['project_id', 'id'])->on('project_accounts')->restrictOnDelete();
        });
    }
    public function down(): void
    {
        Schema::table('group_animals', function (Blueprint $table) {
            $table->dropForeign('group_animals_source_account_fk');
            $table->dropUnique('group_animals_import_source_unique');
            $table->dropColumn(['source_account_id', 'source_local_id']);
        });
    }
};
