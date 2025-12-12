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
        Schema::create('portfolio_like_logs', function (Blueprint $table) {
            $table->id()->comment('기본 키');
            $table->unsignedBigInteger('portfolio_id')->comment('포트폴리오 ID (portfolios.id)');
            $table->unsignedBigInteger('user_id')->comment('사용자 ID (users.id)');
            $table->string('action', 10)->comment('액션 (like:좋아요, unlike:좋아요취소)');
            $table->timestamp('created_at')->useCurrent()->comment('생성일시');

            // 인덱스
            $table->index('portfolio_id', 'idx_portfolio_id');
            $table->index('user_id', 'idx_user_id');
            $table->index('created_at', 'idx_created_at');
        });

        // 테이블 코멘트 추가
        DB::statement("ALTER TABLE portfolio_like_logs COMMENT = '포트폴리오 좋아요 이력 테이블 (로그)'");
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('portfolio_like_logs');
    }
};

