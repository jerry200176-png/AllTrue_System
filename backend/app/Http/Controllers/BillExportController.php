<?php

namespace App\Http\Controllers;

use App\Exports\BillListExport;
use App\Models\Campus;
use App\Models\SecurityAuditEvent;
use App\Services\Billing\BillRowText;
use App\Services\Billing\StudentBillWorkbookData;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Maatwebsite\Excel\Facades\Excel;

/**
 * 帳務中心 Excel export (in-app #520), view only. The rows are the ones /alerts/tuition returns for the campus (same
 * service, same role and campus checks); the client names only which of them its current filter/search/tab shows.
 * Every cell is computed here (BillRowText mirrors billRows.js).
 */
class BillExportController extends Controller
{
    public const CAP = 2000;

    public function export(Request $request, AlertController $alerts, StudentBillWorkbookData $workbookData)
    {
        if (count((array) $request->input('rows')) > self::CAP) {
            return response()->json(['message' => '一次最多匯出 ' . self::CAP . ' 筆，請先用篩選縮小範圍。'], 422);
        }
        $data = $request->validate([
            'branch_id' => 'required|integer',
            'filter' => 'required|string|max:20',
            'search' => 'nullable|string|max:60',
            'rows' => 'required|array|min:1',
            'rows.*.id' => 'nullable|integer',
            'rows.*.package_id' => 'nullable|integer',
        ]);

        $sub = Request::create('/', 'GET', ['branch_id' => $data['branch_id']]);
        $sub->attributes->replace($request->attributes->all());
        $res = $alerts->tuition($sub);
        if ($res instanceof JsonResponse && $res->status() !== 200) {
            return $res; // 403 for another campus, same as the list
        }
        $source = collect($res->getData(true));
        $byId = $source->filter(fn ($r) => $r['id'] !== null)->keyBy(fn ($r) => (int) $r['id']);
        $byPkg = $source->filter(fn ($r) => ($r['package_id'] ?? null) !== null)->keyBy(fn ($r) => (int) $r['package_id']);

        $today = now('Asia/Taipei')->toDateString();
        $out = [];
        $selected = [];
        $summaries = [];
        foreach ($data['rows'] as $want) {
            $src = isset($want['id']) ? $byId->get((int) $want['id']) : $byPkg->get((int) ($want['package_id'] ?? 0));
            if (!$src) {
                continue; // gone since the list loaded
            }
            $key = StudentBillWorkbookData::key($src);
            if (isset($selected[$key])) {
                continue;
            }
            $t = BillRowText::describe($src, $today);
            $out[] = [
                (string) ($src['student_name'] ?? ''), (string) ($src['subject'] ?? ''), $t['period'], $t['status'], $t['due'],
                $t['payable'], $t['paid'], $t['owed'],
            ];
            $selected[$key] = $src;
            $summaries[$key] = $out[array_key_last($out)];
        }
        if (!$out) {
            return response()->json(['message' => '清單已更新，請重新整理後再匯出。'], 404);
        }

        $branch = (int) $data['branch_id'];
        $students = $workbookData->build(array_values($selected), $summaries, $request);
        $export = new BillListExport($out, $students, now('Asia/Taipei')->toIso8601String());
        SecurityAuditEvent::append('billing.export', 'success', [
            'actor_type' => 'user', 'actor_id' => $request->attributes->get('auth_user')?->id, 'campus_id' => $branch,
        ], [
            'export_format' => 'xlsx', 'row_count' => count($out), 'column_count' => count($export->headings()),
        ]);

        $code = Campus::query()->whereKey($branch)->value('code') ?: $branch;
        return Excel::download($export, "帳務-{$code}-" . now('Asia/Taipei')->format('Ymd') . '.xlsx');
    }
}
