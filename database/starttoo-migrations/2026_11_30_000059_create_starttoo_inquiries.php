<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * 1:1 문의(앱 MY 「1:1 문의」 · 운영 콘솔 「1:1 문의」).
 * 문의 한 건에 회원 질문 · 운영자 답변 · 추가 문의가 순서대로 쌓인다(starttoo_inquiry_messages).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('starttoo_inquiries', function (Blueprint $t) {
            $t->id();
            $t->unsignedBigInteger('user_id')->index();
            $t->string('category', 20)->comment('account · booking · payment · ad · report · bug · etc');
            $t->string('title', 100);
            $t->string('status', 12)->default('waiting')->comment('waiting 답변 대기 · answered 답변 완료');
            $t->timestamp('asked_at')->nullable()->comment('마지막 회원 질문 · 추가 문의 시각(콘솔 24시간 경과 표시)');
            $t->timestamp('answered_at')->nullable();
            $t->timestamps();
            $t->index(['status', 'asked_at']);
        });

        Schema::create('starttoo_inquiry_messages', function (Blueprint $t) {
            $t->id();
            $t->unsignedBigInteger('inquiry_id')->index();
            $t->string('author', 8)->comment('user 회원 · admin 운영자');
            $t->unsignedBigInteger('admin_id')->nullable();
            $t->text('body');
            $t->json('images')->nullable()->comment('사진 경로 목록(최대 3장, 회원 질문만)');
            $t->timestamp('edited_at')->nullable();
            $t->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('starttoo_inquiry_messages');
        Schema::dropIfExists('starttoo_inquiries');
    }
};
