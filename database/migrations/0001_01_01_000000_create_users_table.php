<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('users', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('email')->unique();
            $table->timestamp('email_verified_at')->nullable();
            $table->string('password')->nullable(); // 소셜 로그인 사용자는 비밀번호 없을 수 있음
            $table->string('username')->nullable()->unique();
            $table->string('profile_image')->nullable();
            $table->text('bio')->nullable();
            $table->boolean('is_verified')->default(false);
            
            // 소셜 로그인 필드
            $table->string('provider')->nullable()->comment('소셜 로그인 제공자 (google, kakao, instagram)');
            $table->string('provider_id')->nullable()->comment('소셜 로그인 제공자의 사용자 ID');
            $table->string('avatar')->nullable()->comment('소셜 로그인 프로필 이미지 URL');
            
            $table->timestamps();
            $table->softDeletes();
            
            // provider와 provider_id의 복합 인덱스
            $table->index(['provider', 'provider_id']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('users');
    }
};
