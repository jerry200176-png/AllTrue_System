<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * PB-00: narrowly scoped, append-only observation table for parent bind/login attempts.
 * Does not alter business tables. Observation rows are not auth/binding truth.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('parent_binding_attempts')) {
            return;
        }

        Schema::create('parent_binding_attempts', function (Blueprint $table) {
            $table->id();
            $table->uuid('correlation_id');
            $table->timestamp('occurred_at')->useCurrent();
            $table->string('channel', 32); // line | parent_portal
            $table->string('method', 32);  // name | student_id | unknown
            $table->string('outcome', 16); // success | failure | noop
            $table->string('reason_code', 64)->nullable();
            $table->unsignedBigInteger('campus_id')->nullable();
            $table->unsignedBigInteger('student_id')->nullable();
            $table->string('phone_fingerprint', 64)->nullable();
            $table->unsignedInteger('candidate_count')->nullable();
            $table->unsignedInteger('phone_match_count')->nullable();

            $table->index('occurred_at', 'pba_occurred_at_idx');
            $table->index(['reason_code', 'occurred_at'], 'pba_reason_occurred_idx');
            $table->index(['campus_id', 'occurred_at'], 'pba_campus_occurred_idx');
            $table->index(['channel', 'occurred_at'], 'pba_channel_occurred_idx');
            $table->index('correlation_id', 'pba_correlation_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('parent_binding_attempts');
    }
};
