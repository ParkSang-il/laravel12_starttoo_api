<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * 작가 광고 게시 중지(운영자): 콘솔에서 끄면 노출만 막고 광고는 남긴다.
 * 작가가 앱에서 고쳐 다시 심사를 요청하고, 승인(또는 운영자 재개)하면 멈춘 기간만큼 종료일을 늘려 다시 나온다.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('starttoo_ads', function (Blueprint $t) {
            $t->timestamp('suspended_at')->nullable()->comment('운영자가 게시 중지한 시각(재개하면 그만큼 기간을 늘린다)');
            $t->string('suspend_reason', 300)->nullable()->comment('게시 중지 사유(작가에게 보인다)');
        });
    }

    public function down(): void
    {
        Schema::table('starttoo_ads', function (Blueprint $t) {
            $t->dropColumn(['suspended_at', 'suspend_reason']);
        });
    }
};
