<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('starttoo_hidden_artworks', function (Blueprint $table) {
            $table->id()->comment('회원별 작품 숨김 기록 번호');
            $table->foreignId('user_id')->comment('숨김을 요청한 회원 번호')->constrained('starttoo_users')->cascadeOnDelete();
            $table->uuid('artwork_id')->comment('숨긴 작품 UUID');
            $table->foreign('artwork_id')->references('id')->on('starttoo_artworks')->cascadeOnDelete();
            $table->timestamp('created_at')->useCurrent()->comment('숨긴 시각');
            $table->unique(['user_id', 'artwork_id']);
        });
    }
    public function down(): void
    {
        Schema::dropIfExists('starttoo_hidden_artworks');
    }
};
