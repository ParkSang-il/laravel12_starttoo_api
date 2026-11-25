<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * AI 타투 도구(도안 추출 · 필러 · 커버업)를 가상 피팅 결과 테이블에 함께 담는다.
 * - type: blend(가상 피팅) · extract · fill · coverup
 * - mask_path: 칠한 영역(흰색) · 필러는 채울 곳, 커버업은 기존 타투
 * - result_paths: 필러 3안
 * - options: 화면에서 고른 값(스타일 · 밀도 · 추출 형태 …), meta: 커버업 진단 값
 * - cost_units: 하루 AI 횟수에 세는 수(기기에서 만든 라인 추출은 0)
 */
return new class extends Migration {
    public function up(): void
    {
        Schema::table('starttoo_fit_results', function (Blueprint $t) {
            $t->string('type', 10)->default('blend')->after('request_key')->comment('blend · extract · fill · coverup');
            $t->string('mask_path', 255)->nullable()->after('stencil_path');
            $t->json('result_paths')->nullable()->after('result_path')->comment('필러 3안 경로');
            $t->json('options')->nullable()->after('result_paths');
            $t->json('meta')->nullable()->after('options')->comment('커버업 진단 등');
            $t->unsignedTinyInteger('cost_units')->default(1)->after('meta')->comment('하루 AI 횟수 차감(기기 처리 0)');
            $t->index(['user_id', 'type', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::table('starttoo_fit_results', function (Blueprint $t) {
            $t->dropIndex(['user_id', 'type', 'created_at']);
            $t->dropColumn(['type', 'mask_path', 'result_paths', 'options', 'meta', 'cost_units']);
        });
    }
};
