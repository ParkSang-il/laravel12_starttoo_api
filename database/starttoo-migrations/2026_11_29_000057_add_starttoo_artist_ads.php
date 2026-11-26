<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * 작가가 직접 신청하는 광고(6-05 ~ 6-09). 운영자 광고와 같은 starttoo_ads 를 쓰고 product 가 있으면 작가 광고다.
 * 흐름: 신청(reviewing) → 운영 콘솔 승인(running, 바로 노출) / 반려(rejected). 결제는 Phase 6 에서 붙인다.
 * - ad_daily: 광고 · 날짜별 노출 · 클릭 · 상담 요청(6-09 일별 그래프)
 * - ad_clicks: 회원이 광고를 누른 기록(7일 안에 그 작가에게 상담을 요청하면 상담 요청 1건으로 센다)
 */
return new class extends Migration {
    public function up(): void
    {
        Schema::table('starttoo_ads', function (Blueprint $t) {
            $t->string('product', 20)->nullable()->after('slot')->comment('작가 광고 상품: search · feed · region · carousel (운영자 광고는 null)');
            $t->string('status', 12)->nullable()->after('product')->comment('작가 광고 상태: reviewing · rejected · running · ended');
            $t->uuid('artwork_id')->nullable()->after('image_path')->comment('작가 광고 작품(사진 · 상세 이동)');
            $t->json('keywords')->nullable()->after('artwork_id')->comment('검색 결과 상단: 검색 키워드(최대 3개)');
            $t->unsignedSmallInteger('days')->nullable()->comment('신청 기간(일). 한 달 상품은 null');
            $t->unsignedInteger('amount')->nullable()->comment('예상 광고비(부가세 별도). 결제는 Phase 6');
            $t->string('reject_reason', 300)->nullable();
            $t->json('pending')->nullable()->comment('노출 중 광고의 문구 · 작품 변경 요청(심사 대기). 승인하면 반영');
            $t->string('pending_reject_reason', 300)->nullable()->comment('변경 요청 반려 사유');
            $t->timestamp('paused_at')->nullable()->comment('작가가 노출을 멈춘 시각(다시 켜면 그만큼 기간을 늘린다)');
            $t->timestamp('requested_at')->nullable();
            $t->timestamp('reviewed_at')->nullable();
            $t->unsignedBigInteger('reviewed_by')->nullable()->comment('심사한 운영자');
            $t->unsignedInteger('leads')->default(0)->comment('광고를 누른 뒤 7일 안에 들어온 상담 요청');
            $t->index(['artist_id', 'product']);
            $t->index(['status']);
        });
        Schema::create('starttoo_ad_daily', function (Blueprint $t) {
            $t->unsignedBigInteger('ad_id');
            $t->date('day');
            $t->unsignedInteger('impressions')->default(0);
            $t->unsignedInteger('clicks')->default(0);
            $t->unsignedInteger('leads')->default(0);
            $t->primary(['ad_id', 'day']);
        });
        Schema::create('starttoo_ad_clicks', function (Blueprint $t) {
            $t->unsignedBigInteger('ad_id');
            $t->unsignedBigInteger('user_id');
            $t->unsignedBigInteger('artist_id');
            $t->timestamp('clicked_at')->comment('마지막으로 누른 시각');
            $t->unsignedBigInteger('lead_case_id')->nullable()->comment('상담 요청으로 센 상담 건(한 번만 센다)');
            $t->primary(['ad_id', 'user_id']);
            $t->index(['user_id', 'artist_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('starttoo_ad_clicks');
        Schema::dropIfExists('starttoo_ad_daily');
        Schema::table('starttoo_ads', function (Blueprint $t) {
            $t->dropIndex(['artist_id', 'product']);
            $t->dropIndex(['status']);
            $t->dropColumn(['product', 'status', 'artwork_id', 'keywords', 'days', 'amount', 'reject_reason', 'pending',
                'pending_reject_reason', 'paused_at', 'requested_at', 'reviewed_at', 'reviewed_by', 'leads']);
        });
    }
};
