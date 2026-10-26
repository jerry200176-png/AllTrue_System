<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        $connection = Schema::getConnection();
        // Older schema helpers may read a replica; ALTER must use primary metadata.
        $columns = $connection->selectFromWriteConnection(
            'SELECT COLUMN_NAME FROM information_schema.COLUMNS '
            .'WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ?',
            [$connection->getTablePrefix().'personal_access_tokens']
        );
        if ($columns === []) {
            throw new RuntimeException('personal_access_tokens must exist before its expiry upgrade');
        }

        // The original 2019 migration is already recorded on existing installations.
        $columnNames = array_map(fn (object $column) => strtolower((string) $column->COLUMN_NAME), $columns);
        if (! in_array('expires_at', $columnNames, true)) {
            Schema::table('personal_access_tokens', function (Blueprint $table) {
                $table->timestamp('expires_at')->nullable()->index();
            });

            return;
        }

        if (! $this->hasExpiryIndex()) {
            Schema::table('personal_access_tokens', function (Blueprint $table) {
                $table->index('expires_at');
            });
        }
    }

    private function hasExpiryIndex(): bool
    {
        // Laravel 8 has no Schema::hasIndex(); keep this prerequisite usable
        // before and after the framework upgrade. Read metadata, never token rows.
        $connection = Schema::getConnection();
        $rows = $connection->selectFromWriteConnection(
            'SELECT INDEX_NAME, COLUMN_NAME FROM information_schema.STATISTICS '
            .'WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? ORDER BY INDEX_NAME, SEQ_IN_INDEX',
            [$connection->getTablePrefix().'personal_access_tokens']
        );
        $indexes = [];
        foreach ($rows as $row) {
            $indexes[$row->INDEX_NAME][] = strtolower((string) $row->COLUMN_NAME);
        }
        foreach ($indexes as $columns) {
            if ($columns === ['expires_at']) {
                return true;
            }
        }

        return false;
    }

    public function down(): void
    {
        // Keep expiry values and pre-existing columns/indexes during code rollback.
        // An idempotent additive migration must never undo an existing schema.
    }
};
