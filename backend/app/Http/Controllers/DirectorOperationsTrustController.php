<?php

namespace App\Http\Controllers;

use App\Services\BusinessDigestService;
use Illuminate\Http\Request;

/**
 * Director Operations Trust — campus-scoped Trust KPIs for the director home (E-OPS-TRUST).
 * Read-only. Distinct from SystemTrustController (changelog/reliability narrative).
 */
class DirectorOperationsTrustController extends Controller
{
    public function show(Request $request, BusinessDigestService $digest)
    {
        $branchId = (int) $request->input('branch_id', 0);
        if ($branchId <= 0) {
            return response()->json(['message' => 'branch_id is required'], 422);
        }

        if (!$this->canAccessBranch($request, $branchId)) {
            return response()->json(['message' => 'Forbidden'], 403);
        }

        $metrics = $digest->metrics($branchId);

        return response()->json([
            'data' => [
                'branch_id' => $branchId,
                'generated_at' => $metrics['generated_at'],
                'decision_center' => $metrics['decision_center'],
                'trust' => $metrics['trust'],
                'revenue' => $metrics['revenue'],
                'retention' => $metrics['retention'],
                'coverage' => $metrics['coverage'],
                'data_quality' => $metrics['data_quality'],
                'anomalies' => $digest->anomalies($metrics),
            ],
        ]);
    }

    private function canAccessBranch(Request $request, int $branchId): bool
    {
        $role = (string) $request->attributes->get('auth_role', '');
        if ($role === 'super_admin') {
            return true;
        }
        $allowed = array_map('intval', (array) $request->attributes->get('auth_campus_ids', []));

        return in_array($branchId, $allowed, true);
    }
}
