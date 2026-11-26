<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * 운영 콘솔 공지 「게시할 때 푸시 보내기」: 예약 공지는 게시 시각이 되면 보낸다(starttoo:announce-push, 매분).
 */
return new class extends Migration {
    public function up(): void
    {
        Schema::table('starttoo_announcements', function (Blueprint $t) {
            $t->boolean('push_pending')->default(false)->comment('게시되면 푸시를 보낼 공지');
            $t->uuid('push_campaign_id')->nullable()->comment('보낸 푸시 캠페인');
            $t->index(['push_pending', 'published_at']);
        });
    }

    public function down(): void
    {
        Schema::table('starttoo_announcements', function (Blueprint $t) {
            $t->dropIndex(['push_pending', 'published_at']);
            $t->dropColumn(['push_pending', 'push_campaign_id']);
        });
    }
};
