<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('group_animals', function (Blueprint $table) {
            $table->string('age', 80)->default('');
        });
    }
    public function down(): void
    {
        Schema::table('group_animals', function (Blueprint $table) {
            $table->dropColumn('age');
        });
    }
};
