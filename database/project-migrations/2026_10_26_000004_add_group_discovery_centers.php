<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('care_groups', function (Blueprint $table) {
            // Public activity-area reference, never copied from group_places.
            $table->decimal('discovery_latitude', 5, 2)->nullable();
            $table->decimal('discovery_longitude', 5, 2)->nullable();
            $table->index(['project_id', 'is_discoverable', 'accepting_requests', 'discovery_latitude'], 'care_groups_discovery_index');
        });
    }

    public function down(): void
    {
        Schema::table('care_groups', function (Blueprint $table) {
            $table->dropIndex('care_groups_discovery_index');
            $table->dropColumn(['discovery_latitude', 'discovery_longitude']);
        });
    }
};
