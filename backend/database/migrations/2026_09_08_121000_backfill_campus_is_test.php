<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasTable('Campus') || !Schema::hasColumn('Campus', 'is_test')) {
            return;
        }

        DB::table('Campus')
            ->whereNull('is_test')
            ->orderBy('id')
            ->chunkById(500, function ($campuses): void {
                DB::table('Campus')
                    ->whereIn('id', $campuses->pluck('id')->all())
                    ->update(['is_test' => false]);
            });

        // Doctrine DBAL is intentionally not a production dependency in this
        // application. Use the database-native MySQL type change after the
        // explicit chunked backfill above.
        if (DB::connection()->getDriverName() === 'mysql') {
            DB::statement('ALTER TABLE Campus MODIFY is_test TINYINT(1) NOT NULL DEFAULT 0');
        }
    }

    public function down(): void
    {
        if (!Schema::hasTable('Campus') || !Schema::hasColumn('Campus', 'is_test')) {
            return;
        }

        if (DB::connection()->getDriverName() === 'mysql') {
            DB::statement('ALTER TABLE Campus MODIFY is_test TINYINT(1) NULL DEFAULT NULL');
        }
    }
};
