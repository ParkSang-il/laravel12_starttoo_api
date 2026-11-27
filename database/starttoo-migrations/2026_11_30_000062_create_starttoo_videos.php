<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * 무빙(동영상 · 쇼츠) 1차 — 기획서 「STARTTOO 동영상(쇼츠) 기능 기획서」, docs/video.md.
 * 영상 1개 = videos 1행. 게시 정보(작품 · 도안 · 게시물 입력)는 payload 에 두었다가
 * 변환이 끝나 게시될 때 기존 작품 · 게시물을 만든다(포스터 = 첫 사진). 그 전에는 어디에도 안 보인다.
 */
return new class extends Migration {
    public function up(): void
    {
        Schema::create('starttoo_videos', function (Blueprint $t) {
            $t->uuid('id')->primary();
            $t->foreignId('user_id')->constrained('starttoo_users')->cascadeOnDelete();
            $t->uuid('request_key')->comment('회원별 중복 세션 방지');
            $t->string('target', 10)->comment('artwork 작품 · 도안 / post 게시물');
            $t->json('payload')->comment('게시할 때 쓰는 작품 · 게시물 입력(검증 끝난 값)');
            $t->string('status', 16)->default('uploading')
                ->comment('uploading · processing · ready · published · held · upload_failed · encode_failed · rejected');
            $t->boolean('auto_publish')->default(true);
            $t->string('source_key', 255)->comment('나눠 올린 원본 객체 키');
            $t->string('upload_id', 255)->nullable()->comment('S3 호환 멀티파트 업로드 id(완료 · 취소 후 null)');
            $t->unsignedSmallInteger('parts_total');
            $t->unsignedBigInteger('size_bytes');
            $t->char('sha256', 64)->comment('같은 파일 재업로드 막기');
            $t->unsignedInteger('duration_ms')->nullable();
            $t->unsignedSmallInteger('width')->nullable();
            $t->unsignedSmallInteger('height')->nullable();
            $t->unsignedInteger('cover_ms')->default(0)->comment('커버로 쓸 시점');
            $t->boolean('muted')->default(false);
            $t->string('hls_path', 255)->nullable()->comment('master.m3u8 객체 키');
            $t->string('poster_path', 255)->nullable()->comment('포스터 JPG 객체 키(작품 · 게시물 첫 사진과 같음)');
            $t->json('renditions')->nullable();
            $t->string('error_code', 40)->nullable();
            $t->unsignedTinyInteger('attempts')->default(0);
            $t->string('stage', 12)->nullable()->comment('변환 단계: probe · transcode · thumbnail · done');
            $t->string('published_type', 10)->nullable()->comment('게시 후 연결: artwork · post');
            $t->uuid('published_id')->nullable();
            $t->timestamp('uploaded_at')->nullable();
            $t->timestamp('processed_at')->nullable();
            $t->timestamp('published_at')->nullable();
            $t->timestamp('ready_notified_at')->nullable()->comment('ready 7일 알림');
            $t->timestamp('source_deleted_at')->nullable()->comment('원본 30일 보관 후 삭제');
            $t->timestamps();
            $t->unique(['user_id', 'request_key']);
            $t->index(['user_id', 'status', 'created_at']);
            $t->index(['published_type', 'published_id']);
            $t->index(['user_id', 'sha256']);
            $t->index(['status', 'updated_at']);
        });

        Schema::create('starttoo_video_events', function (Blueprint $t) {
            $t->id();
            $t->uuid('video_id');
            $t->string('status', 16);
            $t->json('detail')->nullable();
            $t->timestamp('created_at')->useCurrent();
            $t->index(['video_id', 'id']);
        });

        // 시청 기록: 하루 · 영상 · 회원 단위로 더한다(회원 없으면 user_id 0)
        Schema::create('starttoo_video_views', function (Blueprint $t) {
            $t->uuid('video_id');
            $t->unsignedBigInteger('user_id')->default(0);
            $t->date('day');
            $t->unsignedInteger('plays')->default(0);
            $t->unsignedInteger('watched_3s')->default(0);
            $t->unsignedInteger('completed')->default(0);
            $t->unsignedInteger('loops')->default(0);
            $t->unsignedBigInteger('watched_ms')->default(0);
            $t->unsignedInteger('cta_clicks')->default(0)->comment('상담 · 피팅 버튼');
            $t->primary(['video_id', 'user_id', 'day']);
            $t->index(['day', 'video_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('starttoo_video_views');
        Schema::dropIfExists('starttoo_video_events');
        Schema::dropIfExists('starttoo_videos');
    }
};
