<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('starttoo_shops', function (Blueprint $t) {
            $t->string('business_hours', 80)->nullable()->after('notice')->comment('운영 시간 안내');
            $t->string('guide', 600)->nullable()->after('business_hours')->comment('시술 전 안내');
        });
        // 2-11 상점 소식: 공지·휴무. 새 도안 소식은 도안 게시로 자동 표시한다.
        Schema::create('starttoo_shop_news', function (Blueprint $t) {
            $t->id()->comment('상점 소식 번호');
            $t->foreignId('user_id')->comment('상점 주인 회원 번호')->constrained('starttoo_users')->cascadeOnDelete();
            $t->string('kind', 12)->comment('notice 공지, holiday 휴무');
            $t->string('title', 80)->nullable()->comment('제목');
            $t->string('body', 1000)->comment('내용');
            $t->boolean('pinned')->default(false)->comment('상단 고정');
            $t->timestamp('created_at')->useCurrent()->comment('작성 시각');
            $t->index(['user_id', 'id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('starttoo_shop_news');
        Schema::table('starttoo_shops', fn (Blueprint $t) => $t->dropColumn(['business_hours', 'guide']));
    }
};
