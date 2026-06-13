<?php

namespace App\Http\Controllers;

use App\Models\ScheduleAuditLog;
use App\Models\UserCampus;
use Illuminate\Http\Request;

/**
 * GET /api/v1/schedule-audit  — 排課稽核日誌查詢（director/super_admin）
 */
class ScheduleAuditController extends Controller
{
    public function index(Request $request)
    {
        $role     = $request->attributes->get('auth_role');
        $operator = $request->attributes->get('auth_user');

        if (!in_array($role, ['director', 'admin', 'super_admin'])) {
            return response()->json(['message' => 'Forbidden'], 403);
        }

        $validated = $request->validate([
            'branch_id'  => 'nullable|integer',
            'date_from'  => 'nullable|date',
            'date_to'    => 'nullable|date|after_or_equal:date_from',
            'session_id' => 'nullable|integer',
            'per_page'   => 'nullable|integer|min:1|max:200',
        ]);

        // Directors can only see their own branch(es).
        if ($role !== 'super_admin' && $operator) {
            $allowedBranches = UserCampus::where('UserID', $operator->id)
                ->where('Approved', true)
                ->pluck('CampusID')
                ->map(fn ($id) => (int) $id)
                ->all();
        } else {
            $allowedBranches = null;
        }

        $query = ScheduleAuditLog::query()->orderByDesc('created_at');

        if ($allowedBranches !== null) {
            $query->whereIn('branch_id', $allowedBranches);
        }

        if (!empty($validated['branch_id'])) {
            if ($allowedBranches !== null && !in_array((int) $validated['branch_id'], $allowedBranches)) {
                return response()->json(['message' => 'Forbidden'], 403);
            }
            $query->where('branch_id', $validated['branch_id']);
        }

        if (!empty($validated['date_from'])) {
            $query->where('created_at', '>=', $validated['date_from'] . ' 00:00:00');
        }
        if (!empty($validated['date_to'])) {
            $query->where('created_at', '<=', $validated['date_to'] . ' 23:59:59');
        }
        if (!empty($validated['session_id'])) {
            $query->where('session_id', $validated['session_id']);
        }

        $perPage   = (int) ($validated['per_page'] ?? 50);
        $paginated = $query->paginate($perPage);

        return response()->json([
            'data' => $paginated->items(),
            'meta' => [
                'total'        => $paginated->total(),
                'per_page'     => $paginated->perPage(),
                'current_page' => $paginated->currentPage(),
                'last_page'    => $paginated->lastPage(),
            ],
        ]);
    }
}
