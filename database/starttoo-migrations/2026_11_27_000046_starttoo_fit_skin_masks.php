<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * 피부 영역 마스크(도안이 옷 · 배경으로 삐져나가지 않게 자른다).
 * - fit_models.skin_path: 가상 모델 사진마다 한 번 만들어 둔다
 * - fit_results.skin_path: 내 사진으로 피팅할 때 쓴 마스크(결과 자르기 · 다시 쓰기)
 */
return new class extends Migration {
    public function up(): void
    {
        Schema::table('starttoo_fit_models', function (Blueprint $t) {
            $t->string('skin_path', 255)->nullable()->after('image_path')->comment('피부 영역 마스크 PNG(흰색 = 피부)');
        });
        Schema::table('starttoo_fit_results', function (Blueprint $t) {
            $t->string('skin_path', 255)->nullable()->after('mask_path')->comment('피부 영역 마스크 PNG');
        });
    }

    public function down(): void
    {
        Schema::table('starttoo_fit_results', fn (Blueprint $t) => $t->dropColumn('skin_path'));
        Schema::table('starttoo_fit_models', fn (Blueprint $t) => $t->dropColumn('skin_path'));
    }
};
