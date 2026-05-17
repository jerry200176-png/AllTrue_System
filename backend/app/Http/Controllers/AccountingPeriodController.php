<?php

namespace App\Http\Controllers;

use App\Models\AccountingPeriod;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class AccountingPeriodController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $role = $request->attributes->get('auth_role');
        $campusIds = $role === 'super_admin'
            ? []
            : array_map('intval', (array) $request->attributes->get('auth_campus_ids', []));

        $query = AccountingPeriod::query()->orderByDesc('period_year')->orderByDesc('period_month');

        if ($request->filled('branch_id')) {
            $branchId = (int) $request->input('branch_id');
            if ($role !== 'super_admin' && !empty($campusIds) && !in_array($branchId, $campusIds, true)) {
                return response()->json(['message' => 'Forbidden'], 403);
            }
            $query->where('branch_id', $branchId);
        } elseif (!empty($campusIds)) {
            $query->whereIn('branch_id', $campusIds);
        }

        $periods = $query->limit(36)->get();

        return response()->json([
            'periods' => $periods->map(fn (AccountingPeriod $p) => [
                'branch_id' => (int) $p->branch_id,
                'year' => (int) $p->period_year,
                'month' => (int) $p->period_month,
                'closed_at' => $p->closed_at?->toIso8601String(),
                'closed_by' => $p->closed_by ? (int) $p->closed_by : null,
                'is_closed' => $p->closed_at !== null && $p->reopened_at === null,
                'reopened_at' => $p->reopened_at?->toIso8601String(),
            ])->values(),
        ]);
    }

    public function close(Request $request): JsonResponse
    {
        $request->validate([
            'branch_id' => 'required|integer',
            'year' => 'required|integer|min:2020|max:2099',
            'month' => 'required|integer|min:1|max:12',
        ]);

        $branchId = (int) $request->input('branch_id');
        $year = (int) $request->input('year');
        $month = (int) $request->input('month');
        $user = $request->attributes->get('auth_user');

        $now = now();
        $periodEnd = \Carbon\Carbon::create($year, $month, 1)->endOfMonth();
        if ($periodEnd->isFuture()) {
            return response()->json(['message' => '不可關帳未來月份'], 422);
        }

        $existing = AccountingPeriod::query()
            ->where('branch_id', $branchId)
            ->where('period_year', $year)
            ->where('period_month', $month)
            ->first();

        if ($existing && $existing->closed_at && !$existing->reopened_at) {
            return response()->json(['message' => '此月份已關帳'], 422);
        }

        if ($existing) {
            $existing->update([
                'closed_at' => $now,
                'closed_by' => (int) $user->id,
                'reopened_at' => null,
                'reopened_by' => null,
                'reopen_reason' => null,
            ]);
        } else {
            AccountingPeriod::create([
                'branch_id' => $branchId,
                'period_year' => $year,
                'period_month' => $month,
                'closed_at' => $now,
                'closed_by' => (int) $user->id,
            ]);
        }

        return response()->json(['message' => '關帳成功', 'closed_at' => $now->toIso8601String()]);
    }

    public function reopen(Request $request): JsonResponse
    {
        $request->validate([
            'branch_id' => 'required|integer',
            'year' => 'required|integer',
            'month' => 'required|integer|min:1|max:12',
            'reason' => 'required|string|min:5',
        ]);

        $branchId = (int) $request->input('branch_id');
        $year = (int) $request->input('year');
        $month = (int) $request->input('month');
        $user = $request->attributes->get('auth_user');

        $period = AccountingPeriod::query()
            ->where('branch_id', $branchId)
            ->where('period_year', $year)
            ->where('period_month', $month)
            ->whereNotNull('closed_at')
            ->first();

        if (!$period) {
            return response()->json(['message' => '找不到已關帳的期間'], 404);
        }

        $period->update([
            'reopened_at' => now(),
            'reopened_by' => (int) $user->id,
            'reopen_reason' => $request->input('reason'),
        ]);

        return response()->json(['message' => '重新開帳成功']);
    }
}
