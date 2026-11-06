<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
return new class extends Migration {
    public function up(): void {
        Schema::create('device_push_tokens', function (Blueprint $t) {
            $t->bigIncrements('id'); $t->unsignedBigInteger('project_id'); $t->unsignedBigInteger('account_id');
            $t->char('session_id', 64)->unique(); $t->string('platform', 10); $t->text('token');
            $t->char('token_hash', 64)->unique(); $t->timestamp('last_registered_at'); $t->timestamps();
            $t->foreign(['project_id', 'account_id'])->references(['project_id', 'id'])->on('project_accounts')->restrictOnDelete();
            $t->foreign('session_id')->references('id')->on('project_auth_sessions')->cascadeOnDelete();
        });
    }
    public function down(): void { Schema::dropIfExists('device_push_tokens'); }
};
