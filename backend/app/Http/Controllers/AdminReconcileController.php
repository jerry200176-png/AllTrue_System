<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use App\Services\SessionDeductionService;

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
                'data' => [
                    'checked_at' => null,
                    'total_checked' => 0,
                    'mismatch_count' => 0,
                    'mismatches' => [],
                    'message' => '尚無對帳報告，請等待夜間排程執行。',
                ],
            ]);
        }

        rsort($files);
        $latest = $files[0];
        $report = json_decode(file_get_contents($latest), true);

        return response()->json(['data' => $report]);
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
}
