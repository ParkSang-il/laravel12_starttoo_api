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
        Schema::create('portfolio_reports', function (Blueprint $table) {
            $table->id()->comment('기본 키');
            $table->unsignedBigInteger('portfolio_id')->comment('포트폴리오 ID (portfolios.id)');
            $table->unsignedBigInteger('user_id')->comment('신고한 사용자 ID (users.id)');
            $table->string('report_type', 50)->comment('신고 유형 (spam:스팸, inappropriate:부적절한내용, violence:폭력, nudity:나체, hate:혐오, copyright:저작권침해, other:기타)');
            $table->text('reason')->nullable()->comment('신고 사유');
            $table->string('status', 20)->default('pending')->comment('처리 상태 (pending:대기중, reviewed:검토완료, resolved:처리완료, rejected:거절됨)');
            $table->text('admin_note')->nullable()->comment('관리자 메모');
            $table->timestamp('reviewed_at')->nullable()->comment('검토일시');
            $table->timestamps();

            // 인덱스
            $table->index('portfolio_id', 'idx_portfolio_id');
            $table->index('user_id', 'idx_user_id');
            $table->index('status', 'idx_status');
            $table->index(['portfolio_id', 'user_id'], 'idx_portfolio_user');
            $table->unique(['portfolio_id', 'user_id'], 'uq_portfolio_user');
        });

        // 테이블 코멘트 추가
        DB::statement("ALTER TABLE portfolio_reports COMMENT = '포트폴리오 신고 테이블'");
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('portfolio_reports');
    }
};

