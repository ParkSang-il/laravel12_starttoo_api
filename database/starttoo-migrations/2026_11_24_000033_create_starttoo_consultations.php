<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Phase 3 상담·채팅. 고객-작가 한 쌍당 상담방 하나(종료 없이 이어서 대화).
 * 두 참여자가 고정이라 참여자 테이블 대신 방에 각자의 읽음 위치를 둔다.
 */
return new class extends Migration {
    public function up(): void
    {
        Schema::create('starttoo_consultations', function (Blueprint $t) {
            $t->id()->comment('상담방 번호');
            $t->foreignId('customer_id')->comment('상담을 요청한 회원')->constrained('starttoo_users')->cascadeOnDelete();
            $t->foreignId('artist_id')->comment('상담 받는 아티스트')->constrained('starttoo_users')->cascadeOnDelete();
            $t->string('status', 20)->default('requested')
                ->comment('requested 응답 대기 · consulting 상담 중 · quoted 견적 도착 · booked 예약 확정(Phase 4)');
            $t->unsignedBigInteger('last_message_id')->nullable()->comment('목록 정렬·미리보기용 마지막 메시지');
            $t->timestamp('last_message_at')->nullable()->comment('마지막 메시지 시각');
            $t->unsignedBigInteger('customer_last_read_id')->default(0)->comment('고객이 읽은 마지막 메시지(앞으로만 이동)');
            $t->unsignedBigInteger('artist_last_read_id')->default(0)->comment('작가가 읽은 마지막 메시지(앞으로만 이동)');
            $t->timestamps();
            $t->unique(['customer_id', 'artist_id']);
            $t->index(['customer_id', 'last_message_at']);
            $t->index(['artist_id', 'last_message_at']);
        });

        Schema::create('starttoo_consultation_requests', function (Blueprint $t) {
            $t->id()->comment('상담 요청서 번호');
            $t->foreignId('consultation_id')->comment('상담방')->constrained('starttoo_consultations')->cascadeOnDelete();
            $t->char('artwork_id', 36)->nullable()->comment('참고 작품·도안');
            $t->string('body_part', 30)->nullable()->comment('희망 부위(작품 부위 코드)');
            $t->string('size', 2)->nullable()->comment('크기 S · M · L');
            $t->string('budget', 40)->nullable()->comment('예산(자유 입력)');
            $t->string('preferred_time', 40)->nullable()->comment('희망 시기(자유 입력)');
            $t->string('memo', 1000)->nullable()->comment('요청 내용');
            $t->timestamp('created_at')->nullable();
        });

        Schema::create('starttoo_messages', function (Blueprint $t) {
            $t->id()->comment('메시지 번호(방 안에서 시간순)');
            $t->foreignId('consultation_id')->comment('상담방')->constrained('starttoo_consultations')->cascadeOnDelete();
            $t->unsignedBigInteger('sender_id')->nullable()->comment('보낸 회원, 시스템 안내는 null');
            $t->char('client_id', 36)->nullable()->comment('앱이 만든 전송 키. 재전송해도 한 건만 저장');
            $t->string('kind', 12)->comment('text · image · artwork · request · quote · system');
            $t->text('body')->nullable()->comment('본문');
            $t->json('payload')->nullable()->comment('사진 경로·작품 번호·요청서 번호·견적 번호 등');
            $t->timestamp('created_at')->nullable();
            $t->unique(['consultation_id', 'sender_id', 'client_id']);
            $t->index(['consultation_id', 'id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('starttoo_messages');
        Schema::dropIfExists('starttoo_consultation_requests');
        Schema::dropIfExists('starttoo_consultations');
    }
};
