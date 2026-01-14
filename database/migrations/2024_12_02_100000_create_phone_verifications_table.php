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
        Schema::create('phone_verifications', function (Blueprint $table) {
            $table->id()->comment('기본 키');
            $table->string('phone', 20)->nullable(false)->comment('휴대폰 번호');
            $table->string('verification_code', 10)->nullable(false)->comment('인증번호');
            $table->timestamp('verified_at')->nullable()->comment('인증 완료일시');
            $table->timestamp('expires_at')->nullable(false)->comment('만료일시');
            $table->timestamps();

            // 개별 인덱스
            $table->index('phone', 'idx_phone');
            $table->index('verified_at', 'idx_verified_at');
            $table->index('expires_at', 'idx_expires_at');
        });

        // 테이블 코멘트 추가
        DB::statement("ALTER TABLE phone_verifications COMMENT = '휴대폰 인증 테이블'");
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('phone_verifications');
    }
};

