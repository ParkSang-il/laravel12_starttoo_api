<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('starttoo_user_preferences', function (Blueprint $t) {
            $t->boolean('shopNews')->default(true)->after('social')
                ->comment('팔로우한 상점의 소식(공지·휴무·새 도안) 푸시');
        });
    }

    public function down(): void
    {
        Schema::table('starttoo_user_preferences', fn (Blueprint $t) => $t->dropColumn('shopNews'));
    }
};
