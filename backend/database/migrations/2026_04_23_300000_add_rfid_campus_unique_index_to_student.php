<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;

/**
 * TD-010: Replace single-column RFID unique index with composite (RFID, CampusID).
 *
 * Upgrade path:
 *   Old: student_rfid_unique  → unique on RFID only (blocks cross-campus same card)
 *   New: students_rfid_campus_unique → unique on (RFID, CampusID)
 *        Same card allowed in different campuses; blocked only within same campus.
 *
 * Pre-condition: No duplicate (RFID, CampusID) pairs must exist.
 * This migration pre-checks and aborts gracefully if duplicates are found.
 *
 * MySQL unique index naturally allows multiple NULL values (each NULL treated
 * as distinct), so students without RFID cards are unaffected.
 */
return new class extends Migration
{
    public function up(): void
    {
        // Pre-check: abort gracefully if duplicate (RFID, CampusID) pairs exist
        $duplicates = DB::table('Student')
            ->select('RFID', 'CampusID', DB::raw('COUNT(*) as cnt'))
            ->whereNotNull('RFID')
            ->where('RFID', '!=', '')
            ->groupBy('RFID', 'CampusID')
            ->having('cnt', '>', 1)
            ->get();

        if ($duplicates->isNotEmpty()) {
            Log::warning('rfid_unique_index_skipped_due_to_duplicates', [
                'duplicates' => $duplicates->toArray(),
                'action'     => 'Please resolve duplicate RFIDs manually, then re-run migration',
            ]);
            return;
        }

        $driver        = DB::getDriverName();
        $existingNames = collect(DB::select("SHOW INDEX FROM `Student`"))->pluck('Key_name');

        // Drop old single-column RFID unique index if it exists
        if ($existingNames->contains('student_rfid_unique')) {
            if ($driver === 'mysql') {
                DB::statement('ALTER TABLE `Student` DROP INDEX `student_rfid_unique`');
            }
        }

        // Add composite unique index (idempotent)
        if (!$existingNames->contains('students_rfid_campus_unique')) {
            Schema::table('Student', function (Blueprint $table) {
                $table->unique(['RFID', 'CampusID'], 'students_rfid_campus_unique');
            });
        }
    }

    public function down(): void
    {
        $driver        = DB::getDriverName();
        $existingNames = collect(DB::select("SHOW INDEX FROM `Student`"))->pluck('Key_name');

        // Drop composite index
        if ($existingNames->contains('students_rfid_campus_unique')) {
            Schema::table('Student', function (Blueprint $table) {
                $table->dropUnique('students_rfid_campus_unique');
            });
        }

        // Restore old single-column index
        if (!$existingNames->contains('student_rfid_unique')) {
            if ($driver === 'mysql') {
                try {
                    DB::statement('CREATE UNIQUE INDEX `student_rfid_unique` ON `Student` (`RFID`)');
                } catch (\Throwable $e) { /* index may already exist */ }
            }
        }
    }
};
