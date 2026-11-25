<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Phase 5 인증 리뷰. 시술 완료(completed) 예약의 고객만, 예약당 한 건.
 * 평점 · 태그 · 내용 · 사진 · 작가 답변 · 도움이 됐어요.
 */
return new class extends Migration {
    public function up(): void
    {
        Schema::create('starttoo_reviews', function (Blueprint $t) {
            $t->id();
            $t->foreignId('booking_id')->unique()->comment('시술 완료 예약(예약당 한 건)');
            $t->foreignId('customer_id')->constrained('starttoo_users')->cascadeOnDelete();
            $t->foreignId('artist_id')->constrained('starttoo_users')->cascadeOnDelete();
            $t->uuid('request_key')->comment('중복 등록 방지');
            $t->unsignedTinyInteger('rating')->comment('1~5');
            $t->json('tags')->nullable()->comment('고른 태그');
            $t->text('content')->comment('20~2000자');
            $t->json('images')->nullable()->comment('사진 경로(최대 5장)');
            $t->string('work', 120)->nullable()->comment('작성 시점 작업 요약: 블랙워크 뱀 · 팔뚝');
            $t->text('reply')->nullable()->comment('작가 답변');
            $t->timestamp('replied_at')->nullable();
            $t->unsignedInteger('helpful_count')->default(0);
            $t->boolean('moderation_hidden')->default(false);
            $t->timestamps();
            $t->unique(['customer_id', 'request_key']);
            $t->index(['artist_id', 'moderation_hidden', 'created_at']);
            $t->index(['customer_id', 'created_at']);
        });

        Schema::create('starttoo_review_helpfuls', function (Blueprint $t) {
            $t->id();
            $t->foreignId('review_id')->constrained('starttoo_reviews')->cascadeOnDelete();
            $t->foreignId('user_id')->constrained('starttoo_users')->cascadeOnDelete();
            $t->timestamp('created_at')->nullable();
            $t->unique(['review_id', 'user_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('starttoo_review_helpfuls');
        Schema::dropIfExists('starttoo_reviews');
    }
};
