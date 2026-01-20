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
        Schema::create('portfolio_videos', function (Blueprint $table) {
            $table->id()->comment('기본 키');
            $table->unsignedBigInteger('portfolio_id')->nullable(false)->comment('포트폴리오 ID (portfolios.id)');
            $table->string('video_file_path', 500)->nullable(false)->comment('원본 비디오 파일 경로');
            $table->string('video_url', 500)->nullable()->comment('인코딩 완료 후 HLS 재생 URL');
            $table->string('video_thumbnail_url', 500)->nullable()->comment('비디오 썸네일 URL');
            $table->unsignedBigInteger('video_job_id')->nullable()->comment('VOD 인코딩 작업 ID');
            $table->enum('video_status', ['pending', 'processing', 'complete', 'failed'])->nullable()->comment('비디오 인코딩 상태');
            $table->integer('video_order')->default(0)->nullable(false)->comment('비디오 순서');
            $table->timestamps();

            // 인덱스
            $table->index('portfolio_id', 'idx_portfolio_id');
            $table->index(['portfolio_id', 'video_order'], 'idx_portfolio_video_order');
            $table->index('video_file_path', 'idx_video_file_path');
            $table->index('video_job_id', 'idx_video_job_id');
            $table->index('video_status', 'idx_video_status');
        });

        // 테이블 코멘트 추가
        DB::statement("ALTER TABLE portfolio_videos COMMENT = '포트폴리오 비디오 테이블'");
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('portfolio_videos');
    }
};

