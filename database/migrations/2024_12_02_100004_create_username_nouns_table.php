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
        Schema::create('username_nouns', function (Blueprint $table) {
            $table->id()->comment('기본 키');
            $table->string('word')->unique()->comment('명사 단어');
            $table->string('category')->nullable()->comment('카테고리 (ink, tool, art, text, symbol, body, time, nature, place, concept)');
            $table->timestamps();

            //인덱스
            $table->index('created_at', 'idx_created_at');
        });

        // 테이블 코멘트 추가
        DB::statement("ALTER TABLE username_nouns COMMENT = 'username 생성용 명사 테이블'");
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('username_nouns');
    }
};

