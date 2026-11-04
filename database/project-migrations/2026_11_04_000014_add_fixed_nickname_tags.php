<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('project_accounts', function (Blueprint $table) {
            $table->unsignedBigInteger('nickname_tag')->nullable()->unique();
        });
        $connection = DB::connection(Schema::getConnection()->getName());
        $words = $connection->table('nickname_words')->where('is_active', true)->get()->groupBy('type');
        $adjectives = ($words['adjective'] ?? collect())->pluck('word')->all() ?: ['다정한'];
        $nouns = ($words['noun'] ?? collect())->pluck('word')->all() ?: ['밥지기'];
        $connection->table('project_accounts')->orderBy('id')->chunkById(200, function ($accounts) use ($connection, $adjectives, $nouns) {
            foreach ($accounts as $account) {
                $name = trim($account->display_name ?? '');
                $name = preg_replace('/#'.preg_quote((string) $account->id, '/').'$/u', '', $name);
                if ($name === '') $name = $adjectives[array_rand($adjectives)].' '.$nouns[array_rand($nouns)];
                $connection->table('project_accounts')->where('id', $account->id)->update([
                    'nickname_tag' => $account->id, 'display_name' => $name,
                ]);
            }
        });
    }

    public function down(): void
    {
        Schema::table('project_accounts', function (Blueprint $table) {
            $table->dropUnique(['nickname_tag']);
            $table->dropColumn('nickname_tag');
        });
    }
};
