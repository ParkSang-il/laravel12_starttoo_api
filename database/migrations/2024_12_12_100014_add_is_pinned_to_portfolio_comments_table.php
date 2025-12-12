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
        Schema::table('portfolio_comments', function (Blueprint $table) {
            $table->boolean('is_pinned')->default(false)->after('is_deleted')->comment('상위 고정 여부');
            $table->index(['portfolio_id', 'is_pinned'], 'idx_portfolio_pinned');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('portfolio_comments', function (Blueprint $table) {
            $table->dropIndex('idx_portfolio_pinned');
            $table->dropColumn('is_pinned');
        });
    }
};

