<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * TD-058 residual: ClassSessionController::buildClassSessionIndexQuery()'s
 * substitute-teacher leftJoin ON clause used to compare
 * SUBSTRING(cs.StartTime, 1, 5) against the derived sub_sched table —
 * function-wrapping cs.StartTime in the join predicate defeats index usage
 * on that column. The correlated per-row MAX(sub2.id) subquery itself was
 * already de-correlated into a derived-table join (TD-018 清償, 2026-06-01);
 * this only removes the leftover function-wrap on the outer join.
 *
 * A VIRTUAL generated column is used (not STORED): it is a metadata-only
 * ADD COLUMN with no table rewrite/backfill, unlike the STORED pattern used
 * in 2026_07_09_100000_add_unique_class_session_slot_index.php. No index is
 * added — sub_sched is a small derived table (substitute-schedule rows
 * only), so the join is efficient once neither side is function-wrapped;
 * add an index later if EXPLAIN shows it's needed.
 */
return new class extends Migration
{
    private const COLUMN = 'StartTimeHM';

    public function up(): void
    {
        if (!Schema::hasTable('ClassSession')) {
            return;
        }
        if (!Schema::hasColumn('ClassSession', self::COLUMN)) {
            DB::statement(
                'ALTER TABLE `ClassSession` ADD COLUMN `' . self::COLUMN . '` '
                . 'VARCHAR(5) GENERATED ALWAYS AS (SUBSTRING(`StartTime`, 1, 5)) VIRTUAL'
            );
        }
    }

    public function down(): void
    {
        if (!Schema::hasTable('ClassSession')) {
            return;
        }
        if (Schema::hasColumn('ClassSession', self::COLUMN)) {
            DB::statement('ALTER TABLE `ClassSession` DROP COLUMN `' . self::COLUMN . '`');
        }
    }
};
