<?php

namespace App\Http\Controllers\Concerns;

use App\Models\Student;
use App\Models\StudentClass;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Campus and teacher scoping shared by the learning-plan and study-task APIs (#4970, #5269).
 * Reads: director, super_admin, a teacher for courses visible to them (StudentClass::visibleToTeacher).
 * Writes: director, super_admin, a teacher only for courses they own (TeacherID).
 */
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

    /** Student in the caller's campus; a teacher also needs a course of this student that is visible to them. */
    private function studentReadable(Request $request, Student $student): bool
    {
        if (!$this->campusAllowed($request, (int) $student->CampusID)) {
            return false;
        }
        $teacherId = $this->teacherId($request);

        return $teacherId === null || ($teacherId > 0
            && StudentClass::query()->where('StudentID', $student->id)->visibleToTeacher($teacherId)->exists());
    }

    /** Student in the caller's campus; a teacher also needs a course of this student that they own. */
    private function studentWritable(Request $request, Student $student): bool
    {
        if (!$this->campusAllowed($request, (int) $student->CampusID)) {
            return false;
        }
        $teacherId = $this->teacherId($request);

        return $teacherId === null || ($teacherId > 0
            && StudentClass::query()->where('StudentID', $student->id)->where('TeacherID', $teacherId)->exists());
    }

    private function forbidden(): JsonResponse
    {
        return response()->json(['message' => 'Forbidden'], 403);
    }
}
