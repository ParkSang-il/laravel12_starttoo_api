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
        Schema::create('artist_profiles', function (Blueprint $table) {
            $table->id()->comment('기본 키');
            $table->unsignedBigInteger('user_id')->comment('사용자 ID (users.id)');
            $table->string('cover_image', 255)->nullable()->comment('커버 이미지 URL');
            $table->string('artist_name', 100)->nullable()->comment('아티스트명');
            $table->string('email', 100)->nullable()->comment('이메일');
            $table->string('instagram', 100)->nullable()->comment('인스타그램 계정');
            $table->string('website', 255)->nullable()->comment('웹사이트 URL');
            $table->string('studio_address', 255)->nullable()->comment('스튜디오 주소');
            $table->text('bio')->nullable()->comment('샵 안내 메세지');
            $table->timestamps();

            // 인덱스
            $table->unique('user_id', 'uk_user_id');
            $table->index('user_id', 'idx_user_id');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('artist_profiles');
    }
};

