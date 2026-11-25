<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * 자체 광고. 자리(slot)별로 운영자가 등록한다(starttoo:ad). 구글 광고와 별개.
 * 누르면 작가 상점(artist_id) 또는 앱 안 화면(link)으로 간다.
 */
return new class extends Migration {
    public function up(): void
    {
        Schema::create('starttoo_ads', function (Blueprint $t) {
            $t->id();
            $t->string('slot', 30)->comment('community_top: 커뮤니티 피드 맨 위 카드');
            $t->string('advertiser', 60)->comment('광고주 이름');
            $t->string('title', 80)->comment('광고 문구');
            $t->string('image_path')->nullable()->comment('배경 이미지');
            $t->foreignId('artist_id')->nullable()->comment('누르면 이 작가 상점으로')->constrained('starttoo_users')->nullOnDelete();
            $t->string('link', 200)->nullable()->comment('누르면 갈 앱 안 주소(/로 시작). artist_id가 없을 때');
            $t->timestamp('starts_at')->nullable()->comment('노출 시작(없으면 바로)');
            $t->timestamp('ends_at')->nullable()->comment('노출 끝(없으면 계속)');
            $t->boolean('active')->default(true);
            $t->unsignedInteger('impressions')->default(0)->comment('노출 수');
            $t->unsignedInteger('clicks')->default(0)->comment('누른 수');
            $t->timestamps();
            $t->index(['slot', 'active']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('starttoo_ads');
    }
};
