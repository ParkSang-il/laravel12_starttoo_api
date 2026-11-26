<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** 작가 광고: 심사 요청 때 동의한 광고 운영 정책 버전 · 시각(마지막 요청 기준) */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('starttoo_ads', function (Blueprint $t) {
            $t->string('policy_version', 40)->nullable()->comment('동의한 광고 운영 정책 버전(basic = 기본안, 아니면 콘솔 저장 시각)');
            $t->timestamp('policy_agreed_at')->nullable()->comment('광고 운영 정책 동의 시각');
        });
    }

    public function down(): void
    {
        Schema::table('starttoo_ads', function (Blueprint $t) {
            $t->dropColumn(['policy_version', 'policy_agreed_at']);
        });
    }
};
