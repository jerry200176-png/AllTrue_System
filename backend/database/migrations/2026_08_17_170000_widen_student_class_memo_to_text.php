<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * #1732 — StudentClass.Memo VARCHAR(512) truncates pasted tuition breakdowns
 * (Sentry PHP-LARAVEL-2B, SQLSTATE 1406). Widen to TEXT. SQLite tests skip ALTER.
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

        DB::statement('ALTER TABLE `StudentClass` MODIFY `Memo` TEXT NULL');
    }

    public function down(): void
    {
        if (!Schema::hasTable('StudentClass') || !Schema::hasColumn('StudentClass', 'Memo')) {
            return;
        }
        if (DB::connection()->getDriverName() !== 'mysql') {
            return;
        }

        DB::statement('ALTER TABLE `StudentClass` MODIFY `Memo` VARCHAR(512) NULL');
    }
};
