<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Backfill: records where Paid=0 AND PayDate IS NOT NULL
 *
 * Root cause: StudentsList.vue togglePaymentStatus did not send paid_at: null
 * when switching to unpaid, leaving PayDate with a stale date while Paid was
 * set to 0. These 27 records are confirmed by the director to be genuinely
 * paid courses that were accidentally toggled to unpaid. Correct them by
 * setting Paid=1 (preserving the existing PayDate).
 *
 * down() is intentionally a no-op: we cannot safely reverse this because we
 * do not know which records were touched vs. which were legitimately Paid=0.
 */
return new class extends Migration
{
    public function up(): void
    {
        $affected = DB::table('StudentClass')
            ->whereNotNull('PayDate')
            ->where('Paid', 0)
            ->count();

        Log::info("[Backfill] paid_status_where_paydate_not_null: {$affected} records found, setting Paid=1");

        DB::table('StudentClass')
            ->whereNotNull('PayDate')
            ->where('Paid', 0)
            ->update(['Paid' => 1]);

        $remaining = DB::table('StudentClass')
            ->whereNotNull('PayDate')
            ->where('Paid', 0)
            ->count();

        Log::info("[Backfill] paid_status_where_paydate_not_null: done. Remaining inconsistent records: {$remaining}");
    }

    public function down(): void
    {
        // Intentional no-op: cannot safely reverse a data-correction backfill.
        // If rollback is needed, restore from DB backup taken before this migration.
        Log::warning('[Backfill] paid_status_where_paydate_not_null: down() called — no-op, manual restore required if needed.');
    }
};
