<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * 운영 콘솔 M-19 「사용 중인 버전 분포」: 앱이 시작 · 앞으로 올 때 부르는 버전 확인(GET app/version)을
 * 날짜 · 플랫폼 · 버전별로 센다(회원 · 기기 정보는 남기지 않는다).
 * 버전 정책 값 자체는 starttoo_settings('app_version')에 둔다.
 */
return new class extends Migration {
    public function up(): void
    {
        Schema::create('starttoo_app_version_stats', function (Blueprint $t) {
            $t->date('day');
            $t->string('platform', 10)->comment('ios · android');
            $t->string('version', 20);
            $t->unsignedInteger('checks')->default(0)->comment('버전 확인 횟수(앱 실행 · 앞으로 올 때)');
            $t->primary(['day', 'platform', 'version']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('starttoo_app_version_stats');
    }
};
