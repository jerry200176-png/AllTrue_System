<?php

namespace Tests\Feature;

use App\Models\ClassSession;
use App\Models\LearningRecord;
use App\Models\Student;
use App\Models\StudentClass;
use App\Models\StudentSignIn;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class MonthlyLeaveBoundaryInventoryTest extends TestCase
{
    use RefreshDatabase;

    public function test_inventory_reports_out_of_boundary_session_and_does_not_write(): void
    {
        $student = Student::create([
            'name' => 'inventory-test', 'CampusID' => 7, 'ClassID' => 1,
            'enable' => 1, 'MDT' => now(), 'Notify_Token' => '',
        ]);
        $course = StudentClass::create([
            'StudentID' => $student->id, 'GradeID' => 1, 'SubjectID' => 1,
            'TeacherID' => 1, 'by1' => 1, 'Period' => 4,
            'StartDate' => '2026-09-01', 'EndDate' => '2026-09-30',
            'TotalHours' => 10, 'Charge' => 1000, 'Paid' => 0, 'Rate' => 1000,
            'MDate' => now(), 'Stop' => 0, 'ScheduleMode' => 'date',
            'SessionCount' => 5, 'SessionDuration' => 120,
            'RemainingSessions' => 0, 'ClassType' => 'one_on_one', 'UsedSessions' => 0,
        ]);
        $session = ClassSession::create([
            'StudentClassID' => $course->ID, 'SessionDate' => '2026-10-01',
            'StartTime' => '18:00', 'EndTime' => '20:00', 'Status' => 'attended',
        ]);
        StudentSignIn::create([
            'StudentClassID' => $course->ID, 'StudentID' => $student->id,
            'CampusID' => 7, 'ClassSessionID' => $session->id,
            'SignInDT' => '2026-10-01 18:00:00', 'SignOutDT' => '2026-10-01 20:00:00',
            'Status' => 'present', 'PersonType' => 'student',
        ]);
        LearningRecord::create([
            'StudentClassID' => $course->ID, 'ClassSessionID' => $session->id,
            'TeacherID' => 1, 'Content' => '', 'Subject' => 'test',
            'SessionDate' => '2026-10-01', 'StartTime' => '18:00', 'EndTime' => '20:00',
            'Status' => 'pending',
        ]);

        $before = [
            'courses' => StudentClass::count(),
            'sessions' => ClassSession::count(),
            'signins' => StudentSignIn::count(),
            'learning_records' => LearningRecord::count(),
        ];

        $this->artisan('monthly:leave-boundary-inventory', ['--limit' => 10, '--json' => true])
            ->assertExitCode(0);
        $this->assertSame($before, [
            'courses' => StudentClass::count(),
            'sessions' => ClassSession::count(),
            'signins' => StudentSignIn::count(),
            'learning_records' => LearningRecord::count(),
        ]);
    }
}
