<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dateTime('suspended_until')->nullable()->after('phone_verified_at')->comment('정지 해제 일시 (null이면 정지 안됨, 영구정지면 9999-12-31)');
            $table->string('suspension_type', 20)->nullable()->after('suspended_until')->comment('정지 유형 (5days, 10days, 15days, 30days, permanent)');
            $table->text('suspension_reason')->nullable()->after('suspension_type')->comment('정지 사유');
            $table->unsignedBigInteger('suspended_by')->nullable()->after('suspension_reason')->comment('정지 처리한 관리자 ID');
            $table->dateTime('suspended_at')->nullable()->after('suspended_by')->comment('정지 처리 일시');
            
            $table->index('suspended_until', 'idx_suspended_until');
            $table->index('suspension_type', 'idx_suspension_type');
        });

        DB::statement("ALTER TABLE users COMMENT = '사용자 테이블 (정지 기능 포함)'");
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropIndex('idx_suspended_until');
            $table->dropIndex('idx_suspension_type');
            $table->dropColumn([
                'suspended_until',
                'suspension_type',
                'suspension_reason',
                'suspended_by',
                'suspended_at',
            ]);
        });
    }
};

