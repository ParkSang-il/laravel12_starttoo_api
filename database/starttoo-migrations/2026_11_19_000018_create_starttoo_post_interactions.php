<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('starttoo_post_likes', function (Blueprint $table) {
            $table->id()->comment('일반 게시물 좋아요 번호');
            $table->foreignUuid('post_id')->comment('게시물 UUID')->constrained('starttoo_posts')->cascadeOnDelete();
            $table->foreignId('user_id')->comment('좋아요 회원 번호')->constrained('starttoo_users')->cascadeOnDelete();
            $table->timestamp('created_at')->useCurrent()->comment('좋아요 시각');
            $table->unique(['post_id', 'user_id']);
        });
        Schema::create('starttoo_post_like_requests', function (Blueprint $table) {
            $table->id()->comment('게시물 좋아요 재시도 이력 번호');
            $table->foreignId('user_id')->comment('요청 회원 번호')->constrained('starttoo_users')->cascadeOnDelete();
            $table->uuid('request_key')->comment('회원별 중복 변경 방지 키');
            $table->char('payload_hash', 64)->comment('요청 내용 SHA-256');
            $table->json('response_payload')->comment('응답 유실 후 재시도 결과');
            $table->timestamp('created_at')->useCurrent()->comment('처리 시각');
            $table->unique(['user_id', 'request_key']);
        });
        Schema::create('starttoo_post_comments', function (Blueprint $table) {
            $table->id()->comment('게시물 댓글 번호');
            $table->foreignUuid('post_id')->comment('댓글이 속한 게시물 UUID')->constrained('starttoo_posts')->cascadeOnDelete();
            $table->foreignId('user_id')->comment('작성 회원 번호')->constrained('starttoo_users')->cascadeOnDelete();
            $table->foreignId('parent_id')->nullable()->comment('원댓글 번호: 대댓글까지만 허용')->constrained('starttoo_post_comments')->cascadeOnDelete();
            $table->text('content')->comment('댓글 본문: 최대 1000자');
            $table->json('mentions')->comment('선택한 멘션 회원 번호와 접수 시 닉네임');
            $table->unsignedInteger('like_count')->default(0)->comment('댓글 좋아요 수: 댓글 행 잠금 안에서 갱신');
            $table->boolean('moderation_hidden')->default(false)->comment('운영자 숨김 여부');
            $table->timestamp('deleted_at')->nullable()->comment('작성자 삭제 시각: 대댓글 문맥을 보존');
            $table->timestamps();
            $table->index(['post_id', 'parent_id', 'id']);
            $table->index(['post_id', 'parent_id', 'like_count', 'id'], 'starttoo_post_comments_popular_idx');
        });
        Schema::create('starttoo_post_comment_likes', function (Blueprint $table) {
            $table->id()->comment('댓글 좋아요 번호');
            $table->foreignId('comment_id')->comment('댓글 번호')->constrained('starttoo_post_comments')->cascadeOnDelete();
            $table->foreignId('user_id')->comment('좋아요 회원 번호')->constrained('starttoo_users')->cascadeOnDelete();
            $table->timestamp('created_at')->useCurrent()->comment('좋아요 시각');
            $table->unique(['comment_id', 'user_id']);
        });
        Schema::create('starttoo_post_comment_requests', function (Blueprint $table) {
            $table->id()->comment('댓글 변경 재시도 이력 번호');
            $table->foreignId('user_id')->comment('요청 회원 번호')->constrained('starttoo_users')->cascadeOnDelete();
            $table->uuid('request_key')->comment('회원별 중복 변경 방지 키');
            $table->char('payload_hash', 64)->comment('요청 내용 SHA-256');
            $table->json('response_payload')->comment('응답 유실 후 재시도 결과');
            $table->timestamp('created_at')->useCurrent()->comment('처리 시각');
            $table->unique(['user_id', 'request_key']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('starttoo_post_comment_requests');
        Schema::dropIfExists('starttoo_post_comment_likes');
        Schema::dropIfExists('starttoo_post_comments');
        Schema::dropIfExists('starttoo_post_like_requests');
        Schema::dropIfExists('starttoo_post_likes');
    }
};
