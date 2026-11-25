<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Phase 4 예약.
 * - 아티스트 가용 시간: 휴무 요일 · 시작 가능 시간 · 특정 휴무일
 * - 예약: 상담 건의 유효한 견적으로 요청 → 작가 확정(= 예약 확정, 결제는 Phase 6) → 시술 완료
 *   확정된 예약만 그 시간을 잡는다(같은 시간 요청은 여러 개 가능, 하나를 확정하면 나머지는 자동 거절).
 */
return new class extends Migration {
    public function up(): void
    {
        Schema::create('starttoo_booking_settings', function (Blueprint $t) {
            $t->unsignedBigInteger('user_id')->primary()->comment('아티스트');
            $t->json('closed_weekdays')->comment('휴무 요일 [1=월 … 7=일]');
            $t->json('start_times')->comment('시작 가능 시간 ["11:00", …]');
            $t->timestamp('updated_at')->nullable();
        });
        Schema::create('starttoo_booking_closures', function (Blueprint $t) {
            $t->id();
            $t->unsignedBigInteger('user_id')->comment('아티스트');
            $t->date('date')->comment('특정 휴무일');
            $t->unique(['user_id', 'date']);
        });
        Schema::create('starttoo_bookings', function (Blueprint $t) {
            $t->id()->comment('예약 번호');
            $t->foreignId('consultation_id')->comment('대화방')->constrained('starttoo_consultations')->cascadeOnDelete();
            $t->unsignedBigInteger('case_id')->index()->comment('상담 건');
            $t->unsignedBigInteger('quote_id')->comment('예약한 견적');
            $t->unsignedBigInteger('customer_id');
            $t->unsignedBigInteger('artist_id');
            $t->uuid('client_id')->comment('요청 중복 방지 키');
            $t->dateTime('starts_at')->comment('시술 시작(한국 시간)');
            $t->dateTime('change_starts_at')->nullable()->comment('변경 요청한 새 시작 시각');
            $t->unsignedTinyInteger('change_count')->default(0)->comment('수락된 일정 변경 횟수');
            $t->string('status', 20)->default('pending')
                ->comment('pending 확정 대기 · confirmed 확정 · change_requested 변경 요청 · completed 시술 완료 · cancelled 취소 · rejected 거절 · expired 만료');
            // 견적 시점 정보(견적이 바뀌어도 예약 내용은 그대로)
            $t->string('subject', 100);
            $t->unsignedInteger('total');
            $t->unsignedInteger('deposit');
            $t->string('duration', 40)->nullable();
            $t->string('cancel_reason', 20)->nullable();
            $t->string('cancelled_by', 10)->nullable()->comment('customer · artist · system');
            $t->string('reject_reason', 20)->nullable()->comment('artist 작가 거절 · slot_taken 같은 시간 확정');
            $t->timestamp('confirmed_at')->nullable();
            $t->timestamp('completed_at')->nullable();
            $t->timestamp('cancelled_at')->nullable();
            $t->timestamps();
            $t->unique(['customer_id', 'client_id']);
            $t->index(['artist_id', 'starts_at']);
            $t->index(['customer_id', 'starts_at']);
            $t->index(['status', 'starts_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('starttoo_bookings');
        Schema::dropIfExists('starttoo_booking_closures');
        Schema::dropIfExists('starttoo_booking_settings');
    }
};
