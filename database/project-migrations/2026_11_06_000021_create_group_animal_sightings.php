<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('group_animal_sightings', function (Blueprint $table) {
            $table->unsignedBigInteger('project_id');
            $table->uuid('group_id');
            $table->uuid('animal_id');
            $table->uuid('place_id');
            $table->uuid('record_id');
            $table->dateTime('fed_at');
            $table->string('status', 20);
            $table->primary(['record_id', 'animal_id']);
            $table->index(['project_id', 'group_id', 'animal_id', 'fed_at', 'record_id'], 'animal_sightings_history_index');
            $table->foreign('record_id')->references('id')->on('group_care_records')->cascadeOnDelete();
            $table->foreign(['group_id', 'animal_id'])->references(['group_id', 'id'])->on('group_animals')->restrictOnDelete();
            $table->foreign(['group_id', 'place_id'])->references(['group_id', 'id'])->on('group_places')->restrictOnDelete();
        });
        $db = DB::connection(Schema::getConnection()->getName());
        // Preserve old records; build the lookup index in bounded batches.
        $db->table('group_care_records')->select('id', 'project_id', 'group_id', 'place_id', 'fed_at', 'sightings')
            ->chunkById(100, function ($records) use ($db) {
                $rows = [];
                foreach ($records as $record) {
                    foreach (json_decode($record->sightings, true, 512, JSON_THROW_ON_ERROR) as $animal => $status) {
                        $rows[] = ['project_id' => $record->project_id, 'group_id' => $record->group_id,
                            'place_id' => $record->place_id, 'animal_id' => $animal, 'record_id' => $record->id,
                            'fed_at' => $record->fed_at, 'status' => $status];
                        if (count($rows) === 100) {
                            $db->table('group_animal_sightings')->upsert($rows, ['record_id', 'animal_id'], ['fed_at', 'status']);
                            $rows = [];
                        }
                    }
                }
                if ($rows !== []) $db->table('group_animal_sightings')->upsert($rows, ['record_id', 'animal_id'], ['fed_at', 'status']);
            });
    }

    public function down(): void
    {
        Schema::dropIfExists('group_animal_sightings');
    }
};
