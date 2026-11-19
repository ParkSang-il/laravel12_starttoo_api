<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('starttoo_post_saves', function (Blueprint $table) {
            $table->id()->comment('일반 게시물 저장 번호');
            $table->foreignUuid('post_id')->comment('저장한 게시물 UUID')->constrained('starttoo_posts')->cascadeOnDelete();
            $table->foreignId('user_id')->comment('저장 회원 번호')->constrained('starttoo_users')->cascadeOnDelete();
            $table->timestamp('created_at')->useCurrent()->comment('최근 저장 시각');
            $table->unique(['post_id', 'user_id']);
            $table->index(['user_id', 'created_at', 'post_id']);
        });
        Schema::create('starttoo_hidden_posts', function (Blueprint $table) {
            $table->id()->comment('개인 피드 게시물 숨김 번호');
            $table->foreignUuid('post_id')->comment('숨긴 게시물 UUID')->constrained('starttoo_posts')->cascadeOnDelete();
            $table->foreignId('user_id')->comment('숨김 요청 회원 번호')->constrained('starttoo_users')->cascadeOnDelete();
            $table->timestamp('created_at')->useCurrent()->comment('숨김 시각');
            $table->unique(['user_id', 'post_id']);
        });
        Schema::create('starttoo_post_deletions', function (Blueprint $table) {
            $table->id()->comment('게시물 삭제 재시도 이력 번호');
            $table->foreignId('user_id')->comment('삭제한 작성 회원 번호')->constrained('starttoo_users')->cascadeOnDelete();
            $table->uuid('post_id')->comment('삭제된 게시물 UUID: 삭제 후 재시도용으로 외래키 제외');
            $table->uuid('request_key')->comment('회원별 중복 삭제 방지 키');
            $table->json('paths')->comment('커밋 후 정리할 사진 경로: 재시도 시 다시 정리');
            $table->timestamp('created_at')->useCurrent()->comment('삭제 처리 시각');
            $table->unique(['user_id', 'request_key']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('starttoo_post_deletions');
        Schema::dropIfExists('starttoo_hidden_posts');
        Schema::dropIfExists('starttoo_post_saves');
    }
};
