<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('starttoo_users', function (Blueprint $table) {
            $table->string('nickname', 20)->nullable()->unique()->comment('프로필 닉네임: 영문 소문자로 정규화, 중복 불가');
            $table->json('interest_styles')->nullable()->comment('관심 타투 스타일 코드 목록: 최대 5개');
            $table->string('interest_region', 30)->nullable()->comment('관심 지역 코드: 전국 또는 시도 단위 1개');
        });
    }

    public function down(): void
    {
        Schema::table('starttoo_users', function (Blueprint $table) {
            $table->dropUnique(['nickname']);
            $table->dropColumn(['nickname', 'interest_styles', 'interest_region']);
        });
    }
};
