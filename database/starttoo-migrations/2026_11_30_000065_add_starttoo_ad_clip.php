<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * 운영자 · 외부 광고의 무빙 피드 영상(자리 moving_inline): 사진 대신 광고주 영상 파일 1개.
 * 올리면 무빙과 같은 변환(HLS · 포스터)을 거쳐, 끝나기 전에는 노출하지 않는다(Ads::pickOwn).
 * (작가 광고가 자기 무빙을 고르는 video_id 와는 따로다)
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('starttoo_ads', function (Blueprint $t) {
            $t->string('clip_status', 12)->nullable()->comment('광고 영상 변환: processing · ready · failed (null = 사진 광고)');
            $t->string('clip_source', 255)->nullable()->comment('변환 전 원본(로컬 디스크, 변환 후 삭제)');
            $t->string('clip_hls_path', 255)->nullable()->comment('변환본 master.m3u8 객체 키');
            $t->unsignedInteger('clip_duration_ms')->nullable();
            $t->string('clip_error', 40)->nullable()->comment('변환 실패 이유(TOO_LONG · UNSUPPORTED …)');
        });
    }

    public function down(): void
    {
        Schema::table('starttoo_ads', function (Blueprint $t) {
            $t->dropColumn(['clip_status', 'clip_source', 'clip_hls_path', 'clip_duration_ms', 'clip_error']);
        });
    }
};
