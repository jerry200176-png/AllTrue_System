<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/** Expand the #4132 permanent history table for the three-case agreed-fee release. */
return new class extends Migration
{
    private const COLUMNS = [
        'kind' => "VARCHAR(32) NOT NULL DEFAULT 'receipt_reissue'",
        'campus_id' => 'INT UNSIGNED NULL',
        'old_charge' => 'INT UNSIGNED NULL',
        'new_charge' => 'INT UNSIGNED NULL',
        'original_invoice_total' => 'INT UNSIGNED NULL',
        'request_hash' => 'CHAR(64) NULL',
        'state_hash' => 'CHAR(64) NULL',
    ];

    public function up(): void
    {
        if (!Schema::hasTable('invoice_corrections')) {
            throw new RuntimeException('invoice_corrections base table is missing; apply its original create-table migration first.');
        }

        $existing = Schema::getColumnListing('invoice_corrections');
        if (in_array('kind', $existing, true)) {
            $kind = collect(DB::select('SHOW COLUMNS FROM invoice_corrections'))->firstWhere('Field', 'kind');
            if (!$kind || strtolower($kind->Type) !== 'varchar(32)') {
                throw new RuntimeException('Unexpected invoice_corrections.kind definition; stop for schema review.');
            }
            if ($kind->Null !== 'NO' || $kind->Default !== 'receipt_reissue') {
                if (DB::table('invoice_corrections')->whereNull('kind')->exists()) {
                    throw new RuntimeException('Retain unclassified invoice correction history; do not guess its kind.');
                }
                DB::statement("ALTER TABLE invoice_corrections MODIFY kind VARCHAR(32) NOT NULL DEFAULT 'receipt_reissue'");
            }
        }

        $parts = [];
        foreach (self::COLUMNS as $name => $definition) {
            if (!in_array($name, $existing, true)) $parts[] = "ADD {$name} {$definition}";
        }
        foreach (['original_invoice_id' => 'BIGINT UNSIGNED NULL', 'replacement_invoice_id' => 'BIGINT UNSIGNED NULL', 'amount' => 'INT UNSIGNED NULL'] as $name => $definition) {
            if (!in_array($name, $existing, true)) {
                throw new RuntimeException("Required base column invoice_corrections.{$name} is missing.");
            }
            $column = collect(DB::select('SHOW COLUMNS FROM invoice_corrections'))->firstWhere('Field', $name);
            if ($column?->Null === 'NO') $parts[] = "MODIFY {$name} {$definition}";
        }
        if ($parts !== []) DB::statement('ALTER TABLE invoice_corrections '.implode(', ', $parts));

        $indexNames = collect(DB::select('SHOW INDEX FROM invoice_corrections'))->pluck('Key_name')->all();
        if (!in_array('uq_invoice_corrections_course_key', $indexNames, true)) {
            Schema::table('invoice_corrections', function (Blueprint $table) {
                $table->unique(['student_class_id', 'idempotency_key'], 'uq_invoice_corrections_course_key');
            });
        }
    }

    public function down(): void
    {
        if (!Schema::hasTable('invoice_corrections')) return;
        if (DB::table('invoice_corrections')->exists()) {
            throw new RuntimeException('Retain permanent invoice correction history; rollback application code and keep the additive schema.');
        }

        $columns = array_values(array_filter(array_keys(self::COLUMNS), fn ($name) => Schema::hasColumn('invoice_corrections', $name)));
        $hasCourseKey = collect(DB::select('SHOW INDEX FROM invoice_corrections'))->pluck('Key_name')->contains('uq_invoice_corrections_course_key');
        if ($hasCourseKey || $columns !== []) {
            Schema::table('invoice_corrections', function (Blueprint $table) use ($hasCourseKey, $columns) {
                if ($hasCourseKey) $table->dropUnique('uq_invoice_corrections_course_key');
                if ($columns !== []) $table->dropColumn($columns);
            });
        }
        DB::statement('ALTER TABLE invoice_corrections MODIFY original_invoice_id BIGINT UNSIGNED NOT NULL, MODIFY replacement_invoice_id BIGINT UNSIGNED NOT NULL, MODIFY amount INT UNSIGNED NOT NULL');
    }
};
