<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('starttoo_consent_submissions', function (Blueprint $table) {
            $table->uuid('id')->primary()->comment('약관 동의 제출 UUID');
            $table->foreignId('user_id')->comment('동의를 제출한 STARTTOO 회원 번호')->constrained('starttoo_users')->cascadeOnDelete();
            $table->string('version', 50)->comment('동의한 약관 묶음 버전');
            $table->char('revision', 64)->comment('약관 본문과 필수 여부를 포함한 묶음 SHA-256 해시');
            $table->uuid('request_key')->comment('중복 제출 방지용 요청 UUID');
            $table->char('payload_hash', 64)->comment('약관 버전과 선택 내용을 포함한 제출 본문 SHA-256 해시');
            $table->boolean('is_draft')->comment('개발용 초안 여부: 1 초안, 0 정식 문안');
            $table->json('documents_snapshot')->comment('동의 당시 문서 목록과 본문 JSON 스냅샷');
            $table->json('response_payload')->comment('동일 제출 재시도에 반환할 가입 상태 응답 JSON');
            $table->timestamp('recorded_at')->comment('서버에서 동의 제출을 기록한 시각');
            $table->unique(['user_id', 'version']);
            $table->unique(['user_id', 'request_key']);
        });
        Schema::create('starttoo_consent_records', function (Blueprint $table) {
            $table->id()->comment('문서별 동의 기록 고유 번호');
            $table->foreignUuid('submission_id')->comment('소속 약관 동의 제출 UUID')->constrained('starttoo_consent_submissions')->cascadeOnDelete();
            $table->string('document_id', 50)->comment('동의 문서 식별 코드');
            $table->string('document_version', 50)->comment('동의 당시 문서 버전');
            $table->char('content_sha256', 64)->comment('동의 당시 문서 본문의 SHA-256 해시');
            $table->boolean('required')->comment('필수 동의 항목 여부: 1 필수, 0 선택');
            $table->boolean('accepted')->comment('사용자 선택: 1 동의, 0 미동의');
            $table->timestamp('recorded_at')->comment('서버에서 문서별 선택을 기록한 시각');
            $table->unique(['submission_id', 'document_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('starttoo_consent_records');
        Schema::dropIfExists('starttoo_consent_submissions');
    }
};
