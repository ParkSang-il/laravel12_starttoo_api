<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * 작가 광고 「무빙 피드」(자리 moving_inline): 작가가 고른 자기 무빙(starttoo_videos)을 광고로 재생한다.
 * 영상이 지워지거나 비공개가 되면 그 광고는 노출하지 않는다(Ads::pickOwn 이 포스터가 없으면 건너뜀).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('starttoo_ads', function (Blueprint $t) {
            $t->uuid('video_id')->nullable()->after('artwork_id')->comment('무빙 피드 작가 광고: 광고로 재생할 내 무빙');
        });
    }

    public function down(): void
    {
        Schema::table('starttoo_ads', function (Blueprint $t) {
            $t->dropColumn('video_id');
        });
    }
};
