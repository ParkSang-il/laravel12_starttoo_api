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
        Schema::create('user_login_logs', function (Blueprint $table) {
            $table->id()->comment('기본 키');
            $table->unsignedBigInteger('user_id')->nullable()->comment('사용자 ID (users.id)');
            $table->string('ip_address', 45)->nullable()->comment('IP 주소');
            $table->text('user_agent')->nullable()->comment('User Agent');
            $table->string('device_type', 50)->nullable()->comment('디바이스 타입 (mobile, tablet, desktop)');
            $table->string('device_model', 100)->nullable()->comment('디바이스 모델');
            $table->string('os', 50)->nullable()->comment('운영체제');
            $table->string('browser', 50)->nullable()->comment('브라우저');
            $table->string('login_type', 20)->default('phone')->comment('로그인 타입 (phone, email, social)');
            $table->boolean('is_success')->default(true)->comment('로그인 성공 여부');
            $table->string('failure_reason', 255)->nullable()->comment('로그인 실패 사유');
            $table->timestamp('created_at')->useCurrent()->comment('로그인 일시');

            $table->index('user_id', 'idx_user_id');
            $table->index('created_at', 'idx_created_at');
            $table->index(['user_id', 'created_at'], 'idx_user_created');
        });

        DB::statement("ALTER TABLE user_login_logs COMMENT = '사용자 로그인 기록 테이블'");
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('user_login_logs');
    }
};

