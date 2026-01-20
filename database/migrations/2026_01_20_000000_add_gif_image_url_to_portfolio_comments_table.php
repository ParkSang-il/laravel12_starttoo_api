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
        Schema::table('portfolio_comments', function (Blueprint $table) {
            $table->string('gif_image_url', 500)->nullable()->after('content')->comment('GIF 이미지 URL');
        });

        // 테이블 코멘트 업데이트
        DB::statement("ALTER TABLE portfolio_comments COMMENT = '포트폴리오 댓글 테이블'");
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('portfolio_comments', function (Blueprint $table) {
            $table->dropColumn('gif_image_url');
        });
    }
};

