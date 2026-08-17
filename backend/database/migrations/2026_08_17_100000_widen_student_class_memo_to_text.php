<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * #1732 — StudentClass.Memo VARCHAR(512) overflowed when directors pasted
 * payment letters into course notes (SQLSTATE 22001 Data too long).
 *
 * Widen to TEXT + utf8mb4. Idempotent: skip if already TEXT/MEDIUMTEXT/LONGTEXT.
 * down() is a no-op so existing long rows are not truncated.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasTable('StudentClass') || !Schema::hasColumn('StudentClass', 'Memo')) {
            return;
        }
        if (DB::connection()->getDriverName() !== 'mysql') {
            return;
        }

        $schema = DB::getDatabaseName();
        $row = DB::selectOne(
            'SELECT DATA_TYPE AS data_type
             FROM information_schema.COLUMNS
             WHERE TABLE_SCHEMA = ? AND TABLE_NAME = ? AND COLUMN_NAME = ?',
            [$schema, 'StudentClass', 'Memo']
        );
        $type = strtolower((string) ($row->data_type ?? ''));
        if (in_array($type, ['text', 'mediumtext', 'longtext'], true)) {
            return;
        }

        DB::statement(
            'ALTER TABLE `StudentClass` MODIFY `Memo` TEXT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NULL'
        );
    }

    public function down(): void
    {
        // Shrinking TEXT back to VARCHAR(512) would truncate production notes.
    }
};
