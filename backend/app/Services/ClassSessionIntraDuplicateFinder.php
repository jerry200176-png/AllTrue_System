<?php

namespace App\Services;

use Illuminate\Support\Facades\DB;

/**
 * Shared intra-course duplicate slot detection (#957).
 *
 * Type A — active conflicts: 2+ non-cancelled rows on same (StudentClassID, date, start HM).
 * Used by audit + scoped cleanup (must stay aligned).
 *
 * Type B — cancelled placeholder collisions: 2+ total rows but ≤1 non-cancelled.
 * Read-only analysis only; out of scope for PCR cleanup execute.
 */
class ClassSessionIntraDuplicateFinder
{
    /**
     * Type A: true duplicate conflicts (audit + cleanup scope).
     *
     * @return list<array{student_class_id: int, session_date: string, start_time: string, row_count: int, session_ids: list<int>}>
     */
    public function findActiveDuplicateGroups(?int $studentClassId = null): array
    {
        $query = DB::table('ClassSession as cs')
            ->where('cs.Status', '<>', 'cancelled')
            ->selectRaw('cs.StudentClassID as student_class_id')
            ->selectRaw('DATE(cs.SessionDate) as session_date')
            ->selectRaw('SUBSTRING(cs.StartTime, 1, 5) as start_time')
            ->selectRaw('COUNT(*) as row_count')
            ->selectRaw('GROUP_CONCAT(cs.id ORDER BY cs.id) as session_ids')
            ->groupBy('cs.StudentClassID', DB::raw('DATE(cs.SessionDate)'), DB::raw('SUBSTRING(cs.StartTime, 1, 5)'))
            ->having('row_count', '>', '1');

        if ($studentClassId) {
            $query->where('cs.StudentClassID', $studentClassId);
        }

        return $query->get()->map(function ($row) {
            return [
                'student_class_id' => (int) $row->student_class_id,
                'session_date' => (string) $row->session_date,
                'start_time' => (string) $row->start_time,
                'row_count' => (int) $row->row_count,
                'session_ids' => array_map('intval', explode(',', (string) $row->session_ids)),
            ];
        })->values()->all();
    }

    /**
     * Type B: slots with multiple rows but at most one non-cancelled (placeholder analysis).
     *
     * @return list<array{student_class_id: int, session_date: string, start_time: string, total_rows: int, active_rows: int, session_ids: list<int>}>
     */
    public function findCancelledPlaceholderCollisions(?int $studentClassId = null): array
    {
        $sub = DB::table('ClassSession as cs')
            ->selectRaw('cs.StudentClassID as student_class_id')
            ->selectRaw('DATE(cs.SessionDate) as session_date')
            ->selectRaw('SUBSTRING(cs.StartTime, 1, 5) as start_time')
            ->selectRaw('COUNT(*) as total_rows')
            ->selectRaw('SUM(CASE WHEN cs.Status <> \'cancelled\' THEN 1 ELSE 0 END) as active_rows')
            ->selectRaw('GROUP_CONCAT(cs.id ORDER BY cs.id) as session_ids')
            ->groupBy('cs.StudentClassID', DB::raw('DATE(cs.SessionDate)'), DB::raw('SUBSTRING(cs.StartTime, 1, 5)'))
            ->having('total_rows', '>', '1')
            ->having('active_rows', '<=', '1');

        if ($studentClassId) {
            $sub->where('cs.StudentClassID', $studentClassId);
        }

        return $sub->get()->map(function ($row) {
            return [
                'student_class_id' => (int) $row->student_class_id,
                'session_date' => (string) $row->session_date,
                'start_time' => (string) $row->start_time,
                'total_rows' => (int) $row->total_rows,
                'active_rows' => (int) $row->active_rows,
                'session_ids' => array_map('intval', explode(',', (string) $row->session_ids)),
            ];
        })->values()->all();
    }

    /**
     * Slots blocking unique index (any status): total row count > 1.
     *
     * @return list<array{student_class_id: int, session_date: string, start_time: string, row_count: int}>
     */
    public function findUniqueIndexBlockingSlots(?int $studentClassId = null): array
    {
        $query = DB::table('ClassSession as cs')
            ->selectRaw('cs.StudentClassID as student_class_id')
            ->selectRaw('DATE(cs.SessionDate) as session_date')
            ->selectRaw('SUBSTRING(cs.StartTime, 1, 5) as start_time')
            ->selectRaw('COUNT(*) as row_count')
            ->groupBy('cs.StudentClassID', DB::raw('DATE(cs.SessionDate)'), DB::raw('SUBSTRING(cs.StartTime, 1, 5)'))
            ->having('row_count', '>', '1');

        if ($studentClassId) {
            $query->where('cs.StudentClassID', $studentClassId);
        }

        return $query->get()->map(function ($row) {
            return [
                'student_class_id' => (int) $row->student_class_id,
                'session_date' => (string) $row->session_date,
                'start_time' => (string) $row->start_time,
                'row_count' => (int) $row->row_count,
            ];
        })->values()->all();
    }
}
