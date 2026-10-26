<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
return new class extends Migration {
    public function up(): void {
        Schema::create('group_care_records', function(Blueprint $t) {
            $t->uuid('id')->primary(); $t->unsignedBigInteger('project_id'); $t->uuid('group_id'); $t->uuid('place_id');
            $t->unsignedBigInteger('created_by'); $t->uuid('request_id'); $t->string('payload_hash',64);
            $t->dateTime('fed_at'); $t->unsignedInteger('food_amount_gram')->nullable(); $t->unsignedInteger('cost')->default(0);
            $t->json('tags'); $t->json('sightings'); $t->string('memo',500)->default('');
            $t->decimal('latitude',10,7)->nullable(); $t->decimal('longitude',10,7)->nullable(); $t->float('gps_accuracy')->nullable(); $t->timestamps();
            $t->unique(['group_id','created_by','request_id'],'group_care_request_unique');
            $t->foreign(['project_id','group_id'])->references(['project_id','id'])->on('care_groups')->restrictOnDelete();
            $t->foreign(['group_id','place_id'])->references(['group_id','id'])->on('group_places')->restrictOnDelete();
            $t->foreign(['project_id','created_by'])->references(['project_id','id'])->on('project_accounts')->restrictOnDelete();
            $t->index(['place_id','fed_at','id']);
        });
    }
    public function down(): void { Schema::dropIfExists('group_care_records'); }
};
