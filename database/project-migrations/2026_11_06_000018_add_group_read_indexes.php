<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    private function indexes(): array
    {
        return [
            ['group_memberships', 'group_memberships_account_active_idx', ['project_id', 'account_id', 'status', 'group_id']],
            ['group_memberships', 'group_memberships_group_active_idx', ['group_id', 'status', 'id']],
            // Covers message-side filters used by both unread queries, without the text body.
            ['group_messages', 'group_messages_unread_lookup_idx', ['project_id', 'group_id', 'deleted_at', 'id', 'author_id', 'created_at']],
        ];
    }

    public function up(): void
    {
        foreach ($this->indexes() as [$table, $name, $columns]) {
            // MySQL DDL is not transactional; allow retry after a partially applied run.
            if (!Schema::hasIndex($table, $name)) {
                Schema::table($table, fn (Blueprint $t) => $t->index($columns, $name));
            }
        }
    }

    public function down(): void
    {
        foreach (array_reverse($this->indexes()) as [$table, $name]) {
            if (Schema::hasIndex($table, $name)) {
                Schema::table($table, fn (Blueprint $t) => $t->dropIndex($name));
            }
        }
    }
};
