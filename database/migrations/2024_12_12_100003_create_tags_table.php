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
        Schema::create('tags', function (Blueprint $table) {
            $table->id()->comment('기본 키');
            $table->string('name', 100)->unique()->comment('태그명');
            $table->integer('usage_count')->default(0)->comment('사용 횟수');
            $table->timestamps();

            // 인덱스
            $table->index('name', 'idx_name');
            $table->index('usage_count', 'idx_usage_count');
        });

        // 테이블 코멘트 추가
        DB::statement("ALTER TABLE tags COMMENT = '태그 마스터 테이블'");
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('tags');
    }
};

