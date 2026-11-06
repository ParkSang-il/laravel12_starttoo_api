<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('support_messages', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->unsignedBigInteger('project_id');
            $table->unsignedBigInteger('account_id');
            $table->uuid('request_id');
            $table->char('payload_hash', 64);
            $table->string('message', 500);
            $table->boolean('anonymous');
            $table->timestamps();
            $table->unique(['project_id', 'account_id', 'request_id'], 'support_message_request_unique');
            $table->index(['project_id', 'created_at', 'id'], 'support_message_month_index');
            $table->foreign(['project_id', 'account_id'])->references(['project_id', 'id'])
                ->on('project_accounts')->restrictOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('support_messages');
    }
};
