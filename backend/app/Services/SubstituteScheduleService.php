<?php

namespace App\Services;

use Carbon\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Single-session substitute is stored on `schedules`:
 * status=scheduled, original_schedule_id IS NOT NULL, teacher_id = 代課 User id.
 */
class SubstituteScheduleService
{
    public static function resolveSubstituteUserId(int $studentClassId, $sessionDate): ?int
    {
        if ($studentClassId <= 0) {
            return null;
        }
        try {
            $d = Carbon::parse((string) $sessionDate)->toDateString();
        } catch (\Throwable) {
            return null;
        }
        $tid = (int) (DB::table('schedules')
            ->where('student_course_id', $studentClassId)
            ->whereDate('schedule_date', $d)
            ->where('status', 'scheduled')
            ->whereNotNull('original_schedule_id')
            ->orderByDesc('id')
            ->value('teacher_id') ?? 0);

        return $tid > 0 ? $tid : null;
    }

    /**
     * For LearningRecord.TeacherID and similar: prefer substitute when scheduled, else contract teacher.
     */
    public static function effectiveInstructorUserId(int $studentClassId, $sessionDate, int $contractTeacherUserId): int
    {
        $sub = self::resolveSubstituteUserId($studentClassId, $sessionDate);
        if ($sub !== null) {
            return $sub;
        }

        return $contractTeacherUserId > 0 ? $contractTeacherUserId : 0;
    }
}
