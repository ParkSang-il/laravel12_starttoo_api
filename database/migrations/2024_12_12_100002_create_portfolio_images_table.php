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
        Schema::create('portfolio_images', function (Blueprint $table) {
            $table->id()->comment('기본 키');
            $table->unsignedBigInteger('portfolio_id')->comment('포트폴리오 ID (portfolios.id)');
            $table->string('image_url', 255)->comment('이미지 URL');
            $table->integer('image_order')->default(0)->comment('이미지 순서');
            $table->decimal('scale', 5, 2)->nullable()->comment('이미지 스케일 (확대/축소 비율)');
            $table->decimal('offset_x', 10, 6)->nullable()->comment('X 오프셋 (정규화된 값, 0.0~1.0)');
            $table->decimal('offset_y', 10, 6)->nullable()->comment('Y 오프셋 (정규화된 값, 0.0~1.0)');
            $table->timestamps();

            // 인덱스
            $table->index('portfolio_id', 'idx_portfolio_id');
            $table->index(['portfolio_id', 'image_order'], 'idx_portfolio_order');
        });

        // 테이블 코멘트 추가
        DB::statement("ALTER TABLE portfolio_images COMMENT = '포트폴리오 이미지 테이블'");
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('portfolio_images');
    }
};

