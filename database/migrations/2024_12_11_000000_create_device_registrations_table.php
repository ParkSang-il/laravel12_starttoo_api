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
        Schema::create('device_registrations', function (Blueprint $table) {
            $table->id()->comment('기본 키');
            $table->string('device_id', 255)->unique()->comment('디바이스 고유 ID');
            $table->text('user_agent')->nullable()->comment('에이전트 정보 (User-Agent 헤더)');
            $table->unsignedBigInteger('user_id')->nullable()->comment('사용자 ID (회원가입 전에는 null)');
            $table->boolean('marketing_notification_consent')->default(false)->comment('마케팅 알림 수신동의');
            $table->boolean('service_notification_consent')->default(false)->comment('서비스 알림 수신동의');
            $table->timestamps();

            // 인덱스
            $table->index('device_id', 'idx_device_id');
            $table->index('user_id', 'idx_user_id');
            $table->index('created_at', 'idx_created_at');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('device_registrations');
    }
};

