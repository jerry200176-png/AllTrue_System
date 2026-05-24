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
 * When a director reschedules a class (old time → new time on same day),
 * the ScheduleController must keep ClassSession in sync:
 *  1. Original ClassSession (old start_time, status=scheduled) → cancelled.
 *  2. Reschedule destination ClassSession (new start_time, status=cancelled) → scheduled.
 *
 * Without the fix, teachers see the wrong time in LearningRecordsPage.
 */
class RescheduleClassSessionSyncTest extends TestCase
{
    use RefreshDatabase;

    /**
     * After posting status=rescheduled for 15:00, the ClassSession at 15:00 (scheduled)
     * must be cancelled so teachers no longer see it.
     */
    public function test_rescheduled_schedule_cancels_original_class_session(): void
    {
        [$token, $courseId] = $this->makeFixture();

        $oldSession = ClassSession::create([
            'StudentClassID' => $courseId,
            'SessionDate'    => '2026-06-10',
            'StartTime'      => '15:00:00',
            'EndTime'        => '17:00:00',
            'Status'         => 'scheduled',
        ]);

        $this->withToken($token)->postJson('/api/v1/schedules', [
            'student_course_id' => $courseId,
            'schedule_date'     => '2026-06-10',
            'start_time'        => '15:00',
            'end_time'          => '17:00',
            'day_of_week'       => 2,
            'branch_id'         => 1,
            'status'            => 'rescheduled',
        ])->assertStatus(201);

        $oldSession->refresh();
        $this->assertSame('cancelled', $oldSession->Status,
            'Original ClassSession should be cancelled after marking schedule as rescheduled');
    }

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

    /**
     * Attended sessions must NEVER be cancelled by a reschedule operation (safety guard).
     */
    public function test_attended_class_session_is_never_cancelled_by_reschedule(): void
    {
        [$token, $courseId] = $this->makeFixture();

        $attendedSession = ClassSession::create([
            'StudentClassID' => $courseId,
            'SessionDate'    => '2026-06-10',
            'StartTime'      => '15:00:00',
            'EndTime'        => '17:00:00',
            'Status'         => 'attended',
        ]);

        $this->withToken($token)->postJson('/api/v1/schedules', [
            'student_course_id' => $courseId,
            'schedule_date'     => '2026-06-10',
            'start_time'        => '15:00',
            'end_time'          => '17:00',
            'day_of_week'       => 2,
            'branch_id'         => 1,
            'status'            => 'rescheduled',
        ])->assertStatus(201);

        $attendedSession->refresh();
        $this->assertSame('attended', $attendedSession->Status,
            'Attended ClassSession must NOT be retroactively cancelled by a reschedule');
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
        ]);

        return [$raw, (int) $sc->ID];
    }
}
