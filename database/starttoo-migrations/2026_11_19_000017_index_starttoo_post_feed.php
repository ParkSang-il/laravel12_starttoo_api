<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('starttoo_posts', fn (Blueprint $table) => $table->index(['created_at', 'id'], 'starttoo_posts_feed_order_idx'));
    }

    public function down(): void
    {
        Schema::table('starttoo_posts', fn (Blueprint $table) => $table->dropIndex('starttoo_posts_feed_order_idx'));
    }
};
