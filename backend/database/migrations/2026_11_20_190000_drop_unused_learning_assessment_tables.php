<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Schema;

/**
 * TD-090: drop the last three learning-assessment tables. #5555 removed their only readers (parent portal card,
 * student timeline) and their models; nothing has written them since the authoring UI was removed. All three held
 * 0 rows in production on 2026-11-20 (read-only triage count). No foreign keys point at them; children go first.
 */
return new class extends Migration
{
    private const TABLES = [
        'assessment_remediation_actions',
        'assessment_results',
        'assessments',
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
