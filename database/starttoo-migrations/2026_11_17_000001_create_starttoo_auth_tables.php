<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('starttoo_users', function (Blueprint $table) {
            $table->id()->comment('회원 고유 번호');
            $table->string('status', 20)->default('active')->comment('계정 상태: active 정상, suspended 이용 정지');
            $table->string('display_name', 100)->nullable()->comment('프로필 표시 이름');
            $table->string('terms_version', 50)->nullable()->comment('동의를 완료한 약관 묶음 버전');
            $table->timestamp('profile_completed_at')->nullable()->comment('최초 프로필 설정 완료 시각');
            $table->timestamp('created_at')->nullable()->comment('생성 시각');
            $table->timestamp('updated_at')->nullable()->comment('최종 수정 시각');
        });
        Schema::create('starttoo_auth_identities', function (Blueprint $table) {
            $table->id()->comment('소셜 계정 연결 고유 번호');
            $table->foreignId('user_id')->comment('연결된 STARTTOO 회원 번호')->constrained('starttoo_users')->cascadeOnDelete();
            $table->string('provider', 20)->comment('소셜 로그인 제공자 코드');
            $table->string('subject', 255)->comment('소셜 제공자가 발급한 고유 회원 식별자');
            $table->timestamp('created_at')->nullable()->comment('생성 시각');
            $table->timestamp('updated_at')->nullable()->comment('최종 수정 시각');
            $table->unique(['provider', 'subject']);
        });
        Schema::create('starttoo_auth_sessions', function (Blueprint $table) {
            $table->uuid('id')->primary()->comment('기기별 로그인 세션 UUID');
            $table->foreignId('user_id')->comment('로그인한 STARTTOO 회원 번호')->constrained('starttoo_users')->cascadeOnDelete();
            $table->char('access_hash', 64)->unique()->comment('액세스 토큰의 SHA-256 해시');
            $table->char('refresh_hash', 64)->unique()->comment('현재 갱신 토큰의 SHA-256 해시');
            $table->timestamp('access_expires_at')->comment('액세스 토큰 만료 시각');
            $table->timestamp('idle_expires_at')->comment('마지막 유효 활동 기준 세션 유휴 만료 시각');
            $table->timestamp('absolute_expires_at')->comment('최초 로그인 기준 세션 최대 만료 시각');
            $table->char('previous_refresh_hash', 64)->nullable()->index()->comment('직전 갱신 토큰의 SHA-256 해시');
            $table->char('previous_request_hash', 64)->nullable()->comment('직전 갱신 요청 키의 SHA-256 해시');
            $table->text('previous_result')->nullable()->comment('동일 갱신 요청의 응답 유실 복구용 암호화 응답');
            $table->timestamp('retry_until')->nullable()->comment('직전 갱신 요청에 같은 응답을 반환할 수 있는 기한');
            $table->timestamp('revoked_at')->nullable()->comment('기기 세션 폐기 시각');
            $table->timestamp('created_at')->nullable()->comment('생성 시각');
            $table->timestamp('updated_at')->nullable()->comment('최종 수정 시각');
        });
        Schema::create('starttoo_used_refresh_tokens', function (Blueprint $table) {
            $table->char('token_hash', 64)->primary()->comment('사용을 완료한 갱신 토큰의 SHA-256 해시');
            $table->foreignUuid('session_id')->comment('토큰 재사용 시 폐기할 로그인 세션 UUID')->constrained('starttoo_auth_sessions')->cascadeOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('starttoo_used_refresh_tokens');
        Schema::dropIfExists('starttoo_auth_sessions');
        Schema::dropIfExists('starttoo_auth_identities');
        Schema::dropIfExists('starttoo_users');
    }
};
