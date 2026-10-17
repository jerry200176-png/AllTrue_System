<?php

namespace Tests\Support;

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use RuntimeException;

/**
 * AllTrue_test-only helpers for agreed-fee extension columns.
 * Not a production migration — S1 ships fail-closed without ALTER on main.
 */
final class AgreedFeeTestSchema
{
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
        if (!Schema::hasTable('invoice_corrections')) {
            throw new RuntimeException('invoice_corrections missing — create-table from #4132 required on AllTrue_test');
        }
        if (self::hasExtensionColumns()) {
            return;
        }
        // Ephemeral test schema only (RefreshDatabase). Never committed as a prod migration.
        Schema::table('invoice_corrections', function (Blueprint $table) {
            $table->string('kind', 32)->nullable();
            $table->unsignedInteger('campus_id')->nullable();
            $table->unsignedInteger('old_charge')->nullable();
            $table->unsignedInteger('new_charge')->nullable();
            $table->unsignedInteger('original_invoice_total')->nullable();
            $table->char('request_hash', 64)->nullable();
            $table->char('state_hash', 64)->nullable();
        });
        // Match service inserts: nullable invoice links / amount for unbilled kinds.
        try {
            DB::statement('ALTER TABLE invoice_corrections MODIFY original_invoice_id BIGINT UNSIGNED NULL');
            DB::statement('ALTER TABLE invoice_corrections MODIFY replacement_invoice_id BIGINT UNSIGNED NULL');
            DB::statement('ALTER TABLE invoice_corrections MODIFY amount INT UNSIGNED NULL');
        } catch (\Throwable $e) {
            // SQLite or already-nullable — ignore.
        }
        try {
            Schema::table('invoice_corrections', function (Blueprint $table) {
                $table->unique(['student_class_id', 'idempotency_key'], 'uq_invoice_corrections_course_key');
            });
        } catch (\Throwable $e) {
            // Index may already exist after a prior ensure in this process.
        }
    }

    public static function dropExtensionColumns(): void
    {
        if (!Schema::hasTable('invoice_corrections') || !self::hasExtensionColumns()) {
            return;
        }
        try {
            Schema::table('invoice_corrections', function (Blueprint $table) {
                $table->dropUnique('uq_invoice_corrections_course_key');
            });
        } catch (\Throwable $e) {
            // ignore
        }
        Schema::table('invoice_corrections', function (Blueprint $table) {
            $table->dropColumn(self::COLUMNS);
        });
    }

    public static function hasExtensionColumns(): bool
    {
        return Schema::hasColumns('invoice_corrections', self::COLUMNS);
    }

    /**
     * Mirrors excluded extension migration down() — refuse while agreed-fee history exists.
     */
    public static function refuseDestructiveRollback(): void
    {
        if (DB::table('invoice_corrections')
            ->whereIn('kind', ['agreed_fee', 'agreed_fee_unbilled'])
            ->orWhereNull('original_invoice_id')
            ->orWhereNull('replacement_invoice_id')
            ->orWhereNull('amount')
            ->exists()) {
            throw new RuntimeException('Retain agreed-fee correction history; restore application code with the feature disabled.');
        }
        self::dropExtensionColumns();
    }
}
