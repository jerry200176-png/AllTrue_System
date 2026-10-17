<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/** Local prerequisite: coordinate adoption with pending #4132; no production migration authorized. */
return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasColumns('invoice_corrections', ['original_invoice_id', 'replacement_invoice_id', 'student_class_id', 'payment_report_id', 'amount', 'reason', 'idempotency_key', 'operator_user_id', 'created_at'])
            || DB::table('invoice_corrections')->select('student_class_id', 'idempotency_key')->groupBy('student_class_id', 'idempotency_key')->havingRaw('COUNT(*) > 1')->exists()) {
            throw new RuntimeException('Coordinate the #4132 schema and resolve duplicate course/key history before migration.');
        }
        // Deliberately require #4132's table. Do not silently skip an already-created older table.
        // One MySQL ALTER: preflight failure or DDL failure cannot leave half the extension applied.
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

    public function down(): void
    {
        // Unbilled history has no truthful invoice link. Refuse rollback rather than fabricate/delete it.
        if (DB::table('invoice_corrections')->whereIn('kind', ['agreed_fee', 'agreed_fee_unbilled'])->orWhereNull('original_invoice_id')->orWhereNull('replacement_invoice_id')->orWhereNull('amount')->exists()) {
            throw new RuntimeException('Retain agreed-fee correction history; restore application code with the feature disabled.');
        }
        DB::statement('ALTER TABLE invoice_corrections MODIFY original_invoice_id BIGINT UNSIGNED NOT NULL, MODIFY replacement_invoice_id BIGINT UNSIGNED NOT NULL, MODIFY amount INT UNSIGNED NOT NULL');
        Schema::table('invoice_corrections', function (Blueprint $table) {
            $table->dropUnique('uq_invoice_corrections_course_key');
            $table->dropColumn(['kind', 'campus_id', 'old_charge', 'new_charge', 'original_invoice_total', 'request_hash', 'state_hash']);
        });
    }
};
