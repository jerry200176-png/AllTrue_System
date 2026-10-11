<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Occurrence identity P1 (ARCH-OCCURRENCE-IDENTITY-20261011, RFC_SCHEDULE_OCCURRENCE_IDENTITY §9):
 * additive nullable frozen original slot on ClassSession. No backfill (P3), no index (P6), no readers.
 * Rollback: down() drops both columns (nothing reads them).
 */
return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasTable('ClassSession')) {
            return;
        }
        Schema::table('ClassSession', function (Blueprint $table) {
            if (!Schema::hasColumn('ClassSession', 'OriginalSessionDate')) {
                $table->date('OriginalSessionDate')->nullable();
            }
            if (!Schema::hasColumn('ClassSession', 'OriginalStartTimeHM')) {
                $table->char('OriginalStartTimeHM', 5)->nullable();
            }
        });
    }

    public function down(): void
    {
        if (!Schema::hasTable('ClassSession')) {
            return;
        }
        Schema::table('ClassSession', function (Blueprint $table) {
            foreach (['OriginalStartTimeHM', 'OriginalSessionDate'] as $col) {
                if (Schema::hasColumn('ClassSession', $col)) {
                    $table->dropColumn($col);
                }
            }
        });
    }
};
