<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;

/**
 * TD-010: Add unique index on (RFID, CampusID) for Student table.
 *
 * Pre-condition: No duplicate (RFID, CampusID) pairs must exist.
 * This migration pre-checks and skips gracefully if duplicates are found,
 * logging a warning instead of failing hard.
 *
 * MySQL unique index naturally allows multiple NULL values (each NULL is
 * treated as distinct), so students without RFID cards are unaffected.
 */
return new class extends Migration
{
    public function up(): void
    {
        // Pre-check: abort gracefully if duplicate RFID+CampusID pairs exist
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

        // Skip if index already exists (idempotent)
        $existingIndexes = collect(DB::select("SHOW INDEX FROM `Student`"))
            ->pluck('Key_name');

        if ($existingIndexes->contains('students_rfid_campus_unique')) {
            return;
        }

        Schema::table('Student', function (Blueprint $table) {
            $table->unique(['RFID', 'CampusID'], 'students_rfid_campus_unique');
        });
    }

    public function down(): void
    {
        Schema::table('Student', function (Blueprint $table) {
            $table->dropUnique('students_rfid_campus_unique');
        });
    }
};
