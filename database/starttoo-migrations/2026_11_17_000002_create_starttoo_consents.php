<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('starttoo_consent_submissions', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignId('user_id')->constrained('starttoo_users')->cascadeOnDelete();
            $table->string('version', 50);
            $table->char('revision', 64);
            $table->uuid('request_key');
            $table->char('payload_hash', 64);
            $table->boolean('is_draft');
            $table->json('documents_snapshot');
            $table->json('response_payload');
            $table->timestamp('recorded_at');
            $table->unique(['user_id', 'version']);
            $table->unique(['user_id', 'request_key']);
        });
        Schema::create('starttoo_consent_records', function (Blueprint $table) {
            $table->id();
            $table->foreignUuid('submission_id')->constrained('starttoo_consent_submissions')->cascadeOnDelete();
            $table->string('document_id', 50);
            $table->string('document_version', 50);
            $table->char('content_sha256', 64);
            $table->boolean('required');
            $table->boolean('accepted');
            $table->timestamp('recorded_at');
            $table->unique(['submission_id', 'document_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('starttoo_consent_records');
        Schema::dropIfExists('starttoo_consent_submissions');
    }
};
