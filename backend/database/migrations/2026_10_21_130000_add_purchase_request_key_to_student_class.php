<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * #4349: stable identity of a purchase-batch request (sha256 hex of source course, requested start, sessions,
 * mode), so a retry is recognised after the batch's StartDate was shifted past the source lessons (in-app #422).
 * Additive only: nullable, no default, no backfill; legacy rows stay NULL and keep the exact-date matching.
 */
return new class extends Migration
{
    private const INDEX = 'idx_sc_student_purchase_request_key';

    public function up(): void
    {
        if (!Schema::hasTable('StudentClass')) {
            return;
        }
        // Column and index are guarded separately so a partially applied run can be retried.
        if (!Schema::hasColumn('StudentClass', 'purchase_request_key')) {
            Schema::table('StudentClass', function (Blueprint $table) {
                $table->char('purchase_request_key', 64)->nullable();
            });
        }
        if (!$this->hasIndex()) {
            Schema::table('StudentClass', function (Blueprint $table) {
                $table->index(['StudentID', 'purchase_request_key'], self::INDEX);
            });
        }
    }

    public function down(): void
    {
        if (!Schema::hasTable('StudentClass')) {
            return;
        }
        if ($this->hasIndex()) {
            Schema::table('StudentClass', function (Blueprint $table) {
                $table->dropIndex(self::INDEX);
            });
        }
        if (Schema::hasColumn('StudentClass', 'purchase_request_key')) {
            Schema::table('StudentClass', function (Blueprint $table) {
                $table->dropColumn('purchase_request_key');
            });
        }
    }

    private function hasIndex(): bool
    {
        return DB::select('SHOW INDEX FROM `StudentClass` WHERE Key_name = ?', [self::INDEX]) !== [];
    }
};
