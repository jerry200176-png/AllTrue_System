<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * #990 / PERF-16: index the schedules S.D.B. hot key (student_id, day_of_week, branch_id).
 *
 * This is the G-010 "S.D.B." lookup key — used by LINE-login campus/template resolution,
 * calendar template reads, and the #1062 forward-generation design. Production EXPLAIN
 * (2026-07-10) showed a full table scan (type=ALL, ~4.4k rows) for this predicate; the
 * existing idx_sched_course_date_time_status is keyed on student_course_id/date/time and
 * does not cover it. Additive, reversible, zero behavior change.
 */
return new class extends Migration
{
    private const INDEX_NAME = 'idx_sched_sdb';

    public function up(): void
    {
        if (!Schema::hasTable('schedules') || $this->indexExists()) {
            return;
        }

        Schema::table('schedules', function (Blueprint $table) {
            $table->index(['student_id', 'day_of_week', 'branch_id'], self::INDEX_NAME);
        });
    }

    public function down(): void
    {
        if (!Schema::hasTable('schedules') || !$this->indexExists()) {
            return;
        }

        Schema::table('schedules', function (Blueprint $table) {
            $table->dropIndex(self::INDEX_NAME);
        });
    }

    private function indexExists(): bool
    {
        return count(DB::select("SHOW INDEX FROM schedules WHERE Key_name = ?", [self::INDEX_NAME])) > 0;
    }
};
