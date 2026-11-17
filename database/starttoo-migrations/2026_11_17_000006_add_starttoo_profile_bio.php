<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('starttoo_users', function (Blueprint $table) {
            $table->string('bio', 150)->nullable()->comment('회원 프로필 소개: 최대 150자');
        });
    }

    public function down(): void
    {
        Schema::table('starttoo_users', function (Blueprint $table) {
            $table->dropColumn('bio');
        });
    }
};
