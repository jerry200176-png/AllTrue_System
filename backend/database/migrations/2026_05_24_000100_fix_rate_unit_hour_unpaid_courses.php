<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Bug #129: Fix rate_unit='hour' on unpaid courses where rate was intended per-session.
 *
 * Scope:  Only courses where Paid=0 (no money has changed hands).
 *         IDs 182, 671, 673, 1054 (蔡羽絜 / 吳睿哲 at CampusID=9).
 *
 * Action: Set rate_unit='session', recalculate Charge = SessionCount × Rate.
 *         Paid courses (Paid=1) are intentionally excluded; director must review those manually.
 *
 * Rollback: Restore rate_unit='hour', Charge = SessionCount * Rate * avg_hours_per_session.
 *           Avg hours = 2 (all had duration_hours=2 when created).
 */
class FixRateUnitHourUnpaidCourses extends Migration
{
    public function up(): void
    {
        DB::table('StudentClass')
            ->whereIn('ID', [182, 671, 673, 1054])
            ->where('Paid', 0)
            ->where('rate_unit', 'hour')
            ->chunkById(100, function ($rows) {
                foreach ($rows as $row) {
                    $correctCharge = (int) round($row->Rate * $row->SessionCount);
                    DB::table('StudentClass')->where('ID', $row->ID)->update([
                        'rate_unit' => 'session',
                        'Charge'    => $correctCharge,
                    ]);
                }
            }, 'ID');
    }

    public function down(): void
    {
        // Restore with assumed 2h/session (original creation conditions)
        DB::table('StudentClass')
            ->whereIn('ID', [182, 671, 673, 1054])
            ->where('rate_unit', 'session')
            ->chunkById(100, function ($rows) {
                foreach ($rows as $row) {
                    $originalCharge = (int) round($row->Rate * $row->SessionCount * 2);
                    DB::table('StudentClass')->where('ID', $row->ID)->update([
                        'rate_unit' => 'hour',
                        'Charge'    => $originalCharge,
                    ]);
                }
            }, 'ID');
    }
}
