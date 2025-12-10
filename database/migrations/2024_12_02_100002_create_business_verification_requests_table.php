<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('business_verification_requests', function (Blueprint $table) {
            $table->id()->comment('기본 키');
            $table->unsignedBigInteger('business_verification_id')->comment('사업자 인증 정보 ID (business_verifications.id)');
            $table->unsignedBigInteger('user_id')->comment('사용자 ID (users.id)');
            $table->string('business_name', 100)->comment('상호명 (샵 이름)');
            $table->string('business_number', 20)->nullable()->comment('사업자등록번호');
            $table->string('business_certificate', 255)->nullable()->comment('사업자등록증 파일 경로');
            $table->string('license_certificate', 255)->nullable()->comment('문신사 자격증 파일 경로');
            $table->string('safety_education_certificate', 255)->nullable()->comment('위생·안전 교육이수증 파일 경로');
            $table->string('address', 255)->nullable()->comment('샵 주소 (지번/도로명)');
            $table->string('address_detail', 255)->nullable()->comment('상세 주소');
            $table->boolean('contact_phone_public')->default(false)->comment('연락처 공개 여부 (users.phone 공개 여부)');
            $table->json('available_regions')->nullable()->comment('작업 가능 지역 (JSON)');
            $table->json('main_styles')->nullable()->comment('주요 스타일 (JSON)');
            $table->string('status', 20)->default('pending')->comment('승인 상태 (pending:대기중, approved:승인됨, rejected:거절됨)');
            $table->text('rejected_reason')->nullable()->comment('거절 사유');
            $table->timestamp('approved_at')->nullable()->comment('승인일시');
            $table->timestamps();

            // 인덱스
            $table->index('business_verification_id', 'idx_business_verification_id');
            $table->index('user_id', 'idx_user_id');
            $table->index('status', 'idx_status');
            $table->index('approved_at', 'idx_approved_at');
            $table->index('created_at', 'idx_created_at');
            $table->index('business_name', 'idx_business_name');
            $table->index('business_number', 'idx_business_number');
        });

        // 테이블 코멘트 추가
        DB::statement("ALTER TABLE business_verification_requests COMMENT = '사업자 정보 수정 요청 테이블 (관리자 승인 필요)'");
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('business_verification_requests');
    }
};

