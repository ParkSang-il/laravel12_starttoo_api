<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('group_memberships', fn (Blueprint $t) => $t->unsignedBigInteger('chat_read_message_id')->nullable());
        Schema::table('group_messages', function (Blueprint $t) {
            $t->uuid('care_record_id')->nullable();
            $t->unique(['group_id', 'care_record_id'], 'group_message_care_unique');
            $t->foreign('care_record_id')->references('id')->on('group_care_records')->restrictOnDelete();
        });
    }
    public function down(): void
    {
        Schema::table('group_messages', function (Blueprint $t) {
            $t->dropForeign(['care_record_id']);
            $t->dropUnique('group_message_care_unique');
            $t->dropColumn('care_record_id');
        });
        Schema::table('group_memberships', fn (Blueprint $t) => $t->dropColumn('chat_read_message_id'));
    }
};
