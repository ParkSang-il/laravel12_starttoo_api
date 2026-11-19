<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('starttoo_user_preferences', function (Blueprint $t) {
            $t->foreignId('user_id')->primary()->comment('설정 소유 회원 번호')->constrained('starttoo_users')->cascadeOnDelete();
            foreach (['chat' => '상담 메시지 알림', 'booking' => '예약 알림', 'social' => '소셜 알림', 'dm' => '비팔로워 메시지 허용'] as $key => $label) $t->boolean($key)->default(true)->comment($label.' 여부');
            $t->boolean('privateSave')->default(false)->comment('저장 목록 공개 여부');
            $t->unsignedInteger('version')->default(0)->comment('동시 수정 충돌 방지 버전');
            $t->timestamp('updated_at')->nullable()->comment('마지막 설정 변경 시각');
        });
        Schema::create('starttoo_preference_requests', function (Blueprint $t) {
            $t->id()->comment('설정 변경 요청 번호');
            $t->foreignId('user_id')->comment('요청 회원 번호')->constrained('starttoo_users')->cascadeOnDelete();
            $t->uuid('request_key')->comment('재시도 중복 방지 UUID');
            $t->char('payload_hash', 64)->comment('요청 본문 해시');
            $t->json('response_payload')->comment('재시도 반환 설정 JSON');
            $t->timestamp('created_at')->useCurrent()->comment('설정 변경 기록 시각');
            $t->unique(['user_id', 'request_key'], 'st_pref_request_unique');
        });
        Schema::create('starttoo_marketing_choices', function (Blueprint $t) {
            $t->id()->comment('광고 수신 선택 변경 번호');
            $t->foreignId('user_id')->comment('선택 회원 번호')->constrained('starttoo_users')->cascadeOnDelete();
            $t->boolean('accepted')->comment('광고 수신 동의 여부');
            $t->json('document_snapshot')->nullable()->comment('동의 당시 광고 수신 문서와 초안 여부');
            $t->timestamp('recorded_at')->comment('선택 변경 서버 시각');
            $t->index(['user_id', 'recorded_at', 'id'], 'st_marketing_latest');
        });
        Schema::create('starttoo_notifications', function (Blueprint $t) {
            $t->id()->comment('알림 고유 번호');
            $t->foreignId('user_id')->comment('수신 회원 번호')->constrained('starttoo_users')->cascadeOnDelete();
            $t->foreignId('actor_id')->comment('알림 발생 회원 번호')->constrained('starttoo_users')->cascadeOnDelete();
            $t->string('kind', 16)->comment('알림 종류 like comment reply follow');
            $t->string('target_type', 16)->comment('대상 종류 artwork post user');
            $t->string('target_id', 36)->comment('대상 식별자');
            $t->unsignedBigInteger('comment_id')->nullable()->comment('댓글 또는 답글 번호');
            $t->char('event_key', 64)->unique()->comment('활성 이벤트별 중복 알림 방지 해시');
            $t->timestamp('read_at')->nullable()->comment('읽음 처리 시각');
            $t->timestamp('created_at')->useCurrent()->comment('알림 생성 시각');
            $t->index(['user_id', 'id'], 'st_notification_page');
            $t->index(['user_id', 'read_at'], 'st_notification_unread');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('starttoo_notifications');
        Schema::dropIfExists('starttoo_marketing_choices');
        Schema::dropIfExists('starttoo_preference_requests');
        Schema::dropIfExists('starttoo_user_preferences');
    }
};
