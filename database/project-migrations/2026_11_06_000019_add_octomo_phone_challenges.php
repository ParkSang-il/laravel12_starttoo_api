<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('project_phone_challenges', function (Blueprint $t) {
            $t->string('provider', 20)->default('preview');
            $t->string('message_text', 160)->nullable();
            $t->timestamp('last_checked_at', 6)->nullable();
        });
    }
    public function down(): void
    {
        Schema::table('project_phone_challenges', fn (Blueprint $t) => $t->dropColumn(['provider', 'message_text', 'last_checked_at']));
    }
};
