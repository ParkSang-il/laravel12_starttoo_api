<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        // 휴대폰 본인인증 결과. 실명·전화번호는 암호화해 보관하고 심사에만 사용한다.
        Schema::create('starttoo_identity_verifications', function (Blueprint $t) {
            $t->id()->comment('본인인증 번호');
            $t->foreignId('user_id')->unique()->comment('인증한 회원(회원당 최신 1건)')->constrained('starttoo_users')->cascadeOnDelete();
            $t->string('provider', 20)->comment('본인인증 제공자 코드');
            $t->char('person_hash', 64)->nullable()->index()->comment('제공자 고유 개인식별값(CI)의 해시: 원문 미보관');
            $t->text('name_enc')->comment('실명(암호화)');
            $t->text('phone_enc')->nullable()->comment('휴대폰 번호(암호화)');
            $t->text('birth_enc')->nullable()->comment('생년월일(암호화)');
            $t->timestamp('verified_at')->comment('인증 완료 시각');
            $t->timestamp('created_at')->useCurrent()->comment('기록 시각');
        });
        // 아티스트 전환 신청. 반려·보완 요청 후 같은 신청서를 고쳐 다시 제출한다.
        Schema::create('starttoo_artist_applications', function (Blueprint $t) {
            $t->id()->comment('아티스트 신청 번호');
            $t->foreignId('user_id')->unique()->comment('신청 회원(회원당 1건)')->constrained('starttoo_users')->cascadeOnDelete();
            $t->string('status', 20)->index()->comment('submitted 접수, reviewing 심사 중, needs_changes 보완 요청, approved 승인, rejected 반려');
            $t->string('activity_name', 40)->comment('활동명');
            $t->string('region', 40)->comment('활동 지역 코드');
            $t->string('bio', 600)->comment('소개');
            $t->string('portfolio_link', 300)->nullable()->comment('포트폴리오 링크');
            $t->json('portfolio_paths')->comment('포트폴리오 사진 경로 목록(3~12장)');
            $t->text('license_number_enc')->nullable()->comment('면허 번호(암호화): 제도 시행 후');
            $t->string('license_path', 255)->nullable()->comment('자격증 사진 경로: 제도 시행 후');
            $t->text('review_note')->nullable()->comment('최근 심사 사유(보완 요청·반려)');
            $t->unsignedInteger('version')->default(1)->comment('제출 횟수');
            $t->timestamp('submitted_at')->comment('최근 제출 시각');
            $t->timestamp('reviewed_at')->nullable()->comment('최근 심사 결정 시각');
            $t->timestamps();
        });
        Schema::create('starttoo_artist_application_events', function (Blueprint $t) {
            $t->id()->comment('심사 이력 번호');
            $t->foreignId('application_id')->comment('아티스트 신청 번호')->constrained('starttoo_artist_applications')->cascadeOnDelete();
            $t->string('status', 20)->comment('바뀐 상태');
            $t->text('note')->nullable()->comment('사유·메모');
            $t->string('actor', 60)->comment('member 신청자, operator:{이름} 운영자');
            $t->unsignedInteger('version')->comment('해당 제출 회차');
            $t->timestamp('created_at')->useCurrent()->comment('기록 시각');
            $t->index(['application_id', 'id']);
        });
        Schema::table('starttoo_users', function (Blueprint $t) {
            $t->string('artist_status', 20)->nullable()->comment('아티스트 상태: approved 승인(상점 개설 가능), null 일반 회원');
            $t->timestamp('artist_approved_at')->nullable()->comment('아티스트 승인 시각');
        });
    }

    public function down(): void
    {
        Schema::table('starttoo_users', fn (Blueprint $t) => $t->dropColumn(['artist_status', 'artist_approved_at']));
        Schema::dropIfExists('starttoo_artist_application_events');
        Schema::dropIfExists('starttoo_artist_applications');
        Schema::dropIfExists('starttoo_identity_verifications');
    }
};
