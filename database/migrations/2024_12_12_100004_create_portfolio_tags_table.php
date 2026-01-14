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
        Schema::create('portfolio_tags', function (Blueprint $table) {
            $table->id()->comment('기본 키');
            $table->unsignedBigInteger('portfolio_id')->nullable(false)->comment('포트폴리오 ID (portfolios.id)');
            $table->unsignedBigInteger('tag_id')->nullable(false)->comment('태그 ID (tags.id)');
            $table->timestamps();

            // 인덱스
            $table->index('portfolio_id', 'idx_portfolio_id');
            $table->index('tag_id', 'idx_tag_id');
            $table->unique(['portfolio_id', 'tag_id'], 'uq_portfolio_tag');
        });

        // 테이블 코멘트 추가
        DB::statement("ALTER TABLE portfolio_tags COMMENT = '포트폴리오 태그 테이블'");
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('portfolio_tags');
    }
};

