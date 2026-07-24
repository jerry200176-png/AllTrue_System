<?php

namespace Tests\Feature;

use App\Models\ClassSession;
use App\Models\Student;
use App\Models\StudentClass;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class EarlySettlementLockInvariantTest extends TestCase
{
    use RefreshDatabase;

    public function test_locked_course_rejects_new_sessions(): void
    {
        $course = $this->course();
        $course->update([
            'closed_reason' => 'usage_settled',
            'settlement_locked_at' => now(),
        ]);

        $this->expectException(ValidationException::class);
        $this->classSession((int) $course->ID);
    }

    public function test_locked_course_rejects_attendance_changes(): void
    {
        $course = $this->course();
        $session = $this->classSession((int) $course->ID);
        $course->update([
            'closed_reason' => 'usage_settled',
            'settlement_locked_at' => now(),
        ]);

        $this->expectException(ValidationException::class);
        $session->update(['Status' => 'attended']);
    }

    private function course(): StudentClass
    {
        $student = Student::create([
            'name' => '結清鎖定測試生',
            'CampusID' => 1,
            'ClassID' => 1,
            'SchoolName' => 'Test School',
            'enable' => 1,
            'MDT' => now(),
            'Notify_Token' => '',
        ]);

        return StudentClass::create([
            'StudentID' => $student->id,
            'GradeID' => 1,
            'SubjectID' => 1,
            'TeacherID' => 99,
            'by1' => 1,
            'Period' => 4,
            'StartDate' => '2026-07-01',
            'TotalHours' => 20,
            'Charge' => 0,
            'Paid' => 0,
            'Rate' => 0,
            'MDate' => now(),
            'Stop' => 0,
            'ScheduleMode' => 'count',
            'SessionCount' => 8,
            'SessionDuration' => 60,
            'RemainingSessions' => 8,
            'ClassType' => 'one_on_one',
            'UsedSessions' => 0,
        ]);
    }

    private function classSession(int $courseId): ClassSession
    {
        return ClassSession::create([
            'StudentClassID' => $courseId,
            'SessionDate' => '2026-07-25',
            'StartTime' => '10:00',
            'EndTime' => '11:00',
            'Status' => 'scheduled',
        ]);
    }
}
