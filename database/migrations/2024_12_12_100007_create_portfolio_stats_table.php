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
        Schema::create('portfolio_stats', function (Blueprint $table) {
            $table->unsignedBigInteger('portfolio_id')->primary()->comment('포트폴리오 ID (portfolios.id)');
            $table->integer('total_views')->default(0)->comment('누적 조회수');
            $table->integer('total_likes')->default(0)->comment('누적 좋아요 수');
            $table->integer('total_shares')->default(0)->comment('누적 공유 수');
            $table->integer('total_comments')->default(0)->comment('누적 댓글 수');
            $table->integer('recent_views')->default(0)->comment('최근 기간(예:7일) 조회수');
            $table->integer('recent_likes')->default(0)->comment('최근 기간(예:7일) 좋아요 수');
            $table->integer('recent_shares')->default(0)->comment('최근 기간(예:7일) 공유 수');
            $table->integer('recent_comments')->default(0)->comment('최근 기간(예:7일) 댓글 수');
            $table->dateTime('first_published_at')->comment('포트폴리오 최초 공개 일시');
            $table->dateTime('last_activity_at')->nullable()->comment('마지막 상호작용(조회/좋아요 등) 발생 일시');
            $table->timestamp('updated_at')->useCurrent()->useCurrentOnUpdate()->comment('통계 갱신 일시');
        });

        // 테이블 코멘트 추가
        DB::statement("ALTER TABLE portfolio_stats COMMENT = '포트폴리오 통계/인기 정보 테이블'");
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('portfolio_stats');
    }
};

