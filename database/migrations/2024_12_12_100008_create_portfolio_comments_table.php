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
        Schema::create('portfolio_comments', function (Blueprint $table) {
            $table->id()->comment('기본 키');
            $table->unsignedBigInteger('portfolio_id')->nullable(false)->comment('포트폴리오 ID (portfolios.id)');
            $table->unsignedBigInteger('user_id')->nullable(false)->comment('작성자 ID (users.id)');
            $table->unsignedBigInteger('parent_id')->nullable()->comment('부모 댓글 ID (portfolio_comments.id, 대댓글인 경우)');
            $table->text('content')->nullable(false)->comment('댓글 내용');
            $table->integer('replies_count')->default(0)->nullable(false)->comment('대댓글 수');
            $table->boolean('is_deleted')->default(false)->nullable(false)->comment('삭제 여부');
            $table->timestamps();
            $table->softDeletes();

            // 인덱스
            $table->index('portfolio_id', 'idx_portfolio_id');
            $table->index('user_id', 'idx_user_id');
            $table->index('parent_id', 'idx_parent_id');
            $table->index(['portfolio_id', 'parent_id'], 'idx_portfolio_parent');
            $table->index(['portfolio_id', 'created_at'], 'idx_portfolio_created');
        });

        // 테이블 코멘트 추가
        DB::statement("ALTER TABLE portfolio_comments COMMENT = '포트폴리오 댓글 테이블'");
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('portfolio_comments');
    }
};

