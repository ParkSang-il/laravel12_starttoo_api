<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('starttoo_users', function (Blueprint $t) {
            $t->string('status', 20)->default('active')
                ->comment('계정 상태: active 정상, suspended 이용 정지, withdrawn 탈퇴')->change();
            $t->timestamp('withdrawn_at')->nullable()->comment('회원 탈퇴 시각: 데이터는 보관, 공개 콘텐츠는 비공개');
            $t->string('withdraw_reason', 40)->nullable()->comment('탈퇴 사유 코드');
            $t->string('withdraw_note', 300)->nullable()->comment('탈퇴 사유 직접 입력');
        });
        Schema::table('starttoo_auth_identities', function (Blueprint $t) {
            $t->timestamp('withdrawn_at')->nullable()
                ->comment('탈퇴로 연결 해제한 시각: subject를 바꿔 같은 소셜 계정의 재가입을 허용');
        });
    }

    public function down(): void
    {
        Schema::table('starttoo_auth_identities', fn (Blueprint $t) => $t->dropColumn('withdrawn_at'));
        Schema::table('starttoo_users', fn (Blueprint $t) => $t->dropColumn(['withdrawn_at', 'withdraw_reason', 'withdraw_note']));
    }
};
