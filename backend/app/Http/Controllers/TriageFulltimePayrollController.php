<?php

namespace App\Http\Controllers;

use Carbon\Carbon;
use Illuminate\Http\Request;

/**
 * Founder GO 2026-11-15: the 正職薪資要件 report of ONE campus and ONE month for a read-only triage token, so an agent
 * can check the numbers against real data without a browser session or database access. It is the page's own
 * computation; nothing is stored. IDs, numbers, dates and status codes only: every name and free-text value is dropped.
 * Switch: triage_tokens.fulltime_payroll_enabled.
 */
class TriageFulltimePayrollController extends Controller
{
    /** Keys that hold a person's name or text someone typed; removed wherever they appear. */
    private const TEXT_KEYS = ['teacher_name', 'campus_name', 'label', 'reason', 'evidence', 'message', 'locked_by'];

    public function show(Request $request, TeacherEligibilityController $report)
    {
        if (!(bool) config('triage_tokens.fulltime_payroll_enabled')) {
            return response()->json(['message' => 'Not found'], 404);
        }
        $data = $request->validate([
            'month' => ['required', 'regex:/^\\d{4}-(0[1-9]|1[0-2])$/'],
            'branch_id' => ['required', 'integer', 'min:1'],
        ]);
        $start = Carbon::parse($data['month'] . '-01', 'Asia/Taipei');
        // An in-memory GET request carrying only these query values; nothing of the caller's request is passed on.
        $inner = new Request([
            'period' => 'month',
            'start' => $start->toDateString(),
            'end' => $start->copy()->endOfMonth()->toDateString(),
            'branch_id' => (int) $data['branch_id'],
            'compare' => $request->boolean('compare') ? 1 : 0,
        ]);
        // The token acts for the super admin who created it (the middleware has checked that role is still held).
        $inner->attributes->set('auth_role', 'super_admin');

        $response = $report->index($inner);
        $payload = $this->plain($response->getData(true));

        return response()->json(is_array($payload) ? $payload : [], $response->getStatusCode());
    }

    /**
     * Keeps numbers, booleans, nulls and strings made only of ASCII letters, digits and `_ . : -` (ids, dates, status
     * codes). Text keys go by name as well, because a name can be plain ASCII.
     */
    private function plain(mixed $value): mixed
    {
        if (is_array($value)) {
            $out = [];
            foreach ($value as $key => $item) {
                // A key is an id, a date or a field name; anything else (text used as a key) goes too.
                if (is_string($key) && (in_array($key, self::TEXT_KEYS, true) || preg_match('/\A[A-Za-z0-9_.:\-]*\z/', $key) !== 1)) {
                    continue;
                }
                if (is_string($item) && preg_match('/\A[A-Za-z0-9_.:\-]*\z/', $item) !== 1) {
                    continue;
                }
                $out[$key] = $this->plain($item);
            }

            return $out;
        }

        return $value;
    }
}
