<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * #1378 — StudentClass free-text columns must accept utf8mb4 (emoji / 4-byte Unicode).
 *
 * Production evidence (Sentry PHP-LARAVEL-24): insert into StudentClass.Memo failed with
 * SQLSTATE 1366 Incorrect string value for calendar emoji (U+1F4C5) while connection charset
 * is already utf8mb4. Root cause: legacy table/column charset utf8mb3 (aka utf8).
 *
 * CI / fresh migrate already creates utf8mb4 tables — this migration is idempotent and
 * only ALTERs columns that are not yet utf8mb4.
 *
 * Free-text targets (user-authored or paste-prone): Memo, PackageName, closed_reason.
 * Also sets table DEFAULT charset so future columns inherit utf8mb4.
 *
 * ⛔ Production execute requires Founder GO — see docs/runbooks/1378-memo-utf8mb4-execution-package.md
 */
return new class extends Migration
{
    private const TARGET_CHARSET = 'utf8mb4';
    private const TARGET_COLLATION = 'utf8mb4_unicode_ci';

    /** @var array<string, array{type: string, nullable: bool}> */
    private const COLUMNS = [
        'Memo' => ['type' => 'VARCHAR(512)', 'nullable' => true],
        'PackageName' => ['type' => 'VARCHAR(128)', 'nullable' => true],
        'closed_reason' => ['type' => 'VARCHAR(20)', 'nullable' => true],
    ];

    public function up(): void
    {
        if (!Schema::hasTable('StudentClass')) {
            return;
        }

        if (DB::connection()->getDriverName() !== 'mysql') {
            return;
        }

        $schema = DB::getDatabaseName();

        foreach (self::COLUMNS as $column => $meta) {
            if (!Schema::hasColumn('StudentClass', $column)) {
                continue;
            }
            if ($this->columnAlreadyUtf8mb4($schema, $column)) {
                continue;
            }

            $nullSql = $meta['nullable'] ? 'NULL' : 'NOT NULL';
            // Column-level MODIFY keeps lock scope smaller than CONVERT TO on whole table.
            DB::statement(sprintf(
                'ALTER TABLE `StudentClass` MODIFY `%s` %s CHARACTER SET %s COLLATE %s %s',
                $column,
                $meta['type'],
                self::TARGET_CHARSET,
                self::TARGET_COLLATION,
                $nullSql
            ));
        }

        // Future columns inherit utf8mb4 even if DB default drifts.
        $tableCharset = DB::selectOne(
            'SELECT CCSA.character_set_name AS charset_name
             FROM information_schema.TABLES T
             JOIN information_schema.COLLATION_CHARACTER_SET_APPLICABILITY CCSA
               ON CCSA.collation_name = T.TABLE_COLLATION
             WHERE T.TABLE_SCHEMA = ? AND T.TABLE_NAME = ?',
            [$schema, 'StudentClass']
        );
        if (($tableCharset->charset_name ?? null) !== self::TARGET_CHARSET) {
            DB::statement(sprintf(
                'ALTER TABLE `StudentClass` DEFAULT CHARACTER SET %s COLLATE %s',
                self::TARGET_CHARSET,
                self::TARGET_COLLATION
            ));
        }
    }

    public function down(): void
    {
        // Downgrade to utf8mb3 would corrupt emoji rows written after up() — no-op by design.
    }

    private function columnAlreadyUtf8mb4(string $schema, string $column): bool
    {
        $row = DB::selectOne(
            'SELECT CHARACTER_SET_NAME AS charset_name, COLLATION_NAME AS collation_name
             FROM information_schema.COLUMNS
             WHERE TABLE_SCHEMA = ? AND TABLE_NAME = ? AND COLUMN_NAME = ?',
            [$schema, 'StudentClass', $column]
        );

        return ($row->charset_name ?? null) === self::TARGET_CHARSET
            && ($row->collation_name ?? null) === self::TARGET_COLLATION;
    }
};
