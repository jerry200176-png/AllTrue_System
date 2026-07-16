<?php

namespace App\Http\Controllers;

use App\Services\BusinessDigestService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

/** E-OPS-TRUST: campus-scoped Decision Center for director home (read-only). */
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
        $dc = $metrics['decision_center'] ?? [];

        // Server-side snapshot for anomaly first-seen / cleared timelines (no PII names).
        Log::channel('daily')->info('ops_trust_snapshot', [
            'branch_id' => $branchId,
            'user_id' => (int) ($request->attributes->get('auth_user_id') ?? 0),
            'score' => (int) ($dc['score'] ?? 0),
            'status' => (string) ($dc['status'] ?? ''),
            'critical_count' => (int) ($dc['critical_count'] ?? 0),
            'warning_count' => (int) ($dc['warning_count'] ?? 0),
            'decision_keys' => collect($dc['decisions'] ?? [])->pluck('key')->values()->all(),
            'stranded_sessions' => (int) ($metrics['revenue']['stranded_sessions'] ?? 0),
            'dormant_students' => (int) ($metrics['retention']['dormant_prepaid_students'] ?? 0),
            'sessions_next_7d' => (int) ($metrics['coverage']['sessions_next_7d'] ?? 0),
            'remaining_divergent' => (int) ($metrics['data_quality']['remaining_divergent'] ?? 0),
        ]);

        return response()->json([
            'data' => [
                'branch_id' => $branchId,
                'generated_at' => $metrics['generated_at'],
                'decision_center' => $dc,
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
