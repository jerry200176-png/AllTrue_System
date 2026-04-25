<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Security fix (part 2): remove student_line_bindings where a single
 * line_user_id is shared across students from more than one campus.
 *
 * Legitimate family groups: siblings attend the same campus.
 * Cross-campus LINE user IDs are artefacts of the 2026-04-16 backfill
 * migration that copied Student.LineID verbatim — multiple unrelated students
 * happened to share the same LineID value in the old system.
 */
return new class extends Migration
{
    public function up(): void
    {
        // Find line_user_id values linked to students from more than 1 campus
        $badLineIds = DB::table('student_line_bindings as slb')
            ->join('Student as s', 's.id', '=', 'slb.student_id')
            ->selectRaw('slb.line_user_id, COUNT(DISTINCT s.CampusID) as campus_count')
            ->groupBy('slb.line_user_id')
            ->havingRaw('COUNT(DISTINCT s.CampusID) > 1')
            ->pluck('line_user_id');

        if ($badLineIds->isEmpty()) {
            return;
        }

        \Illuminate\Support\Facades\Log::warning('security.line_binding_cleanup', [
            'action'        => 'remove_cross_campus_bindings',
            'line_user_ids' => $badLineIds->toArray(),
        ]);

        DB::table('student_line_bindings')
            ->whereIn('line_user_id', $badLineIds)
            ->delete();
    }

    public function down(): void
    {
        // Cannot recover — this is a security fix
    }
};
