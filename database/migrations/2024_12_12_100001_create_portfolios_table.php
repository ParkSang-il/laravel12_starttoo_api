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
        Schema::create('portfolios', function (Blueprint $table) {
            $table->id()->comment('기본 키');
            $table->unsignedBigInteger('user_id')->nullable(false)->comment('아티스트 ID (users.id)');
            $table->string('title', 255)->nullable(false)->comment('제목');
            $table->text('description')->nullable()->comment('설명');
            $table->date('work_date')->nullable()->comment('작업 날짜');
            $table->decimal('price', 10, 2)->nullable()->comment('가격');
            $table->boolean('is_public')->default(true)->nullable(false)->comment('공개 여부');
            $table->boolean('is_sensitive')->default(false)->nullable(false)->comment('민감성 정보 포함 여부');
            $table->integer('views')->default(0)->nullable(false)->comment('조회수');
            $table->integer('likes_count')->default(0)->nullable(false)->comment('좋아요 수');
            $table->integer('comments_count')->default(0)->nullable(false)->comment('댓글 수');
            $table->timestamps();
            $table->softDeletes();

            // 인덱스
            $table->index('user_id', 'idx_user_id');
            $table->index('is_public', 'idx_is_public');
            $table->index('created_at', 'idx_created_at');
        });

        // 테이블 코멘트 추가
        DB::statement("ALTER TABLE portfolios COMMENT = '포트폴리오 작품 테이블'");
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('portfolios');
    }
};

