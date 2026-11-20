<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('starttoo_notifications', function (Blueprint $t) {
            $t->string('variant', 20)->nullable()->after('comment_id')
                ->comment('안내 알림 세부 종류: 아티스트 심사 submitted reviewing needs_changes approved rejected');
        });
    }

    public function down(): void
    {
        Schema::table('starttoo_notifications', fn (Blueprint $t) => $t->dropColumn('variant'));
    }
};
