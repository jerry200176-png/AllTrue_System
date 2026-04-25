<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Security fix: remove student_line_bindings rows where line_user_id is not
 * a valid LINE user ID (format: U + 32 lowercase hex chars).
 *
 * Root cause: the 2026_04_16 backfill migration copied Student.LineID values
 * that were never real LINE user IDs (placeholders, phone numbers, etc.),
 * causing cross-family students to appear as siblings in the parent portal.
 */
return new class extends Migration
{
    public function up(): void
    {
        // Delete rows where line_user_id doesn't match official LINE format
        DB::table('student_line_bindings')
            ->chunkById(200, function ($rows) {
                foreach ($rows as $row) {
                    if (!preg_match('/^U[0-9a-f]{32}$/i', $row->line_user_id)) {
                        DB::table('student_line_bindings')->where('id', $row->id)->delete();
                    }
                }
            });
    }

    public function down(): void
    {
        // Cannot recover deleted rows — this is a security fix, not reversible
    }
};
