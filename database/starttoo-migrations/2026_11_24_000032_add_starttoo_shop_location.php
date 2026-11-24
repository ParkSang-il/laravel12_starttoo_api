<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('starttoo_shops', function (Blueprint $t) {
            $t->string('address', 200)->nullable()->after('region')->comment('상점 주소(도로명 우선). 본인과 예약 확정 고객에게만 공개');
            $t->string('address_detail', 100)->nullable()->after('address')->comment('상세 주소(층·호수). 본인과 예약 확정 고객에게만 공개');
            $t->string('area', 40)->nullable()->after('address_detail')->comment('공개 표시용 동 단위 지역: 마포구 합정동');
            $t->decimal('lat', 10, 7)->nullable()->after('area')->comment('정확한 위도(비공개)');
            $t->decimal('lng', 10, 7)->nullable()->after('lat')->comment('정확한 경도(비공개)');
            $t->decimal('map_lat', 10, 7)->nullable()->after('lng')->comment('공개 지도용 위도: 정확한 위치에서 150~300m 흐린 값');
            $t->decimal('map_lng', 10, 7)->nullable()->after('map_lat')->comment('공개 지도용 경도');
            $t->index(['map_lat', 'map_lng']);
        });
    }

    public function down(): void
    {
        Schema::table('starttoo_shops', function (Blueprint $t) {
            $t->dropIndex(['map_lat', 'map_lng']);
            $t->dropColumn(['address', 'address_detail', 'area', 'lat', 'lng', 'map_lat', 'map_lng']);
        });
    }
};
