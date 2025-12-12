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
        Schema::create('portfolio_likes', function (Blueprint $table) {
            $table->id()->comment('기본 키');
            $table->unsignedBigInteger('portfolio_id')->comment('포트폴리오 ID (portfolios.id)');
            $table->unsignedBigInteger('user_id')->comment('사용자 ID (users.id)');
            $table->timestamp('created_at')->useCurrent()->comment('생성일시');

            // 인덱스
            $table->unique(['portfolio_id', 'user_id'], 'uq_portfolio_user');
        });

        // 테이블 코멘트 추가
        DB::statement("ALTER TABLE portfolio_likes COMMENT = '포트폴리오 좋아요 테이블'");
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('portfolio_likes');
    }
};

