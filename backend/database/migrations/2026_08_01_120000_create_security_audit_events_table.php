<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** #1420: append-only, PII-minimized security evidence. */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('security_audit_events')) {
            return;
        }

        Schema::create('security_audit_events', function (Blueprint $table) {
            $table->id();
            $table->string('event_type', 96);
            $table->uuid('correlation_id');
            $table->timestamp('occurred_at')->useCurrent();
            $table->unsignedBigInteger('campus_id')->nullable();
            $table->char('actor_ref', 64)->nullable();
            $table->char('subject_ref', 64)->nullable();
            $table->char('binding_ref', 64)->nullable();
            $table->string('outcome', 32);
            $table->json('metadata')->nullable();
            $table->timestamp('retention_until')->nullable();
            $table->index(['event_type', 'occurred_at'], 'sae_type_occurred_idx');
            $table->index(['campus_id', 'occurred_at'], 'sae_campus_occurred_idx');
            $table->index('correlation_id', 'sae_correlation_idx');
            $table->index('retention_until', 'sae_retention_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('security_audit_events');
    }
};
