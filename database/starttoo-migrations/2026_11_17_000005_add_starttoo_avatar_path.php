<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('starttoo_users', function (Blueprint $table) {
            $table->string('avatar_path', 255)->nullable()->comment('프로필 사진 S3 비공개 객체 경로');
        });
    }

    public function down(): void
    {
        Schema::table('starttoo_users', function (Blueprint $table) {
            $table->dropColumn('avatar_path');
        });
    }
};
