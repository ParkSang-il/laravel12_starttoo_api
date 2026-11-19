<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('starttoo_push_logs', function (Blueprint $t) {
            $t->id()->comment('푸시 시도 및 열람 이력 번호');
            $t->string('source_type', 16)->comment('notification 또는 campaign');
            $t->string('source_id', 36)->comment('알림 번호 또는 광고 캠페인 UUID');
            $t->unsignedBigInteger('delivery_id')->nullable()->comment('발송 작업 번호 스냅샷');
            $t->unsignedBigInteger('user_id')->nullable()->comment('수신 회원 번호 스냅샷');
            $t->unsignedBigInteger('device_id')->nullable()->comment('기기 번호 스냅샷 삭제 후에도 이력 유지');
            $t->string('platform', 10)->nullable()->comment('수신 기기 플랫폼');
            $t->string('kind', 20)->nullable()->comment('알림 이벤트 또는 marketing');
            $t->string('title_snapshot', 100)->nullable()->comment('발송 제목 스냅샷');
            $t->string('body_snapshot', 800)->nullable()->comment('발송 안내 본문 스냅샷 토큰이나 댓글 원문은 저장하지 않음');
            $t->unsignedSmallInteger('attempt')->default(0)->comment('해당 기기 발송 시도 순번');
            $t->string('result', 20)->comment('started sent retry invalid rejected skipped exception opened');
            $t->string('reason', 80)->nullable()->comment('원문이나 토큰을 제외한 결과 분류');
            $t->unsignedSmallInteger('http_status')->nullable()->comment('FCM HTTP 응답 코드');
            $t->string('fcm_message_id', 255)->nullable()->comment('FCM 접수 메시지 식별자');
            $t->timestamp('created_at')->useCurrent()->comment('발송 시도 또는 열람 기록 시각');
            $t->timestamp('finished_at')->nullable()->comment('발송 결과 기록 시각');
            $t->index(['source_type', 'source_id', 'result'], 'st_push_log_source');
            $t->index(['user_id', 'created_at'], 'st_push_log_user');
        });
        Schema::create('starttoo_notification_fanouts', function (Blueprint $t) {
            $t->id()->comment('팔로워 새 게시물 알림 분할 작업 번호');
            $t->unsignedBigInteger('author_id')->comment('게시물 작성 회원');
            $t->string('target_type', 12)->comment('artwork 또는 post');
            $t->uuid('target_id')->comment('새 게시물 UUID');
            $t->unsignedBigInteger('last_follow_id')->default(0)->comment('마지막 처리 팔로우 번호');
            $t->unsignedBigInteger('max_follow_id')->comment('게시 시점 팔로우 번호 상한');
            $t->boolean('complete')->default(false)->comment('분할 처리 완료 여부');
            $t->timestamp('created_at')->useCurrent()->comment('공개 게시 시각');
            $t->unique(['target_type', 'target_id'], 'st_notify_new_post_unique');
        });
        Schema::create('starttoo_push_campaigns', function (Blueprint $t) {
            $t->uuid('id')->primary()->comment('광고 푸시 캠페인 UUID');
            $t->string('title', 60)->comment('광고 표시가 포함된 제목');
            $t->string('body', 800)->comment('발신자 및 수신 거부 안내를 포함한 본문');
            $t->string('target_type', 16)->comment('home artwork post settings');
            $t->uuid('target_id')->nullable()->comment('앱 내부 게시물 대상 UUID');
            $t->string('state', 16)->default('draft')->comment('draft running paused cancelled completed');
            $t->json('user_ids')->nullable()->comment('지정 회원 목록 null은 전체 동의 회원');
            $t->unsignedBigInteger('max_device_id')->default(0)->comment('시작 시점 기기 번호 상한');
            $t->unsignedBigInteger('last_device_id')->default(0)->comment('분할 대상 생성 커서');
            $t->boolean('expanded')->default(false)->comment('전체 수신 작업 생성 완료 여부');
            $t->timestamp('created_at')->useCurrent()->comment('캠페인 생성 시각');
            $t->timestamp('started_at')->nullable()->comment('최초 발송 시작 시각');
            $t->timestamp('expires_at')->nullable()->comment('캠페인 발송 기한');
            $t->timestamp('next_send_at')->nullable()->comment('캠페인별 속도 제한의 다음 발송 시각');
            $t->unsignedInteger('per_minute')->default(300)->comment('캠페인 분당 최대 발송 시도 수');
            $t->timestamp('rate_window_at')->nullable()->comment('현재 속도 제한 집계 창 시작 시각');
            $t->unsignedInteger('rate_count')->default(0)->comment('현재 집계 창의 발송 시도 수');
        });
        Schema::create('starttoo_campaign_deliveries', function (Blueprint $t) {
            $t->id()->comment('광고 기기별 발송 작업 번호');
            $t->foreignUuid('campaign_id')->comment('광고 캠페인 UUID')->constrained('starttoo_push_campaigns')->cascadeOnDelete();
            $t->unsignedBigInteger('device_id')->comment('대상 기기 번호 스냅샷');
            $t->unsignedBigInteger('user_id')->comment('대상 회원 번호 스냅샷');
            $t->uuid('session_id')->comment('대상 세션 스냅샷');
            $t->char('token_hash', 64)->comment('대상 토큰 해시 스냅샷');
            $t->string('state', 12)->default('pending')->comment('pending sending sent skipped failed');
            $t->unsignedSmallInteger('attempts')->default(0)->comment('FCM 발송 시도 수');
            $t->timestamp('available_at')->comment('다음 시도 가능 시각');
            $t->uuid('lease_key')->nullable()->comment('현재 시도 소유 UUID');
            $t->timestamp('leased_until')->nullable()->comment('발송 임대 만료 시각');
            $t->timestamp('opened_at')->nullable()->comment('해당 회원의 캠페인 클릭 시각');
            $t->unique(['campaign_id', 'device_id'], 'st_campaign_device_unique');
            $t->index(['state', 'available_at'], 'st_campaign_pending');
            $t->index(['campaign_id', 'user_id'], 'st_campaign_recipient');
            $t->index(['campaign_id', 'state', 'available_at', 'id'], 'st_campaign_ready');
        });
    }
    public function down(): void
    {
        Schema::dropIfExists('starttoo_campaign_deliveries');
        Schema::dropIfExists('starttoo_push_campaigns');
        Schema::dropIfExists('starttoo_notification_fanouts');
        Schema::dropIfExists('starttoo_push_logs');
    }
};
