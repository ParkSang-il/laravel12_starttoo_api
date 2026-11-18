<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('starttoo_posts', function (Blueprint $table) {
            $table->uuid('id')->primary()->comment('일반 게시물 UUID: 작품과 별도 관리');
            $table->foreignId('user_id')->comment('작성 회원 번호')->constrained('starttoo_users')->cascadeOnDelete();
            $table->uuid('request_key')->comment('회원별 중복 작성 방지 UUID');
            $table->char('payload_hash', 64)->comment('본문과 사진 순서의 SHA-256');
            $table->text('content')->comment('게시물 본문: 최대 2000자');
            $table->json('tags')->comment('본문에서 추출한 해시태그');
            $table->unsignedInteger('version')->default(1)->comment('편집 충돌 확인 버전');
            $table->boolean('moderation_hidden')->default(false)->comment('운영자 공개 제한 여부');
            $table->timestamp('created_at')->useCurrent()->comment('최초 게시 시각');
            $table->timestamp('updated_at')->useCurrent()->comment('최근 수정 시각');
            $table->unique(['user_id', 'request_key']);
            $table->index(['user_id', 'created_at', 'id']);
        });
        Schema::create('starttoo_post_images', function (Blueprint $table) {
            $table->id()->comment('게시물 사진 번호');
            $table->foreignUuid('post_id')->comment('일반 게시물 UUID')->constrained('starttoo_posts')->cascadeOnDelete();
            $table->unsignedTinyInteger('position')->comment('사진 표시 순서: 0부터');
            $table->string('path', 255)->comment('공통 업로드로 저장한 비공개 S3 경로');
            $table->unique(['post_id', 'position']);
        });
        Schema::create('starttoo_post_edits', function (Blueprint $table) {
            $table->id()->comment('게시물 편집 요청 번호');
            $table->foreignUuid('post_id')->comment('수정한 게시물 UUID')->constrained('starttoo_posts')->cascadeOnDelete();
            $table->foreignId('user_id')->comment('편집한 회원 번호')->constrained('starttoo_users')->cascadeOnDelete();
            $table->uuid('request_key')->comment('회원별 중복 편집 방지 UUID');
            $table->char('payload_hash', 64)->comment('게시물·버전·입력·사진 순서의 SHA-256');
            $table->timestamp('created_at')->useCurrent()->comment('편집 성공 시각');
            $table->unique(['user_id', 'request_key']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('starttoo_post_edits');
        Schema::dropIfExists('starttoo_post_images');
        Schema::dropIfExists('starttoo_posts');
    }
};
