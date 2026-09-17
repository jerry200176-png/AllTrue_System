<?php

namespace App\Http\Controllers;

use App\Services\GradePromotionService;
use Illuminate\Http\Request;

class GradePromotionController extends Controller
{
    public function preview(Request $request, GradePromotionService $service)
    {
        $campusIds = $request->attributes->get('auth_campus_ids', []);
        $data = $request->validate([
            'branch_id' => 'required|integer|min:1',
            'season_year' => 'nullable|integer|min:2000|max:2100',
        ]);
        $campusId = (int) $data['branch_id'];
        if (!in_array($campusId, array_map('intval', (array) $campusIds), true)) {
            return response()->json(['message' => 'Forbidden'], 403);
        }

        $seasonYear = isset($data['season_year'])
            ? (int) $data['season_year']
            : $service->defaultSeasonYear();

        $rows = $service->preview($campusId, $seasonYear);
        $actionable = array_values(array_filter($rows, static fn (array $r) => $r['actionable']));
        $admin = $service->adminDateForYear($seasonYear);

        return response()->json([
            'campus_id' => $campusId,
            'season_year' => $seasonYear,
            'admin_date' => $admin->toDateString(),
            'data' => $rows,
            'actionable_count' => count($actionable),
        ]);
    }

    public function confirm(Request $request, GradePromotionService $service)
    {
        $user = $request->attributes->get('auth_user');
        if (!$user) {
            return response()->json(['message' => 'Unauthorized'], 401);
        }

        $campusIds = $request->attributes->get('auth_campus_ids', []);
        $data = $request->validate([
            'branch_id' => 'required|integer|min:1',
            'season_year' => 'nullable|integer|min:2000|max:2100',
            'idempotency_key' => 'required|string|min:8|max:64',
            'exclude_student_ids' => 'nullable|array',
            'exclude_student_ids.*' => 'integer|min:1',
            'corrections' => 'nullable|array',
        ]);

        $campusId = (int) $data['branch_id'];
        if (!in_array($campusId, array_map('intval', (array) $campusIds), true)) {
            return response()->json(['message' => 'Forbidden'], 403);
        }

        $seasonYear = isset($data['season_year'])
            ? (int) $data['season_year']
            : $service->defaultSeasonYear();

        $corrections = [];
        foreach ((array) ($data['corrections'] ?? []) as $sid => $corr) {
            if (!is_array($corr)) {
                continue;
            }
            $corrections[(int) $sid] = $corr;
        }

        $result = $service->confirm(
            $campusId,
            $seasonYear,
            (string) $data['idempotency_key'],
            (int) $user->getKey(),
            array_map('intval', (array) ($data['exclude_student_ids'] ?? [])),
            $corrections
        );

        $batch = $result['batch'];
        $status = $result['replayed'] ? 200 : 201;

        return response()->json([
            'batch_id' => (int) $batch->id,
            'campus_id' => (int) $batch->campus_id,
            'season_year' => (int) $batch->season_year,
            'idempotency_key' => (string) $batch->idempotency_key,
            'replayed' => (bool) $result['replayed'],
            'summary' => $batch->summary,
            'results' => $result['results'],
        ], $status);
    }
}
