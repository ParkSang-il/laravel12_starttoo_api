<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        // 2-15 아티스트 상점 정보. 아티스트 승인 시 신청서 내용으로 만든다.
        Schema::create('starttoo_shops', function (Blueprint $t) {
            $t->foreignId('user_id')->primary()->comment('상점 주인(아티스트) 회원 번호')->constrained('starttoo_users')->cascadeOnDelete();
            $t->string('activity_name', 40)->comment('활동명');
            $t->string('studio_name', 60)->nullable()->comment('스튜디오 이름');
            $t->string('region', 40)->comment('활동 지역 코드');
            $t->string('bio', 600)->default('')->comment('상점 소개');
            $t->unsignedInteger('price_min')->nullable()->comment('최소 가격(원)');
            $t->unsignedInteger('price_max')->nullable()->comment('최대 가격(원)');
            $t->string('consult_status', 12)->default('open')->comment('상담·예약 상태: open 상담 가능, closed 예약 마감, holiday 휴무');
            $t->string('notice', 120)->nullable()->comment('상점 상단 고정 공지');
            $t->string('cover_path', 255)->nullable()->comment('커버 사진 경로');
            $t->unsignedInteger('version')->default(1)->comment('동시 편집 충돌 검사용 버전');
            $t->timestamps();
        });
        Schema::table('starttoo_artworks', function (Blueprint $t) {
            $t->string('design_status', 12)->default('available')
                ->comment('도안 상태: available 예약 가능, consulting 상담 중, done 작업 완료(1회 한정 도안 판매 종료)');
        });
    }

    public function down(): void
    {
        Schema::table('starttoo_artworks', fn (Blueprint $t) => $t->dropColumn('design_status'));
        Schema::dropIfExists('starttoo_shops');
    }
};
