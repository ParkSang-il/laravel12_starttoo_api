<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('portfolios', function (Blueprint $table) {
            $table->dropIndex('idx_video_job_id');
            $table->dropIndex('idx_video_status');
            $table->dropColumn([
                'video_file_path',
                'video_url',
                'video_thumbnail_url',
                'video_job_id',
                'video_status',
            ]);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('portfolios', function (Blueprint $table) {
            $table->string('video_file_path', 500)->nullable()->after('description')->comment('원본 비디오 파일 경로');
            $table->string('video_url', 500)->nullable()->after('video_file_path')->comment('인코딩 완료 후 HLS 재생 URL');
            $table->string('video_thumbnail_url', 500)->nullable()->after('video_url')->comment('비디오 썸네일 URL');
            $table->unsignedBigInteger('video_job_id')->nullable()->after('video_thumbnail_url')->comment('VOD 인코딩 작업 ID');
            $table->enum('video_status', ['pending', 'processing', 'complete', 'failed'])->nullable()->after('video_job_id')->comment('비디오 인코딩 상태');
            
            // 인덱스 추가
            $table->index('video_job_id', 'idx_video_job_id');
            $table->index('video_status', 'idx_video_status');
        });
    }
};

