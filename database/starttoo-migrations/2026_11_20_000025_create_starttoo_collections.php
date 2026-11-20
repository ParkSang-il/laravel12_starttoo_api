<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('starttoo_collections', function (Blueprint $t) {
            $t->id()->comment('저장 컬렉션 번호');
            $t->foreignId('user_id')->comment('컬렉션 소유 회원')->constrained('starttoo_users')->cascadeOnDelete();
            $t->string('name', 40)->comment('컬렉션 이름: 1~20자');
            $t->string('visibility', 10)->default('private')->comment('공개 범위: public 공개, private 나만 보기');
            $t->timestamp('created_at')->useCurrent()->comment('생성 시각');
            $t->timestamp('updated_at')->useCurrent()->comment('최근 수정 시각');
            $t->unique(['user_id', 'name'], 'st_collection_name_unique');
            $t->index(['user_id', 'id']);
        });
        Schema::create('starttoo_collection_items', function (Blueprint $t) {
            $t->id()->comment('컬렉션 항목 번호');
            $t->foreignId('collection_id')->comment('저장 컬렉션 번호')->constrained('starttoo_collections')->cascadeOnDelete();
            $t->string('target_type', 10)->comment('artwork 작품·도안, post 일반 게시물');
            $t->uuid('target_id')->comment('저장 대상 UUID');
            $t->timestamp('created_at')->useCurrent()->comment('컬렉션에 담은 시각');
            $t->unique(['collection_id', 'target_type', 'target_id'], 'st_collection_item_unique');
            $t->index(['target_type', 'target_id'], 'st_collection_item_target');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('starttoo_collection_items');
        Schema::dropIfExists('starttoo_collections');
    }
};
