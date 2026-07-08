<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * #957 D1: DB-level guard against duplicate (StudentClassID, SessionDate, StartTime).
 *
 * Prerequisite: php artisan classsession:cleanup-intra-duplicates --execute --force
 * (production also requires ALLOW_PROD_REPAIR=1 until migration completes).
 */
return new class extends Migration
{
    private const INDEX_NAME = 'uq_class_session_slot';

    public function up(): void
    {
        if (!Schema::hasTable('ClassSession')) {
            return;
        }

        $duplicateGroups = DB::table('ClassSession')
            ->selectRaw('StudentClassID, DATE(SessionDate) as session_date, SUBSTRING(StartTime, 1, 5) as start_time, COUNT(*) as row_count')
            ->groupBy('StudentClassID', DB::raw('DATE(SessionDate)'), DB::raw('SUBSTRING(StartTime, 1, 5)'))
            ->having('row_count', '>', 1)
            ->get();

        if ($duplicateGroups->isNotEmpty()) {
            throw new RuntimeException(
                'Cannot add unique index: ' . $duplicateGroups->count() . ' intra-course duplicate slot group(s) remain. '
                . 'Run: php artisan classsession:cleanup-intra-duplicates --execute --force'
            );
        }

        Schema::table('ClassSession', function (Blueprint $table) {
            if ($this->indexExists()) {
                return;
            }
            $table->unique(['StudentClassID', 'SessionDate', 'StartTime'], self::INDEX_NAME);
        });
    }

    public function down(): void
    {
        if (!Schema::hasTable('ClassSession')) {
            return;
        }

        Schema::table('ClassSession', function (Blueprint $table) {
            if ($this->indexExists()) {
                $table->dropUnique(self::INDEX_NAME);
            }
        });
    }

    private function indexExists(): bool
    {
        $indexes = DB::select("SHOW INDEX FROM ClassSession WHERE Key_name = ?", [self::INDEX_NAME]);

        return count($indexes) > 0;
    }
};
