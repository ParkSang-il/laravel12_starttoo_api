<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Phase 5 커뮤니티(5-04~5-07). 질문 · 후기 · 자유 글 + 좋아요 + 댓글.
 * 기존 일반 게시물(starttoo_posts)과는 별도다(제목 · 카테고리 · 작가 태그).
 */
return new class extends Migration {
    public function up(): void
    {
        Schema::create('starttoo_community_posts', function (Blueprint $t) {
            $t->id();
            $t->foreignId('user_id')->constrained('starttoo_users')->cascadeOnDelete();
            $t->uuid('request_key')->comment('중복 등록 방지');
            $t->string('category', 20)->comment('question 질문 · review 후기 · free 자유');
            $t->string('title', 80);
            $t->text('content')->nullable()->comment('최대 5000자');
            $t->json('images')->nullable()->comment('사진 경로(최대 10장)');
            $t->foreignId('artist_id')->nullable()->comment('후기 글의 작가 태그')->constrained('starttoo_users')->nullOnDelete();
            $t->unsignedInteger('like_count')->default(0);
            $t->unsignedInteger('comment_count')->default(0);
            $t->unsignedInteger('view_count')->default(0);
            $t->boolean('moderation_hidden')->default(false);
            $t->timestamps();
            $t->unique(['user_id', 'request_key']);
            $t->index(['category', 'moderation_hidden', 'id']);
            $t->index(['moderation_hidden', 'created_at']);
        });

        Schema::create('starttoo_community_likes', function (Blueprint $t) {
            $t->id();
            $t->foreignId('post_id')->constrained('starttoo_community_posts')->cascadeOnDelete();
            $t->foreignId('user_id')->constrained('starttoo_users')->cascadeOnDelete();
            $t->timestamp('created_at')->nullable();
            $t->unique(['post_id', 'user_id']);
        });

        Schema::create('starttoo_community_comments', function (Blueprint $t) {
            $t->id();
            $t->foreignId('post_id')->constrained('starttoo_community_posts')->cascadeOnDelete();
            $t->foreignId('user_id')->constrained('starttoo_users')->cascadeOnDelete();
            $t->uuid('request_key')->comment('중복 등록 방지');
            $t->text('content')->comment('최대 1000자');
            $t->boolean('moderation_hidden')->default(false);
            $t->timestamps();
            $t->unique(['user_id', 'request_key']);
            $t->index(['post_id', 'id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('starttoo_community_comments');
        Schema::dropIfExists('starttoo_community_likes');
        Schema::dropIfExists('starttoo_community_posts');
    }
};
