<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** 종료 직전 단계(채팅방 진행바에서 그 단계와 「종료」만 켠다). */
return new class extends Migration {
    public function up(): void
    {
        Schema::table('starttoo_consultation_cases', function (Blueprint $t) {
            $t->string('closed_from', 20)->nullable()->after('close_reason')->comment('종료 직전 상태: requested · consulting · quoted');
        });
    }

    public function down(): void
    {
        Schema::table('starttoo_consultation_cases', fn (Blueprint $t) => $t->dropColumn('closed_from'));
    }
};
