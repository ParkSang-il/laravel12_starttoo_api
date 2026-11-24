<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** P3-4 견적. 상담방마다 버전으로 쌓고, 새 버전을 내면 이전 버전은 대체(superseded) 처리한다. */
return new class extends Migration {
    public function up(): void
    {
        Schema::create('starttoo_quotes', function (Blueprint $t) {
            $t->id()->comment('견적 번호');
            $t->foreignId('consultation_id')->comment('상담방')->constrained('starttoo_consultations')->cascadeOnDelete();
            $t->unsignedInteger('version')->comment('상담방 안의 견적 버전(1부터)');
            $t->string('subject', 100)->comment('작업 대상: 블랙워크 뱀 · 팔뚝 안쪽 · 약 18 × 7cm');
            $t->json('items')->comment('금액 항목 [{label, amount}]');
            $t->unsignedInteger('total')->comment('총액(항목 합계, 원)');
            $t->unsignedInteger('deposit')->default(0)->comment('예약금(원, 총액 이하)');
            $t->string('duration', 40)->nullable()->comment('예상 소요: 약 5시간 · 1회');
            $t->string('includes', 300)->nullable()->comment('포함 사항');
            $t->string('memo', 500)->nullable()->comment('작가 메모');
            $t->timestamp('valid_until')->comment('유효기간(이 시각까지)');
            $t->timestamp('superseded_at')->nullable()->comment('새 버전이 나와 대체된 시각');
            $t->timestamp('created_at')->nullable();
            $t->unique(['consultation_id', 'version']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('starttoo_quotes');
    }
};
