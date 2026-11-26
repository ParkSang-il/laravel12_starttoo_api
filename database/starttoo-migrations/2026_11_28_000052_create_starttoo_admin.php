<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * 운영 콘솔(관리자 웹, docs/admin.md).
 * - admins: 앱 회원과 완전히 분리된 관리자 계정(이메일 · 비밀번호 · 인증 앱 2단계 인증 · 역할).
 * - admin_audit_logs: 콘솔의 모든 접속 · 처리 기록.
 * - settings: 콘솔에서 바꾸는 운영 정책 값(없으면 .env 기본값).
 * - user_sanctions: 회원 제재 이력(경고 · 기간 정지 · 영구 정지 · 해제).
 * - 콘텐츠 「삭제」는 복구 가능한 숨김: moderation_removed_at 을 남기고 moderation_hidden 을 켠다.
 */
return new class extends Migration {
    public function up(): void
    {
        Schema::create('starttoo_admins', function (Blueprint $t) {
            $t->id();
            $t->string('email', 191)->unique();
            $t->string('password', 255)->comment('bcrypt 해시');
            $t->string('name', 50)->comment('콘솔 · 감사 로그에 보이는 이름');
            $t->string('role', 20)->comment('super 최고 관리자 · operator 운영자 · cs CS 담당 · viewer 열람 전용');
            $t->text('otp_secret')->nullable()->comment('인증 앱 비밀값(암호화)');
            $t->unsignedTinyInteger('failed_attempts')->default(0);
            $t->timestamp('locked_until')->nullable()->comment('5회 실패 시 30분 잠금');
            $t->timestamp('last_login_at')->nullable();
            $t->string('last_login_ip', 45)->nullable();
            $t->timestamp('disabled_at')->nullable()->comment('계정 사용 중지');
            $t->timestamps();
        });

        Schema::create('starttoo_admin_audit_logs', function (Blueprint $t) {
            $t->id();
            $t->unsignedBigInteger('admin_id')->nullable()->comment('로그인 실패 등은 null');
            $t->string('admin_name', 50);
            $t->string('category', 10)->comment('접속 · 심사 · 제재 · 콘텐츠 · 설정');
            $t->string('action', 100);
            $t->string('target', 200)->default('-');
            $t->string('ip', 45)->nullable();
            $t->json('changes')->nullable()->comment('바뀐 값(비밀값은 넣지 않는다)');
            $t->timestamp('created_at')->useCurrent();
            $t->index(['category', 'created_at']);
            $t->index('created_at');
        });

        Schema::create('starttoo_settings', function (Blueprint $t) {
            $t->string('key', 50)->primary();
            $t->json('value');
            $t->unsignedBigInteger('updated_by')->nullable()->comment('바꾼 관리자');
            $t->timestamp('updated_at')->nullable();
        });

        Schema::create('starttoo_user_sanctions', function (Blueprint $t) {
            $t->id();
            $t->unsignedBigInteger('user_id')->index();
            $t->string('kind', 10)->comment('warning 경고 · suspend 기간 정지 · ban 영구 정지 · lift 해제');
            $t->unsignedSmallInteger('days')->nullable();
            $t->timestamp('until')->nullable()->comment('기간 정지 끝나는 시각');
            $t->string('reason', 1000)->nullable();
            $t->unsignedBigInteger('report_id')->nullable();
            $t->unsignedBigInteger('admin_id')->nullable();
            $t->timestamp('created_at')->useCurrent();
        });

        Schema::table('starttoo_users', function (Blueprint $t) {
            $t->timestamp('suspended_until')->nullable()->after('status')->comment('기간 정지 끝(영구 정지는 null + status suspended)');
        });

        foreach (['starttoo_artworks', 'starttoo_posts', 'starttoo_reviews', 'starttoo_community_posts'] as $table) {
            Schema::table($table, function (Blueprint $t) {
                $t->timestamp('moderation_removed_at')->nullable()->comment('콘솔 「삭제」(복구 가능한 숨김) 시각');
            });
        }

        // 신고 대상 'community_comment'(17자)가 12자 칸에 들어가지 않아 커뮤니티 댓글 신고가 실패하던 문제
        foreach (['starttoo_reports', 'starttoo_safety_audits'] as $table) {
            Schema::table($table, fn (Blueprint $t) => $t->string('target_type', 20)->change());
        }

        Schema::table('starttoo_artworks', function (Blueprint $t) {
            $t->boolean('fit_blocked')->default(false)->comment('가상 피팅 도안으로 쓰지 못하게(저작권 의심)');
            $t->boolean('recommend_excluded')->default(false)->comment('인기 · 추천 목록에서 뺌');
        });
    }

    public function down(): void
    {
        Schema::table('starttoo_artworks', fn (Blueprint $t) => $t->dropColumn(['fit_blocked', 'recommend_excluded']));
        foreach (['starttoo_artworks', 'starttoo_posts', 'starttoo_reviews', 'starttoo_community_posts'] as $table) {
            Schema::table($table, fn (Blueprint $t) => $t->dropColumn('moderation_removed_at'));
        }
        Schema::table('starttoo_users', fn (Blueprint $t) => $t->dropColumn('suspended_until'));
        Schema::dropIfExists('starttoo_user_sanctions');
        Schema::dropIfExists('starttoo_settings');
        Schema::dropIfExists('starttoo_admin_audit_logs');
        Schema::dropIfExists('starttoo_admins');
    }
};
