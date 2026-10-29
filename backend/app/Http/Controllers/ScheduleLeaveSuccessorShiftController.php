<?php

namespace App\Http\Controllers;

use App\Exceptions\SuccessorShiftRefused;
use App\Services\CourseLeaveCascadeSuccessorShiftService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * In-app #410 / #4152: preview, then apply, a leave whose make-up tail would land in a pre-created renewal.
 * Apply writes only when the re-computed plan still matches the previewed token.
 */
class ScheduleLeaveSuccessorShiftController extends Controller
{
    public function __construct(private CourseLeaveCascadeSuccessorShiftService $service)
    {
    }

    public function preview(Request $request): JsonResponse
    {
        $data = $request->validate(['class_session_id' => 'required|integer|min:1']);
        if ($denied = $this->denyOutsideCampus($request, (int) $data['class_session_id'])) {
            return $denied;
        }
        try {
            return response()->json($this->service->preview((int) $data['class_session_id']) + [
                'apply_enabled' => (bool) config('perfflags.leave_successor_shift_apply_enabled', false),
            ]);
        } catch (\InvalidArgumentException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }
    }

    public function apply(Request $request): JsonResponse
    {
        $data = $request->validate([
            'class_session_id' => 'required|integer|min:1',
            'preview_token' => 'required|string|size:64',
        ]);
        if ($denied = $this->denyOutsideCampus($request, (int) $data['class_session_id'])) {
            return $denied;
        }
        $actorId = (int) ($request->attributes->get('auth_user')->id ?? 0);
        try {
            return response()->json($this->service->apply((int) $data['class_session_id'], (string) $data['preview_token'], $actorId));
        } catch (SuccessorShiftRefused $e) {
            return response()->json(['message' => $e->getMessage(), 'plan' => $e->plan()], $e->status());
        } catch (\InvalidArgumentException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }
    }

    private function denyOutsideCampus(Request $request, int $sessionId): ?JsonResponse
    {
        $campusId = DB::table('ClassSession as cs')
            ->join('StudentClass as sc', 'sc.ID', '=', 'cs.StudentClassID')
            ->join('Student as st', 'st.id', '=', 'sc.StudentID')
            ->where('cs.id', $sessionId)
            ->value('st.CampusID');
        if ($campusId === null) {
            return response()->json(['message' => '找不到堂次'], 404);
        }
        if ($request->attributes->get('auth_role') === 'super_admin') {
            return null;
        }
        $campusIds = array_map('intval', (array) $request->attributes->get('auth_campus_ids', []));

        return in_array((int) $campusId, $campusIds, true) ? null : response()->json(['message' => 'Forbidden'], 403);
    }
}
