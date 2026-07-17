<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class AdminReconcileController extends Controller
{
    /**
     * GET /api/v1/admin/reconcile/latest
     * Return the most recent nightly reconcile report.
     */
    public function latest(Request $request)
    {
        $files = glob(storage_path('logs/nightly-reconcile-*.json'));
        if (empty($files)) {
            return response()->json([
                'data' => null,
                'message' => '尚無對帳報告，請等待夜間排程執行。',
            ], 404);
        }

        rsort($files);
        $latest = $files[0];
        $report = json_decode((string) file_get_contents($latest), true);
        if (!is_array($report)) {
            return response()->json([
                'data' => null,
                'message' => '最新對帳報告無法讀取，請確認排程輸出。',
            ], 500);
        }

        return response()->json(['data' => $this->enrichReport($report)]);
    }

    /**
     * GET /api/v1/admin/reconcile
     * Return reconcile history (list of available reports).
     */
    public function index(Request $request)
    {
        $files = glob(storage_path('logs/nightly-reconcile-*.json'));
        rsort($files);

        $reports = [];
        foreach (array_slice($files, 0, 30) as $file) {
            $report = json_decode(file_get_contents($file), true);
            $reports[] = [
                'date' => $report['checked_at'] ?? basename($file),
                'total_checked' => $report['total_checked'] ?? 0,
                'mismatch_count' => $report['mismatch_count'] ?? 0,
            ];
        }

        return response()->json(['data' => $reports]);
    }

    /**
     * Add authorized display names at request time so the on-disk scheduler
     * report and GitHub evidence remain free of names and campus labels.
     *
     * @param array<string,mixed> $report
     * @return array<string,mixed>
     */
    private function enrichReport(array $report): array
    {
        $mismatches = is_array($report['mismatches'] ?? null) ? $report['mismatches'] : [];
        if ($mismatches === []) {
            $report['mismatches'] = [];
            return $report;
        }

        $studentIds = collect($mismatches)->pluck('student_id')->filter()->map(fn ($id) => (int) $id)->unique()->values();
        $subjectIds = collect($mismatches)->pluck('subject_id')->filter()->map(fn ($id) => (int) $id)->unique()->values();

        $students = DB::table('Student as student')
            ->leftJoin('Campus as campus', 'campus.id', '=', 'student.CampusID')
            ->whereIn('student.id', $studentIds)
            ->select([
                'student.id as student_id',
                'student.name as student_name',
                'student.CampusID as campus_id',
                'campus.name as campus_name',
            ])
            ->get()
            ->keyBy('student_id');

        $subjects = DB::table('Subject')
            ->whereIn('id', $subjectIds)
            ->pluck('Subject_Name', 'id');

        $report['mismatches'] = array_map(static function (array $mismatch) use ($students, $subjects): array {
            $student = $students->get((int) ($mismatch['student_id'] ?? 0));
            $subjectId = (int) ($mismatch['subject_id'] ?? 0);
            $campusId = data_get($student, 'campus_id');

            return $mismatch + [
                'student_name' => data_get($student, 'student_name', '學生資料已不存在'),
                'campus_id' => $campusId !== null ? (int) $campusId : null,
                'campus_name' => data_get($student, 'campus_name', '未指定分校'),
                'subject_name' => $subjects->get($subjectId) ?? '科目資料已不存在',
                'category' => $mismatch['category'] ?? 'unknown',
            ];
        }, $mismatches);

        return $report;
    }
}
