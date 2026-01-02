<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('admin_login_logs', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('admin_id')->nullable()->comment('관리자 ID (admins.id)');
            $table->string('username', 100)->nullable()->comment('로그인 시도한 아이디');
            $table->string('ip_address', 45)->nullable()->comment('IP 주소');
            $table->string('action', 50)->comment('login, logout, password_mismatch');
            $table->boolean('is_success')->default(false)->comment('성공 여부');
            $table->string('failure_reason', 255)->nullable()->comment('실패 사유');
            $table->timestamps();

            $table->index('admin_id', 'idx_admin_login_logs_admin_id');
            $table->index('username', 'idx_admin_login_logs_username');
            $table->index('ip_address', 'idx_admin_login_logs_ip');
            $table->index('action', 'idx_admin_login_logs_action');
            $table->index('created_at', 'idx_admin_login_logs_created_at');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('admin_login_logs');
    }
};

