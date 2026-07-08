<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use App\Services\ClassSessionIntraDuplicateFinder;

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

        $blockingSlots = $finder->findUniqueIndexBlockingSlots();
        if (!empty($blockingSlots)) {
            $placeholderGroups = $finder->findCancelledPlaceholderCollisions();
            throw new RuntimeException(
                'Cannot add unique index: ' . count($blockingSlots) . ' slot(s) still have multiple rows '
                . '(' . count($placeholderGroups) . ' cancelled-placeholder collisions). '
                . 'Type-A cleanup complete; placeholder PCR required before migration.'
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
