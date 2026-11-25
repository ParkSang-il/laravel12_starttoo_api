<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * 가상 모델 사진을 운영자가 직접 올린다(starttoo:fit-models-import).
 * 같은 조건에 여러 장을 올릴 수 있게 조합 unique를 풀고, 같은 파일 중복 등록을 막는 해시를 둔다.
 */
return new class extends Migration {
    public function up(): void
    {
        Schema::table('starttoo_fit_models', function (Blueprint $t) {
            $t->dropUnique('starttoo_fit_models_combo');
            $t->char('source_hash', 64)->nullable()->after('image_path')->comment('올린 원본 파일 sha256(중복 방지)');
            $t->string('source_name', 255)->nullable()->after('source_hash')->comment('올린 파일 경로(운영 확인용)');
            $t->unique('source_hash');
        });
    }

    public function down(): void
    {
        Schema::table('starttoo_fit_models', function (Blueprint $t) {
            $t->dropUnique(['source_hash']);
            $t->dropColumn(['source_hash', 'source_name']);
            $t->unique(['part', 'face', 'tone', 'build', 'kind', 'light'], 'starttoo_fit_models_combo');
        });
    }
};
