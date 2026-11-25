<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * 상담 건(case). 대화방(고객-작가 한 쌍)은 하나로 이어지고, 그 안에서 상담이 건별로 열리고 닫힌다.
 * - 새 요청서 → 새 건(진행 중인 건이 있으면 그 건에 요청서를 덧붙인다)
 * - 아티스트가 종료하거나, 예약이 끝까지 진행되면(Phase 4) 건이 닫힌다. 대화는 계속할 수 있다.
 * - 견적은 건에 속한다. 대화방 status는 현재(가장 최근) 건의 상태를 그대로 보여준다.
 */
return new class extends Migration {
    public function up(): void
    {
        Schema::create('starttoo_consultation_cases', function (Blueprint $t) {
            $t->id()->comment('상담 건 번호');
            $t->foreignId('consultation_id')->comment('대화방')->constrained('starttoo_consultations')->cascadeOnDelete();
            $t->unsignedBigInteger('customer_id')->comment('요청한 회원(목록 조회용)');
            $t->unsignedBigInteger('artist_id')->comment('아티스트(목록 조회용)');
            $t->string('status', 20)->default('requested')
                ->comment('requested 응답 대기 · consulting 상담 중 · quoted 견적 · booked 예약 · completed 완료 · closed 종료');
            $t->string('close_reason', 20)->nullable()->comment('종료 사유 코드: schedule · budget · other_artist · inquiry_done · other');
            $t->timestamp('closed_at')->nullable();
            $t->timestamps();
            $t->index(['customer_id', 'status', 'updated_at']);
            $t->index(['artist_id', 'status', 'updated_at']);
        });
        Schema::table('starttoo_consultations', function (Blueprint $t) {
            $t->unsignedBigInteger('current_case_id')->nullable()->after('status')->comment('현재(가장 최근) 상담 건');
        });
        Schema::table('starttoo_consultation_requests', function (Blueprint $t) {
            $t->unsignedBigInteger('case_id')->nullable()->after('consultation_id')->index()->comment('상담 건');
        });
        Schema::table('starttoo_quotes', function (Blueprint $t) {
            $t->unsignedBigInteger('case_id')->nullable()->after('consultation_id')->index()->comment('상담 건');
        });

        // 기존 대화방은 지금 상태 그대로 한 건으로 옮긴다.
        $db = DB::connection($this->getConnection());
        foreach ($db->table('starttoo_consultations')->orderBy('id')->get() as $room) {
            $caseId = $db->table('starttoo_consultation_cases')->insertGetId([
                'consultation_id' => $room->id, 'customer_id' => $room->customer_id, 'artist_id' => $room->artist_id,
                'status' => $room->status, 'created_at' => $room->created_at, 'updated_at' => $room->last_message_at ?? $room->updated_at,
            ]);
            $db->table('starttoo_consultations')->where('id', $room->id)->update(['current_case_id' => $caseId]);
            $db->table('starttoo_consultation_requests')->where('consultation_id', $room->id)->update(['case_id' => $caseId]);
            $db->table('starttoo_quotes')->where('consultation_id', $room->id)->update(['case_id' => $caseId]);
        }
    }

    public function down(): void
    {
        Schema::table('starttoo_quotes', fn (Blueprint $t) => $t->dropColumn('case_id'));
        Schema::table('starttoo_consultation_requests', fn (Blueprint $t) => $t->dropColumn('case_id'));
        Schema::table('starttoo_consultations', fn (Blueprint $t) => $t->dropColumn('current_case_id'));
        Schema::dropIfExists('starttoo_consultation_cases');
    }
};
