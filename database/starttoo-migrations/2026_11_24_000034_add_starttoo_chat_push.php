<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** 상담 메시지 푸시: 알림함에 쌓지 않고 발송함에서 메시지를 직접 가리킨다. */
return new class extends Migration {
    public function up(): void
    {
        Schema::table('starttoo_push_outbox', function (Blueprint $t) {
            $t->unsignedBigInteger('notification_id')->nullable()->comment('알림 번호(소셜·안내 알림)')->change();
            $t->foreignId('message_id')->nullable()->unique()->after('notification_id')
                ->comment('상담 메시지 번호(채팅 푸시)')->constrained('starttoo_messages')->cascadeOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('starttoo_push_outbox', function (Blueprint $t) {
            $t->dropConstrainedForeignId('message_id');
        });
    }
};
