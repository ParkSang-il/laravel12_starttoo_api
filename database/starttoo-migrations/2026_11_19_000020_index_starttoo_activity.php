<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    private const TABLES = [
        'starttoo_artwork_interactions' => 'starttoo_activity_art_interaction_idx',
        'starttoo_post_likes' => 'starttoo_activity_post_like_idx',
        'starttoo_post_saves' => 'starttoo_activity_post_save_idx',
        'starttoo_artwork_comments' => 'starttoo_activity_art_comment_idx',
        'starttoo_post_comments' => 'starttoo_activity_post_comment_idx',
    ];
    public function up(): void
    {
        // 본인 활동만 최신순으로 최대 21개 후보를 조회하는 복합 인덱스. 업무 컬럼은 추가하지 않는다.
        foreach (self::TABLES as $table => $name) Schema::table($table, fn (Blueprint $t) => $t->index(['user_id', 'created_at', 'id'], $name));
    }
    public function down(): void
    {
        foreach (self::TABLES as $table => $name) Schema::table($table, fn (Blueprint $t) => $t->dropIndex($name));
    }
};
