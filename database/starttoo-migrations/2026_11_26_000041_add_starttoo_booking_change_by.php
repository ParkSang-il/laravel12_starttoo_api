<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * 일정 조율: 변경 희망 시간을 누가 냈는지(customer 변경 요청 / artist 다른 시간 제안).
 * 상대가 수락·거절한다. 비어 있으면 고객 요청(기존 데이터).
 */
return new class extends Migration {
    public function up(): void
    {
        Schema::table('starttoo_bookings', function (Blueprint $t) {
            $t->string('change_by', 10)->nullable()->after('change_starts_at')->comment('customer|artist');
        });
    }

    public function down(): void
    {
        Schema::table('starttoo_bookings', fn (Blueprint $t) => $t->dropColumn('change_by'));
    }
};
