<?php

namespace App\Http\Controllers\Concerns;

use App\Models\Student;
use App\Models\StudentClass;
use Illuminate\Http\Request;

/** Campus and course scope shared by the learning-plan controllers (#4970): reads see visible courses, writes only owned ones. */
trait ScopesLearningPlanAccess
{
    use ChecksCampusAccess;

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

}
