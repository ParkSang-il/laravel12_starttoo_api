<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('starttoo_artwork_uploads', function (Blueprint $t) {
            $t->string('status', 12)->default('ready')->after('path')
                ->comment('사진 처리 상태: processing 변환 대기, ready 첨부 가능, failed 변환 실패');
            $t->timestamp('locked_at')->nullable()->after('status')->comment('변환 작업이 잡은 시각: 중복 처리 방지');
            $t->unsignedTinyInteger('attempts')->default(0)->after('locked_at')->comment('변환 시도 횟수');
            $t->timestamp('processed_at')->nullable()->after('attempts')->comment('변환 완료 또는 실패 확정 시각');
        });
    }

    public function down(): void
    {
        Schema::table('starttoo_artwork_uploads', fn (Blueprint $t) => $t->dropColumn(['status', 'locked_at', 'attempts', 'processed_at']));
    }
};
