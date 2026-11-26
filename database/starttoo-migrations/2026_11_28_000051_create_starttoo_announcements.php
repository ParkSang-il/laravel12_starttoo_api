<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** MY 「공지사항」. 운영자가 서버 명령(starttoo:announce)으로 등록 · 고정 · 내린다. */
return new class extends Migration {
    public function up(): void
    {
        Schema::create('starttoo_announcements', function (Blueprint $t) {
            $t->id();
            $t->string('category', 20)->default('notice')->comment('notice 공지 · update 업데이트 · event 이벤트');
            $t->string('title', 120);
            $t->text('body')->comment('본문(줄바꿈 유지, 최대 1만 자)');
            $t->boolean('pinned')->default(false)->comment('맨 위 고정');
            $t->boolean('active')->default(true)->comment('false면 앱에서 내림');
            $t->timestamp('published_at')->comment('게시 시각(앞으로의 시각이면 그때부터 보임)');
            $t->timestamps();
            $t->index(['active', 'pinned', 'published_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('starttoo_announcements');
    }
};
