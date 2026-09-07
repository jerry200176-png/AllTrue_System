<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('bug_report_evidence', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('bug_report_id');
            $table->string('evidence_type', 80);
            $table->string('production_revision', 40)->nullable();
            $table->string('deploy_run_id', 64)->nullable();
            $table->string('source_ref', 255)->nullable();
            $table->unsignedBigInteger('verified_by');
            $table->timestamp('verified_at');
            $table->json('metadata')->nullable();
            $table->timestamp('created_at')->useCurrent();

            $table->index(['bug_report_id', 'evidence_type', 'verified_at'], 'bug_evidence_bug_type_verified');
            $table->index(['evidence_type', 'production_revision'], 'bug_evidence_type_revision');
            // Backfill manifests always provide source_ref, making retries safe
            // without adding a second idempotency column to the minimal model.
            $table->unique(['bug_report_id', 'evidence_type', 'source_ref'], 'bug_evidence_idempotency');

            // Evidence must outlive a bug row deletion attempt; no cascade keeps
            // the append-only audit trail from being silently removed.
            $table->foreign('bug_report_id')->references('id')->on('bug_reports');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('bug_report_evidence');
    }
};
