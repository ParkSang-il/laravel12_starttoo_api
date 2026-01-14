<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('users', function (Blueprint $table) {
            $table->id()->comment('기본 키');
            $table->tinyInteger('user_type')->unsigned()->nullable(false)->comment('회원 유형 (1:일반 회원, 2:사업자)');
            $table->string('phone', 20)->nullable(false)->unique('users_phone_unique')->comment('휴대폰 번호 (인증 필수)');
            $table->timestamp('phone_verified_at')->nullable()->comment('휴대폰 인증일시');
            $table->string('username', 50)->nullable(false)->unique('users_username_unique')->comment('닉네임/사용자명 (필수)');
            $table->string('profile_image', 255)->nullable()->comment('프로필 이미지 URL');

            $table->timestamps();
            $table->softDeletes();

            // 개별 인덱스
            $table->index('user_type', 'idx_users_user_type');
            $table->index('phone', 'idx_users_phone');
            $table->index('username', 'idx_users_username');
            $table->index('created_at', 'idx_users_created_at');

            // 결합 인덱스
            $table->index(['phone', 'username', 'user_type'], 'idx_users_phone_username_user_type');
        });

        // 테이블 코멘트 추가
        DB::statement("ALTER TABLE users COMMENT = '사용자 테이블 (정지 기능 포함)'");
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('users');
    }
};
