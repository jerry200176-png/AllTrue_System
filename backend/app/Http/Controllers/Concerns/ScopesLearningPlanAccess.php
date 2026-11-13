<?php

namespace App\Http\Controllers\Concerns;

use App\Models\StudentClass;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/** Campus and course scope shared by the learning-plan controllers (#4970): reads see visible courses, writes only owned ones. */
trait ScopesLearningPlanAccess
{
    private function teacherId(Request $request): ?int
    {
        return $request->attributes->get('auth_role') === 'teacher' ? max(0, (int) $request->attributes->get('auth_teacher_id')) : null;
    }

    /** Director/super_admin: any course in their campus. Teacher: only the courses they own. */
    private function courseWritable(Request $request, int $courseId): bool
    {
        $teacherId = $this->teacherId($request);

        return $teacherId === null || ($teacherId > 0 && StudentClass::query()->where('ID', $courseId)->where('TeacherID', $teacherId)->exists());
    }

    private function campusAllowed(Request $request, int $campusId): bool
    {
        return $campusId > 0 && ($request->attributes->get('auth_role') === 'super_admin'
            || in_array($campusId, array_map('intval', (array) $request->attributes->get('auth_campus_ids', [])), true));
    }

    private function forbidden(): JsonResponse
    {
        return response()->json(['message' => 'Forbidden'], 403);
    }
}
