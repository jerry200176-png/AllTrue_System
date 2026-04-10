<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasTable('User') || DB::getDriverName() !== 'mysql') {
            return;
        }

        $indexes = $this->readUserIndexes();

        foreach ($indexes as $keyName => $meta) {
            if ($meta['non_unique'] !== 0) {
                continue;
            }

            $columns = $meta['columns'];
            if (count($columns) === 1 && strcasecmp($columns[0], 'LoginName') === 0) {
                DB::statement(sprintf('ALTER TABLE `User` DROP INDEX `%s`', str_replace('`', '``', $keyName)));
            }
        }

        $indexes = $this->readUserIndexes();
        $hasCompositeUnique = false;
        foreach ($indexes as $meta) {
            if ($meta['non_unique'] !== 0) {
                continue;
            }
            $columns = array_map('strtolower', $meta['columns']);
            if ($columns === ['loginname', 'type']) {
                $hasCompositeUnique = true;
                break;
            }
        }

        if (!$hasCompositeUnique) {
            DB::statement('ALTER TABLE `User` ADD UNIQUE KEY `user_loginname_type_unique` (`LoginName`, `type`)');
        }
    }

    public function down(): void
    {
        if (!Schema::hasTable('User') || DB::getDriverName() !== 'mysql') {
            return;
        }

        $indexes = $this->readUserIndexes();

        foreach ($indexes as $keyName => $meta) {
            if ($meta['non_unique'] !== 0) {
                continue;
            }

            $columns = array_map('strtolower', $meta['columns']);
            if ($columns === ['loginname', 'type']) {
                DB::statement(sprintf('ALTER TABLE `User` DROP INDEX `%s`', str_replace('`', '``', $keyName)));
            }
        }

        $indexes = $this->readUserIndexes();
        $hasSingleUnique = false;
        foreach ($indexes as $meta) {
            if ($meta['non_unique'] !== 0) {
                continue;
            }
            $columns = $meta['columns'];
            if (count($columns) === 1 && strcasecmp($columns[0], 'LoginName') === 0) {
                $hasSingleUnique = true;
                break;
            }
        }

        if (!$hasSingleUnique) {
            DB::statement('ALTER TABLE `User` ADD UNIQUE KEY `user_loginname_unique` (`LoginName`)');
        }
    }

    /**
     * @return array<string, array{non_unique:int, columns:array<int, string>}>
     */
    private function readUserIndexes(): array
    {
        $rows = DB::select('SHOW INDEX FROM `User`');
        $grouped = [];

        foreach ($rows as $row) {
            $keyName = (string) $row->Key_name;
            if (!isset($grouped[$keyName])) {
                $grouped[$keyName] = [
                    'non_unique' => (int) $row->Non_unique,
                    'columns' => [],
                ];
            }

            $grouped[$keyName]['columns'][(int) $row->Seq_in_index] = (string) $row->Column_name;
        }

        foreach ($grouped as $keyName => $meta) {
            ksort($meta['columns']);
            $grouped[$keyName]['columns'] = array_values($meta['columns']);
        }

        return $grouped;
    }
};
