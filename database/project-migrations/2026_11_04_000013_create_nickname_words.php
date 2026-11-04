<?php

use Database\Seeders\NicknameWordSeeder;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('nickname_words', function (Blueprint $table) {
            $table->id();
            $table->string('type', 16);
            $table->string('word', 30);
            $table->boolean('is_active')->default(true);
            $table->timestamps();
            $table->unique(['type', 'word']);
            $table->index(['type', 'is_active']);
        });
        (new NicknameWordSeeder)->seedConnection(Schema::getConnection()->getName());
    }

    public function down(): void
    {
        Schema::dropIfExists('nickname_words');
    }
};
