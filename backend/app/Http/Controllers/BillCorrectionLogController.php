<?php

namespace App\Http\Controllers;

use App\Services\Billing\BillCorrectionLog;
use Illuminate\Http\Request;

/** GET accounting/bill-corrections: HQ (super_admin only, by the route group) reads the 更正帳單 history. */
final class BillCorrectionLogController extends Controller
{
    public function index(Request $request, BillCorrectionLog $log)
    {
        $filters = $request->validate([
            'campus_id' => 'nullable|integer|min:1',
            'month' => ['nullable', 'regex:/^\d{4}-(0[1-9]|1[0-2])$/'],
            'per_page' => 'nullable|integer|min:1|max:100',
        ]);

        return response()->json($log->page($filters));
    }
}
