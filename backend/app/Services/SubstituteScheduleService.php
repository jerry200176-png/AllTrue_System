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
    public static function resolveSubstituteUserId(int $studentClassId, $sessionDate, ?string $startTime = null): ?int
    {
        if ($studentClassId <= 0) {
            return null;
        }
        try {
            $d = Carbon::parse((string) $sessionDate)->toDateString();
        } catch (\Throwable) {
            return null;
        }
        $query = DB::table('schedules')
            ->where('student_course_id', $studentClassId)
            ->whereDate('schedule_date', $d)
            ->where('status', 'scheduled')
            ->whereNotNull('original_schedule_id');

        $normalizedStart = self::normalizeTime($startTime);
        if ($normalizedStart !== null) {
            $query->whereRaw('SUBSTRING(start_time, 1, 5) = ?', [$normalizedStart]);
        }

        $tid = (int) ($query->orderByDesc('id')->value('teacher_id') ?? 0);

        return $tid > 0 ? $tid : null;
    }

    /**
     * For LearningRecord.TeacherID and similar: prefer substitute when scheduled, else contract teacher.
     */
    public static function effectiveInstructorUserId(int $studentClassId, $sessionDate, int $contractTeacherUserId, ?string $startTime = null): int
    {
        $sub = self::resolveSubstituteUserId($studentClassId, $sessionDate, $startTime);
        if ($sub !== null) {
            return $sub;
        }

        return $contractTeacherUserId > 0 ? $contractTeacherUserId : 0;
    }

    private static function normalizeTime(?string $value): ?string
    {
        $value = trim((string) $value);
        if ($value === '') {
            return null;
        }

        if (preg_match('/^(\d{1,2}:\d{2})/', $value, $m)) {
            return $m[1];
        }

        return null;
    }
}
