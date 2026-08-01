<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * ADR-006 Phase 3B — session_coverages table (proposal).
 *
 * DO NOT run on production without Founder GO.
 * Empty table; no DEFAULT status backfill of historical ClassSession.
 * Coverage marking only — does not mutate RemainingSessions / ledger.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('session_coverages')) {
            return;
        }

        Schema::create('session_coverages', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('package_id')->nullable();
            $table->unsignedBigInteger('student_class_id');
            $table->unsignedBigInteger('class_session_id')->nullable();
            $table->unsignedBigInteger('campus_id')->nullable();
            $table->date('occurrence_date');
            $table->string('start_hm', 5);
            // Application must set: held | consumed | released (no DEFAULT → no silent backfill)
            $table->string('status', 16);
            $table->string('commitment_fingerprint', 64)->nullable();
            $table->timestamps();

            $table->unique(['student_class_id', 'occurrence_date', 'start_hm'], 'uniq_session_coverages_sc_occ');
            $table->index(['package_id', 'status'], 'idx_session_coverages_pkg_status');
            $table->index(['campus_id', 'occurrence_date'], 'idx_session_coverages_campus_date');
            $table->index('class_session_id', 'idx_session_coverages_session');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('session_coverages');
    }
};
