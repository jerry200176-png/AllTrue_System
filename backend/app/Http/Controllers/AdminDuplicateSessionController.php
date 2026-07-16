<?php

namespace App\Http\Controllers;

use App\Services\DuplicateSessionService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

class AdminDuplicateSessionController extends Controller
{
    public function __construct(
        private readonly DuplicateSessionService $duplicateSessionService
    ) {
    }

    /**
     * GET /api/v1/admin/duplicate-sessions/p2-review
     * Return cross-SC duplicate groups for director review.
     */
    public function p2Review(Request $request)
    {
        $campusIds = $request->attributes->get('auth_role') === 'super_admin'
            ? []
            : array_map('intval', (array) $request->attributes->get('auth_campus_ids', []));
        $result = $this->duplicateSessionService->p2ReviewGroups($campusIds);

        return response()->json([
            'data' => $result,
        ]);
    }

    /**
     * PATCH /api/v1/admin/duplicate-sessions/p2-review/{groupId}
     * Combined decide + execute in one step.
     *
     * The groupId is a base64url-encoded composite of {student_id}:{date}:{time}.
     */
    public function patchP2Review(Request $request, string $groupId)
    {
        $request->validate([
            'keep_student_class_id' => 'required|integer',
            'reason' => 'nullable|string|max:500',
        ]);

        try {
            [$studentId, $date, $time] = $this->duplicateSessionService->decodeGroupId($groupId);
        } catch (\InvalidArgumentException $e) {
            return response()->json(['message' => '無效的群組識別碼'], 400);
        }

        try {
            $campusIds = $request->attributes->get('auth_role') === 'super_admin'
                ? []
                : array_map('intval', (array) $request->attributes->get('auth_campus_ids', []));
            $result = $this->duplicateSessionService->decideAndExecute(
                studentId: $studentId,
                date: $date,
                time: $time,
                keepStudentClassId: (int) $request->input('keep_student_class_id'),
                reason: $request->input('reason'),
                userId: $request->attributes->get('auth_user')?->id,
                campusIds: $campusIds,
            );
        } catch (\InvalidArgumentException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        } catch (\RuntimeException $e) {
            Log::warning('duplicate_session_patch_failed', [
                'group_id' => $groupId,
                'error' => $e->getMessage(),
            ]);

            return response()->json(['message' => $e->getMessage()], 404);
        }

        return response()->json([
            'data' => [
                'cancelled_count' => $result['cancelled_count'],
                'kept_session_ids' => $result['kept_session_ids'],
            ],
        ]);
    }
}
