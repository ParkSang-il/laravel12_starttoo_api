<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** 가상 모델 성별(female · male). 이전에 만든 사진은 남성으로 둔다. */
return new class extends Migration {
    public function up(): void
    {
        Schema::table('starttoo_fit_models', function (Blueprint $t) {
            $t->string('gender', 6)->default('male')->after('id')->comment('female 여성 · male 남성');
            $t->index(['part', 'face', 'gender', 'status']);
        });
    }

    public function down(): void
    {
        Schema::table('starttoo_fit_models', function (Blueprint $t) {
            $t->dropIndex(['part', 'face', 'gender', 'status']);
            $t->dropColumn('gender');
        });
    }
};
