<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('care_groups', function (Blueprint $table) {
            $table->char('region_code', 10)->nullable()->change();
            $table->string('region_label', 80)->nullable()->change();
        });
    }

    public function down(): void
    {
        if (DB::table('care_groups')->whereNull('region_code')->orWhereNull('region_label')->count() > 0) {
            throw new RuntimeException('지역 없는 그룹이 존재하므로 지역 필수 스키마로 되돌릴 수 없습니다.');
        }
        Schema::table('care_groups', function (Blueprint $table) {
            $table->char('region_code', 10)->nullable(false)->change();
            $table->string('region_label', 80)->nullable(false)->change();
        });
    }
};
