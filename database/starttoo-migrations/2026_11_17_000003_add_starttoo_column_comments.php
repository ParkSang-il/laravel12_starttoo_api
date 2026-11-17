<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // 기존 테이블에도 설명을 반영하며 타입·널 허용·기본값·자동 증가 속성을 유지한다.
        // 인덱스와 외래 키는 다시 생성하지 않는다.
        Schema::table('starttoo_users', function (Blueprint $table) {
            $table->id()->comment('회원 고유 번호')->change();
            $table->string('status', 20)->default('active')->comment('계정 상태: active 정상, suspended 이용 정지')->change();
            $table->string('display_name', 100)->nullable()->comment('프로필 표시 이름')->change();
            $table->string('terms_version', 50)->nullable()->comment('동의를 완료한 약관 묶음 버전')->change();
            $table->timestamp('profile_completed_at')->nullable()->comment('최초 프로필 설정 완료 시각')->change();
            $table->timestamp('created_at')->nullable()->comment('생성 시각')->change();
            $table->timestamp('updated_at')->nullable()->comment('최종 수정 시각')->change();
        });
        Schema::table('starttoo_auth_identities', function (Blueprint $table) {
            $table->id()->comment('소셜 계정 연결 고유 번호')->change();
            $table->unsignedBigInteger('user_id')->comment('연결된 STARTTOO 회원 번호')->change();
            $table->string('provider', 20)->comment('소셜 로그인 제공자 코드')->change();
            $table->string('subject', 255)->comment('소셜 제공자가 발급한 고유 회원 식별자')->change();
            $table->timestamp('created_at')->nullable()->comment('생성 시각')->change();
            $table->timestamp('updated_at')->nullable()->comment('최종 수정 시각')->change();
        });
        Schema::table('starttoo_auth_sessions', function (Blueprint $table) {
            $table->uuid('id')->comment('기기별 로그인 세션 UUID')->change();
            $table->unsignedBigInteger('user_id')->comment('로그인한 STARTTOO 회원 번호')->change();
            $table->char('access_hash', 64)->comment('액세스 토큰의 SHA-256 해시')->change();
            $table->char('refresh_hash', 64)->comment('현재 갱신 토큰의 SHA-256 해시')->change();
            $table->timestamp('access_expires_at')->comment('액세스 토큰 만료 시각')->change();
            $table->timestamp('idle_expires_at')->comment('마지막 유효 활동 기준 세션 유휴 만료 시각')->change();
            $table->timestamp('absolute_expires_at')->comment('최초 로그인 기준 세션 최대 만료 시각')->change();
            $table->char('previous_refresh_hash', 64)->nullable()->comment('직전 갱신 토큰의 SHA-256 해시')->change();
            $table->char('previous_request_hash', 64)->nullable()->comment('직전 갱신 요청 키의 SHA-256 해시')->change();
            $table->text('previous_result')->nullable()->comment('동일 갱신 요청의 응답 유실 복구용 암호화 응답')->change();
            $table->timestamp('retry_until')->nullable()->comment('직전 갱신 요청에 같은 응답을 반환할 수 있는 기한')->change();
            $table->timestamp('revoked_at')->nullable()->comment('기기 세션 폐기 시각')->change();
            $table->timestamp('created_at')->nullable()->comment('생성 시각')->change();
            $table->timestamp('updated_at')->nullable()->comment('최종 수정 시각')->change();
        });
        Schema::table('starttoo_used_refresh_tokens', function (Blueprint $table) {
            $table->char('token_hash', 64)->comment('사용을 완료한 갱신 토큰의 SHA-256 해시')->change();
            $table->uuid('session_id')->comment('토큰 재사용 시 폐기할 로그인 세션 UUID')->change();
        });
        Schema::table('starttoo_consent_submissions', function (Blueprint $table) {
            $table->uuid('id')->comment('약관 동의 제출 UUID')->change();
            $table->unsignedBigInteger('user_id')->comment('동의를 제출한 STARTTOO 회원 번호')->change();
            $table->string('version', 50)->comment('동의한 약관 묶음 버전')->change();
            $table->char('revision', 64)->comment('약관 본문과 필수 여부를 포함한 묶음 SHA-256 해시')->change();
            $table->uuid('request_key')->comment('중복 제출 방지용 요청 UUID')->change();
            $table->char('payload_hash', 64)->comment('약관 버전과 선택 내용을 포함한 제출 본문 SHA-256 해시')->change();
            $table->boolean('is_draft')->comment('개발용 초안 여부: 1 초안, 0 정식 문안')->change();
            $table->json('documents_snapshot')->comment('동의 당시 문서 목록과 본문 JSON 스냅샷')->change();
            $table->json('response_payload')->comment('동일 제출 재시도에 반환할 가입 상태 응답 JSON')->change();
            $table->timestamp('recorded_at')->comment('서버에서 동의 제출을 기록한 시각')->change();
        });
        Schema::table('starttoo_consent_records', function (Blueprint $table) {
            $table->id()->comment('문서별 동의 기록 고유 번호')->change();
            $table->uuid('submission_id')->comment('소속 약관 동의 제출 UUID')->change();
            $table->string('document_id', 50)->comment('동의 문서 식별 코드')->change();
            $table->string('document_version', 50)->comment('동의 당시 문서 버전')->change();
            $table->char('content_sha256', 64)->comment('동의 당시 문서 본문의 SHA-256 해시')->change();
            $table->boolean('required')->comment('필수 동의 항목 여부: 1 필수, 0 선택')->change();
            $table->boolean('accepted')->comment('사용자 선택: 1 동의, 0 미동의')->change();
            $table->timestamp('recorded_at')->comment('서버에서 문서별 선택을 기록한 시각')->change();
        });
    }

    public function down(): void
    {
        // 데이터나 제약에는 영향이 없는 설명 추가이므로 롤백해도 설명은 유지한다.
    }
};
