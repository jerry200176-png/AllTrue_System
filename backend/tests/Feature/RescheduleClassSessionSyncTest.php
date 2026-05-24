<?php

namespace Tests\Feature;

use App\Models\AuthToken;
use App\Models\ClassSession;
use App\Models\Schedule;
use App\Models\Student;
use App\Models\StudentClass;
use App\Models\User;
use App\Models\UserCampus;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Bug #127 regression guard: schedules ↔ ClassSession sync after reschedule.
 *
 * The specific fix: when a 'scheduled' exception row (reschedule destination)
 * is posted via POST /api/v1/schedules, and a ClassSession already exists at
 * that date/time with status='cancelled', reactivate it to 'scheduled'.
 *
 * Without this fix, teachers would see the cancelled slot and miss the new time.
 * The original session lifecycle (cancellation) is handled separately by
 * LearningRecordController::rescheduleSession, which physically moves the session.
 */
class RescheduleClassSessionSyncTest extends TestCase
{
    use RefreshDatabase;

    /**
     * After posting status=scheduled for the new time (13:00), a ClassSession that
     * previously existed as 'cancelled' at 13:00 must be re-activated to 'scheduled'.
     */
    public function test_scheduled_destination_reactivates_cancelled_class_session(): void
    {
        [$token, $courseId] = $this->makeFixture();

        // Simulate a previously cancelled slot at the new destination time
        $newSession = ClassSession::create([
            'StudentClassID' => $courseId,
            'SessionDate'    => '2026-06-10',
            'StartTime'      => '13:00:00',
            'EndTime'        => '15:00:00',
            'Status'         => 'cancelled',
        ]);

        // Director creates a scheduled (make-up/reschedule destination) record
        $this->withToken($token)->postJson('/api/v1/schedules', [
            'student_course_id'  => $courseId,
            'schedule_date'      => '2026-06-10',
            'start_time'         => '13:00',
            'end_time'           => '15:00',
            'day_of_week'        => 2,
            'branch_id'          => 1,
            'status'             => 'scheduled',
            'original_schedule_id' => 999, // non-zero to trigger dedup path
        ])->assertStatus(201);

        $newSession->refresh();
        $this->assertSame('scheduled', $newSession->Status,
            'Cancelled ClassSession at the reschedule destination should be reactivated to scheduled');
    }

    // -------------------------------------------------------------------------
    // Helpers
    // -------------------------------------------------------------------------

    private function makeFixture(): array
    {
        $director = User::create([
            'LoginName' => 'dir-127-' . uniqid() . '@test.com',
            'Name'      => '主任',
            'PSW'       => 'x',
            'type'      => 'A',
            'phone'     => '091' . random_int(1000000, 9999999),
            'MustChangePassword' => false,
        ]);
        UserCampus::create(['CampusID' => 1, 'UserID' => $director->id, 'Admin' => 1, 'Approved' => 1]);
        $raw = bin2hex(random_bytes(16));
        AuthToken::create(['user_id' => $director->id, 'token' => $raw, 'expires_at' => now()->addDay()]);

        $teacher = User::create([
            'LoginName' => 'teacher-127-' . uniqid() . '@test.com',
            'Name'      => '黃芝琳',
            'PSW'       => 'x',
            'type'      => 'T',
            'phone'     => '092' . random_int(1000000, 9999999),
            'MustChangePassword' => false,
        ]);
        UserCampus::create(['CampusID' => 1, 'UserID' => $teacher->id, 'Admin' => 0, 'Approved' => 1]);

        $student = Student::create([
            'name' => '王品方-' . uniqid(),
            'CampusID' => 1,
            'ClassID' => 1,
            'SchoolName' => 'TestSchool',
            'enable' => 1,
            'MDT' => now(),
            'Notify_Token' => '',
        ]);

        $sc = StudentClass::create([
            'StudentID'       => $student->id,
            'TeacherID'       => $teacher->id,
            'GradeID'         => 7,
            'SubjectID'       => 1,
            'ClassType'       => 'one_on_one',
            'Rate'            => 1100,
            'SessionCount'    => 8,
            'RemainingSessions' => 8,
            'UsedSessions'    => 0,
            'Charge'          => 8800,
            'Pay'             => 8800,
            'Paid'            => 0,
            'Stop'            => 0,
            'by1'             => 1,
            'Period'          => 4,
            'StartDate'       => '2026-06-01',
            'TotalHours'      => 16,
            'SessionDuration' => 120,
            'MDate'           => now(),
            'ScheduleMode'    => 'count',
            'RoomID'          => '1',
        ]);

        return [$raw, (int) $sc->ID];
    }
}
