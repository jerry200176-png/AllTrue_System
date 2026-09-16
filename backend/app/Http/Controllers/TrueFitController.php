<?php

namespace App\Http\Controllers;

use App\Services\TrueFitService;
use App\Services\TrueFitTodaySessionsReadService;
use Illuminate\Http\Request;

class TrueFitController extends Controller
{
    /**
     * Pure-read today view for the authenticated teacher's eligible sessions.
     * Merges materialized ClassSession rows with contract projections and
     * schedule-exception slots without invoking index auto-materialization.
     */
    public function todaySessions(Request $request)
    {
        if (!TrueFitService::enabled()) {
            return response()->json(['message' => 'Not found'], 404);
        }

        $role = (string) $request->attributes->get('auth_role');
        if ($role !== 'teacher') {
            return response()->json(['message' => 'Forbidden'], 403);
        }

        $teacherId = (int) ($request->attributes->get('auth_teacher_id') ?? 0);
        if ($teacherId <= 0) {
            return response()->json(['message' => 'Teacher not linked'], 403);
        }

        $requestedCampus = (int) ($request->input('branch_id') ?? $request->input('campus_id') ?? 0);
        if ($requestedCampus > 0) {
            $campusIds = $request->attributes->get('auth_campus_ids', []);
            if (!empty($campusIds) && !in_array($requestedCampus, $campusIds, true)) {
                return response()->json(['message' => 'Forbidden: branch not accessible'], 403);
            }
        }

        $payload = app(TrueFitTodaySessionsReadService::class)->fetchTodaySessions($request, $teacherId);

        return response()->json($payload);
    }
}
