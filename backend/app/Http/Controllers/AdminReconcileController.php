<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class AdminReconcileController extends Controller
{
    private const ROWS_MAX_PER_PAGE = 200;

    /**
     * GET /api/v1/admin/reconcile/latest
     * Return the most recent nightly reconcile report.
     */
    public function latest(Request $request)
    {
        $files = $this->liveReportFiles();
        if (empty($files)) {
            return response()->json([
                'data' => null,
                'message' => '尚無對帳報告，請等待夜間排程執行。',
            ], 404);
        }

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
     * GET /api/v1/admin/reconcile/latest/rows?page=1&per_page=200
     * Every mismatch row of the latest live report, one page at a time. The
     * summary report keeps only the first 200 rows; the command writes all of
     * them (IDs only) to nightly-reconcile-rows-Ymd.json (#4044). A report from
     * before that file existed falls back to its stored rows, and meta.complete
     * then tells the panel that rows past them cannot be reached.
     */
    public function rows(Request $request)
    {
        $validated = $request->validate([
            'page' => ['sometimes', 'integer', 'min:1'],
            'per_page' => ['sometimes', 'integer', 'min:1', 'max:' . self::ROWS_MAX_PER_PAGE],
        ]);
        $page = (int) ($validated['page'] ?? 1);
        $perPage = (int) ($validated['per_page'] ?? self::ROWS_MAX_PER_PAGE);

        $files = $this->liveReportFiles();
        $report = $files === [] ? null : json_decode((string) file_get_contents($files[0]), true);
        if (!is_array($report)) {
            return response()->json([
                'data' => [],
                'message' => '尚無對帳報告，請等待夜間排程執行。',
            ], 404);
        }

        $rows = is_array($report['mismatches'] ?? null) ? $report['mismatches'] : [];
        $rowsFile = dirname($files[0]) . '/' . str_replace('nightly-reconcile-', 'nightly-reconcile-rows-', basename($files[0]));
        $stored = is_file($rowsFile) ? json_decode((string) file_get_contents($rowsFile), true) : null;
        if (
            is_array($stored)
            && is_array($stored['mismatches'] ?? null)
            && ($stored['checked_at'] ?? null) === ($report['checked_at'] ?? null)
        ) {
            $rows = $stored['mismatches'];
        }

        $total = count($rows);
        $mismatchCount = (int) ($report['mismatch_count'] ?? $total);

        return response()->json([
            'data' => $this->enrichRows(array_values(array_slice($rows, ($page - 1) * $perPage, $perPage))),
            'meta' => [
                'checked_at' => $report['checked_at'] ?? null,
                'page' => $page,
                'per_page' => $perPage,
                'total' => $total,
                'last_page' => max(1, (int) ceil($total / $perPage)),
                'mismatch_count' => $mismatchCount,
                'complete' => $total >= $mismatchCount,
            ],
        ]);
    }

    /**
     * GET /api/v1/admin/reconcile
     * Return reconcile history (list of available reports).
     */
    public function index(Request $request)
    {
        $files = $this->liveReportFiles();

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
     * Dated live reports, newest first. Dry-run previews are written to
     * nightly-reconcile-dryrun-*.json and never count as the scheduled run;
     * older dry-runs that still sit under the dated name are skipped by mode
     * (#3945). A report without a mode predates the field and is live.
     *
     * @return list<string>
     */
    private function liveReportFiles(): array
    {
        $files = array_values(array_filter(
            glob(storage_path('logs/nightly-reconcile-*.json')) ?: [],
            static fn (string $file): bool => preg_match('/^nightly-reconcile-\d{8}\.json$/', basename($file)) === 1
        ));
        rsort($files);

        return array_values(array_filter($files, static function (string $file): bool {
            $report = json_decode((string) @file_get_contents($file), true);

            return !is_array($report) || ($report['mode'] ?? 'live') === 'live';
        }));
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
        $report['mismatches'] = $this->enrichRows(is_array($report['mismatches'] ?? null) ? $report['mismatches'] : []);

        return $report;
    }

    /**
     * @param array<int,mixed> $mismatches
     * @return list<array<string,mixed>>
     */
    private function enrichRows(array $mismatches): array
    {
        $mismatches = array_values(array_filter($mismatches, 'is_array'));
        if ($mismatches === []) {
            return [];
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

        return array_map(static function (array $mismatch) use ($students, $subjects): array {
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
    }
}
