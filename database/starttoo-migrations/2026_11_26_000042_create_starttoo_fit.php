<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * 가상 타투 피팅.
 * - fit_models: STARTTOO 가상 모델 사진(나노바나나로 미리 생성). 부위 · 보이는 면 · 피부 톤 · 체형 · 포즈 · 조명.
 * - fit_results: AI 합성 결과(내 피팅 보관함). 내 신체 사진 원본은 합성 후 지우고 결과만 남긴다.
 */
return new class extends Migration {
    public function up(): void
    {
        Schema::create('starttoo_fit_models', function (Blueprint $t) {
            $t->id();
            $t->string('part', 20)->comment('부위: 전완 · 상완 …');
            $t->string('face', 20)->comment('보이는 면: 안쪽 · 바깥쪽 …');
            $t->unsignedTinyInteger('tone')->comment('피부 톤 0(밝은)~4(짙은)');
            $t->string('build', 10)->comment('체형: 슬림 · 보통 · 탄탄');
            $t->string('kind', 10)->comment('포즈: 정면 · 살짝 비튼 · 근접');
            $t->string('light', 10)->comment('조명: 자연광 · 스튜디오 · 실내');
            $t->string('image_path', 255)->nullable()->comment('생성된 사진 경로(생성 전 null)');
            $t->string('status', 12)->default('pending')->comment('pending 생성 대기 · ready · failed');
            $t->unsignedSmallInteger('sort')->default(0);
            $t->boolean('is_active')->default(true);
            $t->timestamps();
            $t->unique(['part', 'face', 'tone', 'build', 'kind', 'light'], 'starttoo_fit_models_combo');
            $t->index(['part', 'face', 'tone', 'build', 'status']);
        });

        Schema::create('starttoo_fit_results', function (Blueprint $t) {
            $t->uuid('id')->primary();
            $t->foreignId('user_id')->constrained('starttoo_users')->cascadeOnDelete();
            $t->uuid('request_key')->comment('중복 요청 방지');
            $t->uuid('artwork_id')->nullable()->comment('입힌 도안(작품). 직접 가져온 도안이면 null');
            $t->foreignId('model_id')->nullable()->comment('가상 모델로 피팅했으면 모델 번호');
            $t->string('source', 10)->comment('photo 내 사진 · virtual 가상 모델');
            $t->string('part', 20)->nullable();
            $t->string('face', 20)->nullable();
            $t->string('input_path', 255)->nullable()->comment('AI에 보낼 배치 이미지(임시, 처리 후 삭제)');
            $t->string('stencil_path', 255)->nullable()->comment('직접 가져온 도안(임시, 처리 후 삭제)');
            $t->string('before_path', 255)->nullable()->comment('전후 비교의 「전」: 도안 없는 사진(내 사진 축소본 · 가상 모델)');
            $t->string('result_path', 255)->nullable()->comment('AI 합성 결과');
            $t->string('status', 12)->default('processing')->comment('processing · ready · failed');
            $t->string('error', 40)->nullable();
            $t->timestamp('consent_at')->comment('AI 처리(사진 외부 전송) 동의 시각');
            $t->timestamp('attached_at')->nullable()->comment('상담에 첨부된 시각(첨부되면 파일을 상담과 함께 보존)');
            $t->timestamps();
            $t->unique(['user_id', 'request_key']);
            $t->index(['user_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('starttoo_fit_results');
        Schema::dropIfExists('starttoo_fit_models');
    }
};
