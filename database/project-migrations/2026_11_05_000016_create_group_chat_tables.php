<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('group_memberships', function (Blueprint $t) {
            $t->timestamp('chat_visible_from', 6)->nullable();
        });
        DB::connection(Schema::getConnection()->getName())->table('group_memberships')
            ->update(['chat_visible_from' => DB::raw('created_at')]);
        Schema::create('group_messages', function (Blueprint $t) {

            $t->bigIncrements('id');
            $t->unsignedBigInteger('project_id'); $t->uuid('group_id');
            $t->unsignedBigInteger('author_id'); $t->uuid('request_id');
            $t->char('payload_hash', 64); $t->string('kind', 20)->default('text');
            $t->text('body')->nullable(); $t->unsignedBigInteger('change_seq');
            $t->timestamp('edited_at', 6)->nullable(); $t->timestamp('deleted_at', 6)->nullable();
            $t->unsignedBigInteger('deleted_by')->nullable(); $t->timestamps(6);
            $t->unique(['group_id', 'author_id', 'request_id'], 'group_message_request_unique');
            $t->unique(['group_id', 'change_seq'], 'group_message_change_unique');
            $t->index(['group_id', 'id']);
            $t->foreign(['project_id', 'group_id'])->references(['project_id', 'id'])->on('care_groups')->restrictOnDelete();
            $t->foreign(['project_id', 'author_id'])->references(['project_id', 'id'])->on('project_accounts')->restrictOnDelete();
            $t->foreign(['project_id', 'deleted_by'])->references(['project_id', 'id'])->on('project_accounts')->restrictOnDelete();
        });
        Schema::create('group_message_revisions', function (Blueprint $t) {
            $t->bigIncrements('id'); $t->unsignedBigInteger('project_id'); $t->uuid('group_id');
            $t->unsignedBigInteger('message_id'); $t->string('action', 10);
            $t->unsignedBigInteger('actor_id'); $t->text('body'); $t->timestamp('created_at', 6);
            $t->foreign(['project_id', 'group_id'])->references(['project_id', 'id'])->on('care_groups')->restrictOnDelete();
            $t->foreign('message_id')->references('id')->on('group_messages')->restrictOnDelete();
            $t->foreign(['project_id', 'actor_id'])->references(['project_id', 'id'])->on('project_accounts')->restrictOnDelete();
            $t->index(['message_id', 'id']);
        });
        Schema::create('group_chat_role_policies', function (Blueprint $t) {
            $t->bigIncrements('id'); $t->unsignedBigInteger('project_id'); $t->uuid('group_id');
            $t->string('role', 20); $t->boolean('can_send'); $t->unsignedBigInteger('updated_by')->nullable(); $t->timestamps();
            $t->unique(['group_id', 'role']);
            $t->foreign(['project_id', 'group_id'])->references(['project_id', 'id'])->on('care_groups')->restrictOnDelete();
        });
        Schema::create('group_chat_member_overrides', function (Blueprint $t) {
            $t->bigIncrements('id'); $t->unsignedBigInteger('project_id'); $t->uuid('group_id');
            $t->unsignedBigInteger('account_id'); $t->string('mode', 10); $t->unsignedBigInteger('set_by'); $t->timestamps();
            $t->unique(['group_id', 'account_id']);
            $t->foreign(['project_id', 'group_id'])->references(['project_id', 'id'])->on('care_groups')->restrictOnDelete();
            $t->foreign(['project_id', 'account_id'])->references(['project_id', 'id'])->on('project_accounts')->restrictOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('group_chat_member_overrides');
        Schema::dropIfExists('group_chat_role_policies');
        Schema::dropIfExists('group_message_revisions');
        Schema::dropIfExists('group_messages');
        Schema::table('group_memberships', fn (Blueprint $t) => $t->dropColumn('chat_visible_from'));
    }
};
