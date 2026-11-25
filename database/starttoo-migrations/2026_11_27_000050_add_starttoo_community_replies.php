<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** 커뮤니티 대댓글(답글). 한 단계만: 답글의 답글도 맨 위 댓글 아래에 붙는다. 댓글을 지우면 답글도 지워진다. */
return new class extends Migration {
    public function up(): void
    {
        Schema::table('starttoo_community_comments', function (Blueprint $t) {
            $t->foreignId('parent_id')->nullable()->after('user_id')->comment('답글이면 맨 위 댓글 번호')
                ->constrained('starttoo_community_comments')->cascadeOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('starttoo_community_comments', function (Blueprint $t) {
            $t->dropConstrainedForeignId('parent_id');
        });
    }
};
