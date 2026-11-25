<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * 작가가 정하는 예약 규정: 시술 N일 전까지 무료 변경·취소, 일정 변경 횟수.
 * 예약에는 요청 시점의 규정을 그대로 저장한다(작가가 나중에 바꿔도 기존 예약은 그대로).
 */
return new class extends Migration {
    public function up(): void
    {
        Schema::table('starttoo_booking_settings', function (Blueprint $t) {
            $t->unsignedTinyInteger('free_days')->default(3)->after('start_times')->comment('시술 N일 전까지 무료 변경·취소');
            $t->unsignedTinyInteger('max_changes')->default(1)->after('free_days')->comment('일정 변경 가능 횟수');
        });
        Schema::table('starttoo_bookings', function (Blueprint $t) {
            $t->unsignedTinyInteger('free_days')->default(3)->after('change_count')->comment('요청 시점 규정: N일 전까지 무료 변경·취소');
            $t->unsignedTinyInteger('max_changes')->default(1)->after('free_days')->comment('요청 시점 규정: 변경 가능 횟수');
        });
    }

    public function down(): void
    {
        Schema::table('starttoo_bookings', fn (Blueprint $t) => $t->dropColumn(['free_days', 'max_changes']));
        Schema::table('starttoo_booking_settings', fn (Blueprint $t) => $t->dropColumn(['free_days', 'max_changes']));
    }
};
