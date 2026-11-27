<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * 홈 추천 피드(docs/feed-ranking.md): 앱이 보내는 카드 노출 · 체류 · 열기 기록과 그 집계.
 * - feed_events: 원본(90일 보관, 탈퇴하면 삭제)
 * - feed_stats: 게시물별 누적(받을 때 바로 더한다)
 * - feed_seen: 회원이 이미 본 카드(반응 없이 여러 번 본 카드는 뒤로 미룬다, 30일 보관)
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('starttoo_feed_events', function (Blueprint $t) {
            $t->id();
            $t->foreignId('user_id')->comment('본 회원')->constrained('starttoo_users')->cascadeOnDelete();
            $t->string('target_type', 10)->comment('artwork 작품 · 도안 / post 게시물');
            $t->uuid('target_id');
            $t->string('event', 10)->comment('view 화면에 보임(ms 체류) / open 상세 열기');
            $t->unsignedInteger('ms')->default(0)->comment('화면에 절반 이상 보인 시간(최대 60초)');
            $t->unsignedSmallInteger('position')->nullable()->comment('피드 안 순서(0부터)');
            $t->timestamp('created_at')->useCurrent();
            $t->index(['user_id', 'created_at']);
            $t->index('created_at');
        });
        Schema::create('starttoo_feed_stats', function (Blueprint $t) {
            $t->string('target_type', 10);
            $t->uuid('target_id');
            $t->unsignedInteger('views')->default(0)->comment('1초 넘게 보인 횟수(같은 회원은 30분에 1번)');
            $t->unsignedBigInteger('view_ms')->default(0)->comment('체류 시간 합');
            $t->unsignedInteger('opens')->default(0)->comment('상세 열기 수');
            $t->timestamp('updated_at')->nullable();
            $t->primary(['target_type', 'target_id']);
        });
        Schema::create('starttoo_feed_seen', function (Blueprint $t) {
            $t->foreignId('user_id')->constrained('starttoo_users')->cascadeOnDelete();
            $t->string('target_type', 10);
            $t->uuid('target_id');
            $t->unsignedSmallInteger('views')->default(0)->comment('본 횟수(30분에 1번)');
            $t->timestamp('last_at')->comment('마지막으로 본 시각');
            $t->primary(['user_id', 'target_type', 'target_id']);
            $t->index('last_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('starttoo_feed_seen');
        Schema::dropIfExists('starttoo_feed_stats');
        Schema::dropIfExists('starttoo_feed_events');
    }
};
