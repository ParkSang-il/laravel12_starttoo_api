<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('starttoo_artwork_interactions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained('starttoo_users')->cascadeOnDelete();
            $table->foreignUuid('artwork_id')->constrained('starttoo_artworks')->cascadeOnDelete();
            $table->string('kind', 10)->comment('like 좋아요, save 저장');
            $table->timestamps();
            $table->unique(['user_id', 'artwork_id', 'kind'], 'starttoo_interaction_unique');
            $table->index(['artwork_id', 'kind']);
        });
        Schema::create('starttoo_interaction_requests', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained('starttoo_users')->cascadeOnDelete();
            $table->uuid('request_key');
            $table->char('payload_hash', 64);
            $table->json('response_payload');
            $table->timestamp('created_at')->useCurrent();
            $table->unique(['user_id', 'request_key']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('starttoo_interaction_requests');
        Schema::dropIfExists('starttoo_artwork_interactions');
    }
};
