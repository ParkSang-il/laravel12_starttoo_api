<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('starttoo_artworks', function (Blueprint $table) {
            $table->string('size_text', 50)->nullable()->comment('작품 크기 설명: 예 18 x 7cm');
            $table->string('visibility', 20)->default('public')->index()->comment('작성자 공개 상태: public 공개, hidden 숨김');
            $table->unsignedInteger('version')->default(1)->comment('동시 편집 충돌 검사용 버전');
        });
        Schema::create('starttoo_artwork_edits', function (Blueprint $table) {
            $table->id();
            $table->foreignUuid('artwork_id')->constrained('starttoo_artworks')->cascadeOnDelete();
            $table->foreignId('user_id')->constrained('starttoo_users')->cascadeOnDelete();
            $table->uuid('request_key');
            $table->char('payload_hash', 64);
            $table->timestamp('created_at')->useCurrent();
            $table->unique(['user_id', 'request_key']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('starttoo_artwork_edits');
        Schema::table('starttoo_artworks', function (Blueprint $table) {
            $table->dropIndex(['visibility']);
            $table->dropColumn(['size_text', 'visibility', 'version']);
        });
    }
};
