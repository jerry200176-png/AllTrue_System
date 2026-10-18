<?php

namespace Tests\Support;

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use RuntimeException;

require_once __DIR__.'/alltrueTestDatabase.php';

/**
 * AllTrue_test-only helpers for agreed-fee extension columns.
 * Mirrors excluded e8 extension DDL (kind NOT NULL DEFAULT receipt_reissue) —
 * never a production migration. S1 ships fail-closed without ALTER on main.
 */
final class AgreedFeeTestSchema
{
    /** Validate the resolved target without opening a PDO connection. */
    public static function assertTestConnection(): void
    {
        $connection = DB::connection();
        $config = $connection->getConfig();
        $host = $config['host'] ?? null;
        if ($connection->getDriverName() !== 'mysql'
            || !alltrue_is_allowed_test_database($connection->getDatabaseName())
            || !is_string($host) || !alltrue_is_allowed_test_db_host($host)
            || !empty($config['url']) || !empty($config['unix_socket'])
            || !empty($config['read']) || !empty($config['write'])
            || !empty($config['options'])) {
            throw new RuntimeException('Agreed-fee schema helpers require an isolated synthetic loopback database.');
        }
    }

    private static function assertNoOpenTransaction(): void
    {
        if (DB::connection()->transactionLevel() !== 0) {
            throw new RuntimeException('Refusing schema DDL in an open test transaction.');
        }
    }

    /** @var list<string> */
    private const COLUMNS = [
        'kind',
        'campus_id',
        'old_charge',
        'new_charge',
        'original_invoice_total',
        'request_hash',
        'state_hash',
    ];

    public static function ensureExtensionColumns(): void
    {
        self::assertTestConnection();
        if (!Schema::hasTable('invoice_corrections')) {
            throw new RuntimeException('invoice_corrections missing — create-table from #4132 required on AllTrue_test');
        }
        if (self::hasExtensionColumns()) {
            self::ensureCourseKeyUnique();

            return;
        }
        // One MySQL ALTER matching e8 source: NOT NULL DEFAULT receipt_reissue for kind;
        // nullable invoice links / amount; unique (student_class_id, idempotency_key).
        self::assertNoOpenTransaction();
        DB::statement("ALTER TABLE invoice_corrections
            ADD kind VARCHAR(32) NOT NULL DEFAULT 'receipt_reissue',
            ADD campus_id INT UNSIGNED NULL,
            ADD old_charge INT UNSIGNED NULL,
            ADD new_charge INT UNSIGNED NULL,
            ADD original_invoice_total INT UNSIGNED NULL,
            ADD request_hash CHAR(64) NULL,
            ADD state_hash CHAR(64) NULL,
            ADD UNIQUE KEY uq_invoice_corrections_course_key (student_class_id, idempotency_key),
            MODIFY original_invoice_id BIGINT UNSIGNED NULL,
            MODIFY replacement_invoice_id BIGINT UNSIGNED NULL,
            MODIFY amount INT UNSIGNED NULL");
    }

    /**
     * Test-only reverse of ensureExtensionColumns (like excluded migration down).
     * Refuses while agreed-fee / null-link history exists.
     */
    public static function dropExtensionColumns(): void
    {
        self::assertTestConnection();
        if (!Schema::hasTable('invoice_corrections') || !self::hasExtensionColumns()) {
            return;
        }
        self::refuseDestructiveRollback();
    }

    public static function hasExtensionColumns(): bool
    {
        self::assertTestConnection();
        return Schema::hasColumns('invoice_corrections', self::COLUMNS);
    }

    /**
     * Mirrors excluded extension migration down() — refuse while agreed-fee history exists,
     * otherwise drop extension columns and restore NOT NULL invoice links/amount.
     */
    public static function refuseDestructiveRollback(): void
    {
        self::assertTestConnection();
        if (DB::table('invoice_corrections')
            ->whereIn('kind', ['agreed_fee', 'agreed_fee_unbilled'])
            ->orWhereNull('original_invoice_id')
            ->orWhereNull('replacement_invoice_id')
            ->orWhereNull('amount')
            ->exists()) {
            throw new RuntimeException('Retain agreed-fee correction history; restore application code with the feature disabled.');
        }
        if (!self::hasExtensionColumns()) {
            return;
        }
        self::assertNoOpenTransaction();
        DB::statement('ALTER TABLE invoice_corrections
            MODIFY original_invoice_id BIGINT UNSIGNED NOT NULL,
            MODIFY replacement_invoice_id BIGINT UNSIGNED NOT NULL,
            MODIFY amount INT UNSIGNED NOT NULL');
        Schema::table('invoice_corrections', function (Blueprint $table) {
            $table->dropUnique('uq_invoice_corrections_course_key');
            $table->dropColumn(self::COLUMNS);
        });
    }

    private static function ensureCourseKeyUnique(): void
    {
        $indexes = collect(DB::select('SHOW INDEX FROM invoice_corrections'))
            ->pluck('Key_name')
            ->all();
        if (in_array('uq_invoice_corrections_course_key', $indexes, true)) {
            return;
        }
        self::assertNoOpenTransaction();
        Schema::table('invoice_corrections', function (Blueprint $table) {
            $table->unique(['student_class_id', 'idempotency_key'], 'uq_invoice_corrections_course_key');
        });
    }
}
