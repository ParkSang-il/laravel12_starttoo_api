<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('user_follows', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('follower_id')->nullable(false)->comment('팔로우 하는 사용자 ID (users.id)');
            $table->unsignedBigInteger('followee_id')->nullable(false)->comment('팔로우 받는 사용자 ID (users.id)');
            $table->timestamps();

            $table->unique(['follower_id', 'followee_id'], 'uniq_follower_followee');
            $table->index('followee_id', 'idx_followee_id');
            $table->index('follower_id', 'idx_follower_id');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('user_follows');
    }
};

