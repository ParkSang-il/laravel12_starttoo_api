<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** P3-6 메시지 신고 처리: 운영자가 가린 메시지는 내용 없이 「가려진 메시지」로 보인다. */
return new class extends Migration {
    public function up(): void
    {
        Schema::table('starttoo_messages', function (Blueprint $t) {
            $t->boolean('moderation_hidden')->default(false)->after('payload')->comment('운영 정책으로 가림');
        });
    }

    public function down(): void
    {
        Schema::table('starttoo_messages', fn (Blueprint $t) => $t->dropColumn('moderation_hidden'));
    }
};
