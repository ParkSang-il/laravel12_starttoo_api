<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('starttoo_artworks', function (Blueprint $table) {
            $table->uuid('id')->primary()->comment('작품 고유 UUID');
            $table->foreignId('user_id')->comment('등록한 회원 번호')->constrained('starttoo_users')->cascadeOnDelete();
            $table->uuid('request_key')->comment('중복 게시 방지를 위한 요청 UUID');
            $table->char('payload_hash', 64)->comment('입력과 사진 순서를 포함한 요청 해시');
            $table->string('kind', 10)->comment('work 시술 작품, design 예약 가능 도안');
            $table->string('title', 100)->comment('작품 제목');
            $table->text('description')->nullable()->comment('작품 설명: 최대 2000자');
            $table->json('styles')->comment('타투 스타일 코드: 1~5개');
            $table->string('body_part', 30)->nullable()->comment('시술 부위 코드');
            $table->string('color', 20)->nullable()->comment('색상 코드');
            $table->unsignedInteger('duration_minutes')->nullable()->comment('작품 작업 시간: 분');
            $table->unsignedInteger('price')->nullable()->comment('도안 가격: 원');
            $table->boolean('show_in_shop')->default(true)->comment('상점 포트폴리오 노출 희망 여부');
            $table->timestamps();
            $table->unique(['user_id', 'request_key']);
            $table->index(['created_at', 'id']);
        });
        Schema::create('starttoo_artwork_images', function (Blueprint $table) {
            $table->id()->comment('작품 사진 고유 번호');
            $table->foreignUuid('artwork_id')->comment('작품 UUID')->constrained('starttoo_artworks')->cascadeOnDelete();
            $table->unsignedTinyInteger('position')->comment('사진 순서: 0이 대표 사진');
            $table->string('path', 255)->comment('S3 비공개 사진 객체 경로');
            $table->unique(['artwork_id', 'position']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('starttoo_artwork_images');
        Schema::dropIfExists('starttoo_artworks');
    }
};
