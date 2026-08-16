<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * #934: courses converting count<->date billing mode left stale invoices/
 * receipts that still looked valid (parent screenshotted an old receipt
 * after the director had already re-registered under the new mode).
 *
 * Purely additive, no backfill: snapshots the StudentClass's ScheduleMode
 * at the moment each Invoice is created. NULL on every pre-existing row —
 * "unknown, don't compare" is the safe default; no financial figure changes.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('Invoice', function (Blueprint $table) {
            if (!Schema::hasColumn('Invoice', 'ScheduleModeAtIssue')) {
                $table->string('ScheduleModeAtIssue', 16)->nullable()->after('Status');
            }
        });
    }

    public function down(): void
    {
        Schema::table('Invoice', function (Blueprint $table) {
            if (Schema::hasColumn('Invoice', 'ScheduleModeAtIssue')) {
                $table->dropColumn('ScheduleModeAtIssue');
            }
        });
    }
};
