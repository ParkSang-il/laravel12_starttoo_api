<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * 운영 콘솔 정보성 푸시: 캠페인 종류.
 * - marketing 광고성: (광고) 표시 · 수신 동의자만 · 09~20시 · 수신 거부 안내
 * - info 정보성(서비스 점검 · 정책 변경 등): 앱 알림을 켠 모든 정상 회원 · 시간 제한 없음 · 광고 내용 금지
 */
return new class extends Migration {
    public function up(): void
    {
        Schema::table('starttoo_push_campaigns', function (Blueprint $t) {
            $t->string('kind', 12)->default('marketing')->after('id')->comment('marketing 광고성 · info 정보성');
            // 공지 푸시(target_type notice)는 공지 번호를 넣으므로 UUID 전용 칸을 문자 칸으로
            $t->string('target_id', 36)->nullable()->comment('게시물 · 작품 UUID 또는 공지 번호')->change();
        });
    }

    public function down(): void
    {
        Schema::table('starttoo_push_campaigns', fn (Blueprint $t) => $t->dropColumn('kind'));
    }
};
