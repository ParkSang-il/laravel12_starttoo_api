<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('group_animals', function (Blueprint $table) {
            $table->string('tnr_stage', 20)->default('needed');
            $table->json('tnr_dates')->nullable();
            $table->date('life_status_at')->nullable();
        });
        // Preserve existing booleans; unknown historical dates remain unknown.
        DB::table('group_animals')->where('neutered', true)->update(['tnr_stage' => 'done']);
    }

    public function down(): void
    {
        Schema::table('group_animals', function (Blueprint $table) {
            $table->dropColumn(['tnr_stage', 'tnr_dates', 'life_status_at']);
        });
    }
};
