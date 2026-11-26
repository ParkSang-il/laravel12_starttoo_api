<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * 자체 광고 신규 노출(도달): 광고마다 처음 본 회원만 센다(같은 회원이 다시 봐도 늘지 않는다).
 * - ad_views: 광고 · 회원 한 쌍당 한 줄(처음 본 시각)
 * - ads.reach: 신규 노출 수(ad_views 줄 수와 같다). impressions 는 지금처럼 볼 때마다 센다
 */
return new class extends Migration {
    public function up(): void
    {
        Schema::create('starttoo_ad_views', function (Blueprint $t) {
            $t->unsignedBigInteger('ad_id');
            $t->unsignedBigInteger('user_id');
            $t->timestamp('created_at')->useCurrent()->comment('처음 본 시각');
            $t->primary(['ad_id', 'user_id']);
        });
        Schema::table('starttoo_ads', function (Blueprint $t) {
            $t->unsignedInteger('reach')->default(0)->comment('신규 노출(처음 본 회원 수)');
        });
    }

    public function down(): void
    {
        Schema::table('starttoo_ads', fn (Blueprint $t) => $t->dropColumn('reach'));
        Schema::dropIfExists('starttoo_ad_views');
    }
};
