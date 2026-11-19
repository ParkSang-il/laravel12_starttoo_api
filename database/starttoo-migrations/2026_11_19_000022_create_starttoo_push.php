<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('starttoo_push_devices', function (Blueprint $t) {
            $t->id()->comment('기기 등록 번호');
            $t->foreignId('user_id')->comment('현재 로그인한 회원')->constrained('starttoo_users')->cascadeOnDelete();
            $t->foreignUuid('session_id')->comment('토큰 등록 인증 세션')->constrained('starttoo_auth_sessions')->cascadeOnDelete();
            $t->uuid('installation_id')->unique()->comment('앱 설치별 식별 UUID');
            $t->char('token_hash', 64)->unique()->comment('FCM 토큰 SHA256 중복 방지');
            $t->text('token_ciphertext')->comment('암호화한 FCM 등록 토큰');
            $t->string('platform', 10)->comment('기기 플랫폼 android ios');
            $t->timestamp('updated_at')->comment('마지막 기기 동기화 시각');
            $t->index(['user_id', 'updated_at']);
        });
        Schema::create('starttoo_push_outbox', function (Blueprint $t) {
            $t->id()->comment('푸시 발송 작업 번호');
            $t->foreignId('notification_id')->unique()->comment('알림 번호')->constrained('starttoo_notifications')->cascadeOnDelete();
            $t->timestamp('created_at')->useCurrent()->comment('작업 생성 시각');
            $t->timestamp('expanded_at')->nullable()->comment('수신 기기별 작업 생성 시각');
        });
        Schema::create('starttoo_push_deliveries', function (Blueprint $t) {
            $t->id()->comment('기기별 발송 번호');
            $t->foreignId('outbox_id')->comment('소속 발송 작업')->constrained('starttoo_push_outbox')->cascadeOnDelete();
            $t->foreignId('device_id')->comment('수신 기기 등록 번호')->constrained('starttoo_push_devices')->cascadeOnDelete();
            $t->foreignUuid('session_id')->comment('작업 생성 당시 수신 인증 세션')->constrained('starttoo_auth_sessions')->cascadeOnDelete();
            $t->char('token_hash', 64)->comment('작업 생성 당시 토큰 해시');
            $t->string('state', 12)->default('pending')->comment('pending sending sent skipped failed');
            $t->unsignedSmallInteger('attempts')->default(0)->comment('발송 시도 횟수');
            $t->timestamp('available_at')->comment('다음 시도 가능 시각');
            $t->timestamp('leased_until')->nullable()->comment('작업 중복 실행 방지 임대 만료');
            $t->uuid('lease_key')->nullable()->comment('발송 시도 소유 UUID');
            $t->unique(['outbox_id', 'device_id'], 'st_push_delivery_unique');
            $t->index(['state', 'available_at'], 'st_push_pending');
        });
    }
    public function down(): void
    {
        Schema::dropIfExists('starttoo_push_deliveries');
        Schema::dropIfExists('starttoo_push_outbox');
        Schema::dropIfExists('starttoo_push_devices');
    }
};
