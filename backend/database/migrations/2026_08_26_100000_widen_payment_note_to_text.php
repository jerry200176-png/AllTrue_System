<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * #244 — PaymentReport.note accepts up to 500 characters, but the legacy
 * Payment.Note column was VARCHAR(255). Keep the full reconciliation context.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasTable('Payment') || !Schema::hasColumn('Payment', 'Note')) {
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
            [$schema, 'Payment', 'Note']
        );
        $type = strtolower((string) ($row->data_type ?? ''));
        if (in_array($type, ['text', 'mediumtext', 'longtext'], true)) {
            return;
        }

        DB::statement(
            'ALTER TABLE `Payment` MODIFY `Note` TEXT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NULL'
        );
    }

    public function down(): void
    {
        // Never shrink production payment notes: doing so would destroy audit context.
    }
};
