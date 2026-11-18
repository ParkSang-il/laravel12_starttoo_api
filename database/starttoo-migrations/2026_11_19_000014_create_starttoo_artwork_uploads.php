<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // 작품 사진을 게시 전에 1장씩 먼저 올린다. 검사·변환을 마친 사진만 기록한다.
        Schema::create('starttoo_artwork_uploads', function (Blueprint $table) {
            $table->uuid('id')->primary()->comment('업로드 사진 UUID: 작품 등록·편집 시 media_id로 사용');
            $table->foreignId('user_id')->comment('올린 회원 번호')->constrained('starttoo_users')->cascadeOnDelete();
            $table->uuid('request_key')->comment('회원별 중복 업로드 방지 UUID');
            $table->char('source_hash', 64)->comment('원본 파일 SHA-256: 같은 키의 다른 파일 거절');
            $table->string('path', 255)->comment('S3 비공개 사진 객체 경로');
            $table->timestamp('attached_at')->nullable()->comment('작품에 첨부된 시각: null이면 미첨부');
            $table->timestamp('created_at')->useCurrent()->comment('업로드 시각: 미첨부 24시간 경과 시 정리');
            $table->unique(['user_id', 'request_key']);
            $table->index(['user_id', 'attached_at', 'created_at']);
            $table->index('path');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('starttoo_artwork_uploads');
    }
};
