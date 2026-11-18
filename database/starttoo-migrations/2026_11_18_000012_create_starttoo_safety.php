<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('starttoo_users', fn (Blueprint $table) => $table->boolean('moderation_hidden')->default(false)->comment('운영자 공개 프로필 숨김'));
        Schema::table('starttoo_artworks', fn (Blueprint $table) => $table->boolean('moderation_hidden')->default(false)->comment('운영자 콘텐츠 숨김: 작성자 공개 설정과 별도'));
        Schema::create('starttoo_user_blocks', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained('starttoo_users')->cascadeOnDelete();
            $table->foreignId('blocked_user_id')->constrained('starttoo_users')->cascadeOnDelete();
            $table->timestamp('created_at')->useCurrent();
            $table->unique(['user_id', 'blocked_user_id']);
            $table->index(['blocked_user_id', 'user_id']);
        });
        Schema::create('starttoo_reports', function (Blueprint $table) {
            $table->id();
            $table->foreignId('reporter_id')->constrained('starttoo_users')->cascadeOnDelete();
            $table->string('target_type', 12)->comment('user 또는 artwork');
            $table->string('target_id', 36);
            $table->foreignId('subject_user_id')->constrained('starttoo_users')->cascadeOnDelete();
            $table->string('reason', 24);
            $table->text('details')->nullable();
            $table->json('snapshot')->comment('접수 시 대상의 최소 정보');
            $table->string('status', 16)->default('pending')->index();
            $table->char('open_key', 64)->nullable()->unique()->comment('같은 회원/대상의 처리 전 중복 신고 방지');
            $table->timestamps();
            $table->index(['target_type', 'target_id']);
        });
        Schema::create('starttoo_safety_requests', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained('starttoo_users')->cascadeOnDelete();
            $table->uuid('request_key');
            $table->char('payload_hash', 64);
            $table->json('response_payload');
            $table->timestamp('created_at')->useCurrent();
            $table->unique(['user_id', 'request_key']);
        });
        Schema::create('starttoo_safety_audits', function (Blueprint $table) {
            $table->id();
            $table->string('actor_type', 12)->comment('member 또는 operator');
            $table->unsignedBigInteger('actor_user_id')->nullable();
            $table->string('operator', 100)->nullable()->comment('서버 운영자가 지정한 작업자 식별자');
            $table->unsignedBigInteger('report_id')->nullable()->index();
            $table->string('target_type', 12);
            $table->string('target_id', 36);
            $table->string('action', 24);
            $table->text('reason')->nullable();
            $table->json('changes')->nullable();
            $table->timestamp('created_at')->useCurrent();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('starttoo_safety_audits');
        Schema::dropIfExists('starttoo_safety_requests');
        Schema::dropIfExists('starttoo_reports');
        Schema::dropIfExists('starttoo_user_blocks');
        Schema::table('starttoo_artworks', fn (Blueprint $table) => $table->dropColumn('moderation_hidden'));
        Schema::table('starttoo_users', fn (Blueprint $table) => $table->dropColumn('moderation_hidden'));
    }
};
