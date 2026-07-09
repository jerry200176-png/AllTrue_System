<?php

namespace Tests\Feature;

use App\Models\ClassSession;
use App\Models\Student;
use App\Models\StudentClass;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Tests\TestCase;

/**
 * Revenue-integrity guard for #1062: a course with unused paid sessions but no
 * upcoming class session must be flagged; one that is exhausted, stopped, or
 * already has an upcoming session must not.
 */
class AuditStrandedPaidSessionsTest extends TestCase
{
    use RefreshDatabase;

    private function student(int $campusId = 1): Student
    {
        return Student::create([
            'name' => '稽核測試生',
            'CampusID' => $campusId,
            'ClassID' => 1,
            'enable' => 1,
            'MDT' => now(),
            'Notify_Token' => '',
        ]);
    }

    private function course(int $studentId, array $overrides = []): StudentClass
    {
        return StudentClass::create(array_merge([
            'StudentID' => $studentId,
            'GradeID' => 1,
            'SubjectID' => 1,
            'TeacherID' => 99,
            'by1' => 1,
            'Period' => 4,
            'StartDate' => now(),
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
        ], $overrides));
    }

    public function test_flags_active_course_with_remaining_but_no_upcoming_session(): void
    {
        $s = $this->student();
        $stranded = $this->course($s->id, ['RemainingSessions' => 5]);

        // Exhausted course — must NOT be flagged.
        $this->course($s->id, ['RemainingSessions' => 0]);
        // Stopped course with balance — must NOT be flagged.
        $this->course($s->id, ['RemainingSessions' => 9, 'Stop' => 1]);
        // Course WITH an upcoming session — must NOT be flagged.
        $scheduled = $this->course($s->id, ['RemainingSessions' => 4]);
        ClassSession::create([
            'StudentClassID' => $scheduled->ID,
            'SessionDate' => Carbon::tomorrow()->toDateString(),
            'StartTime' => '17:00:00',
            'EndTime' => '18:00:00',
            'Status' => 'scheduled',
        ]);

        $code = Artisan::call('sessions:audit-stranded', ['--branch_id' => 1, '--json' => true]);
        $out = Artisan::output();
        $this->assertSame(0, $code);
        $this->assertStringContainsString('"stranded_courses":1', $out);
        $this->assertStringContainsString('"stranded_sessions":5', $out);
    }

    public function test_past_only_session_still_counts_as_stranded(): void
    {
        $s = $this->student();
        $course = $this->course($s->id, ['RemainingSessions' => 3]);
        // Only a PAST session exists — the course is not scheduled ahead.
        ClassSession::create([
            'StudentClassID' => $course->ID,
            'SessionDate' => Carbon::yesterday()->toDateString(),
            'StartTime' => '17:00:00',
            'EndTime' => '18:00:00',
            'Status' => 'attended',
        ]);

        $code = Artisan::call('sessions:audit-stranded', ['--branch_id' => 1, '--json' => true]);
        $this->assertSame(0, $code);
        $this->assertStringContainsString('"stranded_courses":1', Artisan::output());
    }

    public function test_cancelled_upcoming_session_does_not_rescue_course(): void
    {
        $s = $this->student();
        $course = $this->course($s->id, ['RemainingSessions' => 2]);
        ClassSession::create([
            'StudentClassID' => $course->ID,
            'SessionDate' => Carbon::tomorrow()->toDateString(),
            'StartTime' => '17:00:00',
            'EndTime' => '18:00:00',
            'Status' => 'cancelled',
        ]);

        $code = Artisan::call('sessions:audit-stranded', ['--branch_id' => 1, '--json' => true]);
        $this->assertSame(0, $code);
        $this->assertStringContainsString('"stranded_courses":1', Artisan::output());
    }
}
