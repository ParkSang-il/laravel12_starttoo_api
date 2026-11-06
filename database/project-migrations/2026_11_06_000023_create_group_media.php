<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
return new class extends Migration {
    public function up(): void {
        Schema::create('group_media', function (Blueprint $t) {
            $t->uuid('id')->primary(); $t->unsignedBigInteger('project_id'); $t->uuid('group_id');
            $t->unsignedBigInteger('uploader_id'); $t->uuid('upload_id'); $t->string('purpose', 20); $t->uuid('place_id')->nullable();
            $t->string('status', 20)->default('pending'); $t->unsignedBigInteger('message_id')->nullable();
            $t->uuid('care_record_id')->nullable(); $t->uuid('animal_id')->nullable(); $t->unsignedTinyInteger('position')->default(0);
            $t->string('disk', 20); $t->string('object_key', 255)->nullable(); $t->string('thumbnail_key', 255)->nullable();
            $t->string('mime', 30); $t->unsignedInteger('width'); $t->unsignedInteger('height'); $t->unsignedInteger('bytes'); $t->char('source_hash', 64);
            $t->timestamp('attached_at')->nullable(); $t->timestamp('deleted_at')->nullable(); $t->unsignedBigInteger('deleted_by')->nullable();
            $t->timestamp('purge_after')->nullable(); $t->timestamp('purged_at')->nullable(); $t->timestamps();
            $t->unique(['group_id', 'uploader_id', 'upload_id'], 'group_media_upload_unique');
            $t->index(['message_id', 'position']); $t->index(['care_record_id', 'position']);
            $t->index(['status', 'purge_after']); $t->index(['status', 'created_at']);
            $t->foreign(['project_id', 'group_id'])->references(['project_id', 'id'])->on('care_groups')->restrictOnDelete();
            $t->foreign(['project_id', 'uploader_id'])->references(['project_id', 'id'])->on('project_accounts')->restrictOnDelete();
            $t->foreign(['group_id', 'place_id'])->references(['group_id', 'id'])->on('group_places')->restrictOnDelete();
            $t->foreign(['group_id', 'animal_id'])->references(['group_id', 'id'])->on('group_animals')->restrictOnDelete();
            $t->foreign('message_id')->references('id')->on('group_messages')->restrictOnDelete();
            $t->foreign('care_record_id')->references('id')->on('group_care_records')->restrictOnDelete();
        });
        Schema::table('group_animals', function (Blueprint $t) {
            $t->uuid('photo_media_id')->nullable();
            $t->foreign('photo_media_id')->references('id')->on('group_media')->restrictOnDelete();
        });
    }
    public function down(): void {
        Schema::table('group_animals', function (Blueprint $t) { $t->dropForeign(['photo_media_id']); $t->dropColumn('photo_media_id'); });
        Schema::dropIfExists('group_media');
    }
};
