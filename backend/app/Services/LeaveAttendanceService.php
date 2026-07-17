<?php

namespace App\Services;

use App\Models\ClassSession;
use App\Models\StudentClass;
use App\Models\StudentSignIn;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Log;

class LeaveAttendanceService
{
    public function createClosedForSession(
        ClassSession $session,
        StudentClass $course,
        ?int $recordedByUserId = null,
        ?int $teacherId = null,
        ?int $campusId = null
    ): StudentSignIn {
        [$startsAt, $endsAt] = $this->sessionInterval($session);

        /** @var StudentSignIn $created */
        $created = StudentSignIn::query()->create([
            'StudentClassID' => (int) $course->getAttribute('ID'),
            'StudentID' => (int) $course->getAttribute('StudentID'),
            'TeacherID' => $teacherId ?: ((int) ($course->getAttribute('TeacherID') ?? 0) ?: null),
            'RecordedByUserID' => $recordedByUserId ?: null,
            'GradeID' => $course->getAttribute('GradeID'),
            'SubjectID' => $course->getAttribute('SubjectID'),
            'Get1byID' => $course->getAttribute('by1'),
            'CampusID' => $campusId ?: null,
            'SignInDT' => $startsAt->toDateTimeString(),
            'SignOutDT' => $endsAt->toDateTimeString(),
            'MDT' => now(),
            'ClassSessionID' => (int) $session->id,
            'Status' => 'leave',
            'PersonType' => 'student',
            'SessionDeducted' => false,
        ]);

        return $created;
    }

    /**
     * Close an existing open row before changing it to leave. Rows with a
     * ClassSession use the scheduled end; legacy self-study rows get a bounded
     * duration so a status edit cannot create an unbounded presence interval.
     */
    public function closeExistingForLeave(StudentSignIn $signIn): void
    {
        if ($signIn->getAttribute('SignOutDT') !== null) {
            return;
        }

        $classSessionId = (int) ($signIn->getAttribute('ClassSessionID') ?? 0);
        /** @var ClassSession|null $session */
        $session = $classSessionId > 0
            ? ClassSession::query()->find($classSessionId)
            : null;

        if ($session) {
            [, $endsAt] = $this->sessionInterval($session);
            $signIn->setAttribute('SignOutDT', $endsAt->toDateTimeString());
            return;
        }

        $startsAt = CarbonImmutable::parse((string) $signIn->getAttribute('SignInDT'));
        $hours = max(1, (int) ($signIn->getAttribute('Hours') ?? 0));
        $signIn->setAttribute('SignOutDT', $startsAt->addHours($hours)->toDateTimeString());

        Log::warning('leave_attendance_interval_fallback', [
            'reason' => 'class_session_missing',
            'duration_hours' => $hours,
        ]);
    }

    /** @return array{0:CarbonImmutable,1:CarbonImmutable} */
    private function sessionInterval(ClassSession $session): array
    {
        $date = substr((string) $session->getAttribute('SessionDate'), 0, 10);
        $startTime = trim((string) $session->getAttribute('StartTime'));
        $endTime = trim((string) $session->getAttribute('EndTime'));

        if ($date === '' || $startTime === '' || $endTime === '') {
            throw new \InvalidArgumentException('請假堂次缺少完整起訖時間，未建立出缺勤記錄。');
        }

        $startsAt = CarbonImmutable::parse("{$date} {$startTime}");
        $endsAt = CarbonImmutable::parse("{$date} {$endTime}");
        if ($endsAt->lessThanOrEqualTo($startsAt)) {
            $endsAt = $endsAt->addDay();
        }

        return [$startsAt, $endsAt];
    }
}
