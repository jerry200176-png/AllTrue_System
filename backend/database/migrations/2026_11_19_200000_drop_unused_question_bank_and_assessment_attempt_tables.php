<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Schema;

/**
 * TD-090 / TD-091 (Founder GO 2026-11-19): drop the question-bank and assessment-attempt tables. Their UI, routes,
 * controllers and models were removed; nothing reads or writes them, and all seven were empty in production on
 * 2026-11-19. `assessments`, `assessment_results` and `assessment_remediation_actions` stay (parent portal and the
 * student timeline still read them). No foreign keys point at these tables; children are dropped first anyway.
 */
return new class extends Migration
{
    private const TABLES = [
        'assessment_answers',
        'assessment_attempts',
        'assessment_question_snapshots',
        'assessment_audit_logs',
        'question_bank_audit_logs',
        'question_bank_items',
        'question_banks',
    ];

    public function up(): void
    {
        foreach (self::TABLES as $table) {
            Schema::dropIfExists($table);
        }
    }

    // One-way: the tables held no rows and no code uses them; the 2026_08_2x create migrations keep the old shape.
    public function down(): void
    {
    }
};
