<?php

use App\Services\Starttoo\ArtworkHashtags;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('starttoo_artworks', function (Blueprint $table) {
            $table->json('tags')->nullable()->comment('설명에서 추출한 해시태그: # 제외, 소문자, 중복 제거');
        });
        $db = DB::connection($this->getConnection());
        $db->table('starttoo_artworks')->select('id', 'description')->chunkById(100, function ($rows) use ($db) {
            foreach ($rows as $row) {
                $db->table('starttoo_artworks')->where('id', $row->id)->update([
                    'tags' => json_encode(ArtworkHashtags::extract($row->description ?? ''), JSON_THROW_ON_ERROR),
                ]);
            }
        });
    }

    public function down(): void
    {
        Schema::table('starttoo_artworks', fn (Blueprint $table) => $table->dropColumn('tags'));
    }
};
