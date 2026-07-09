<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use App\Services\ClassSessionIntraDuplicateFinder;

/**
 * #957 D1: DB-level guard against duplicate active (StudentClassID, SessionDate, StartTime).
 *
 * Uniqueness is enforced for non-cancelled rows only: ActiveSlotFlag is a stored
 * generated column that is 1 for live rows and NULL for cancelled rows, and
 * MySQL/MariaDB unique indexes ignore NULLs. Cancelled reschedule placeholders
 * legitimately share a slot with the live row (Type B; ~789 groups in production
 * as of 2026-07-09) and must neither block index creation nor break future
 * cancel-then-rebook flows.
 *
 * Prerequisite: php artisan classsession:cleanup-intra-duplicates --execute --force
 * (production also requires ALLOW_PROD_REPAIR=1 until migration completes).
 */
return new class extends Migration
{
    private const INDEX_NAME = 'uq_class_session_slot';
    private const FLAG_COLUMN = 'ActiveSlotFlag';

    public function up(): void
    {
        if (!Schema::hasTable('ClassSession')) {
            return;
        }

        // CI / local test DB: skip index so existing PHPUnit fixtures stay valid.
        // Production deploy (APP_ENV=production) or explicit flag applies the index.
        // ClassSessionD1UniqueIndexTest sets APPLY_CLASS_SESSION_UNIQUE_INDEX=1.
        if (!app()->environment('production') && env('APPLY_CLASS_SESSION_UNIQUE_INDEX') !== '1') {
            return;
        }

        $finder = app(ClassSessionIntraDuplicateFinder::class);

        $activeGroups = $finder->findActiveDuplicateGroups();
        if (!empty($activeGroups)) {
            throw new RuntimeException(
                'Cannot add unique index: ' . count($activeGroups) . ' Type-A active duplicate group(s) remain. '
                . 'Run: php artisan classsession:cleanup-intra-duplicates --execute --force'
            );
        }

        if (!Schema::hasColumn('ClassSession', self::FLAG_COLUMN)) {
            DB::statement(
                'ALTER TABLE ClassSession ADD COLUMN ' . self::FLAG_COLUMN
                . " TINYINT GENERATED ALWAYS AS (CASE WHEN Status = 'cancelled' THEN NULL ELSE 1 END) STORED"
            );
        }

        Schema::table('ClassSession', function (Blueprint $table) {
            if ($this->indexExists()) {
                return;
            }
            $table->unique(['StudentClassID', 'SessionDate', 'StartTime', self::FLAG_COLUMN], self::INDEX_NAME);
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

        if (Schema::hasColumn('ClassSession', self::FLAG_COLUMN)) {
            DB::statement('ALTER TABLE ClassSession DROP COLUMN ' . self::FLAG_COLUMN);
        }
    }

    private function indexExists(): bool
    {
        $indexes = DB::select("SHOW INDEX FROM ClassSession WHERE Key_name = ?", [self::INDEX_NAME]);

        return count($indexes) > 0;
    }
};
