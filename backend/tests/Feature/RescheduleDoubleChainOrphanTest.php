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
 * Regression for GitHub #2001: rescheduling a session a *second* time, away
 * from a slot that is itself the target of an earlier atomic reschedule,
 * must reuse that slot's existing `schedules` row as the new anchor instead
 * of inserting a disconnected duplicate.
 *
 * Real production case: 陳暉諺/劉祉希 course moved 8/22→8/21 (atomic), then
 * 8/21→8/22 again (atomic). The second reschedule left the first target row
 * (schedules.id=8578, status='scheduled') orphaned forever — every busy-slot
 * / conflict-detection query kept reading it as a live occupied slot even
 * though the class had actually moved on and was already attended elsewhere.
 */
class RescheduleDoubleChainOrphanTest extends TestCase
{
    use RefreshDatabase;

    public function test_second_atomic_reschedule_reuses_prior_target_as_anchor_not_orphan(): void
    {
        [$token, $courseId] = $this->seedCourse();

        ClassSession::create([
            'StudentClassID' => $courseId, 'SessionDate' => '2026-08-22',
            'StartTime' => '19:00', 'EndTime' => '21:00', 'Status' => 'scheduled',
        ]);

        // First reschedule: 8/22 -> 8/21 (atomic, creates anchor #1 + target #1).
        $this->postReschedule($token, [
            'student_class_id' => $courseId,
            'old_date' => '2026-08-22',
            'old_start_time' => '19:00',
            'new_date' => '2026-08-21',
            'start_time' => '19:00',
            'end_time' => '21:00',
            'ensure_schedule_exception' => true,
        ])->assertOk();

        $firstTarget = Schedule::where('student_course_id', $courseId)
            ->where('status', 'scheduled')->whereDate('schedule_date', '2026-08-21')->first();
        $this->assertNotNull($firstTarget, 'first reschedule should produce a live target row at 8/21');

        // Second reschedule: 8/21 -> 8/22 again (atomic), moving away from the
        // slot that IS the first reschedule's target.
        $this->postReschedule($token, [
            'student_class_id' => $courseId,
            'old_date' => '2026-08-21',
            'old_start_time' => '19:00',
            'new_date' => '2026-08-22',
            'start_time' => '17:30',
            'end_time' => '19:30',
            'ensure_schedule_exception' => true,
        ])->assertOk();

        $firstTarget->refresh();
        $this->assertSame(
            'rescheduled',
            $firstTarget->status,
            'the first target row must be reused/flipped to rescheduled, not left as a live scheduled ghost'
        );

        $liveScheduled = Schedule::where('student_course_id', $courseId)
            ->where('status', 'scheduled')->get();
        $this->assertCount(1, $liveScheduled, 'exactly one live scheduled row should exist for this course');
        $this->assertSame('2026-08-22', substr((string) $liveScheduled->first()->schedule_date, 0, 10));

        // No duplicate anchor was created at the 8/21 19:00 slot.
        $this->assertSame(1, Schedule::where('student_course_id', $courseId)
            ->whereDate('schedule_date', '2026-08-21')
            ->whereRaw('SUBSTRING(start_time, 1, 5) = ?', ['19:00'])
            ->count());
    }

    private function postReschedule(string $token, array $payload)
    {
        return $this->withHeaders([
            'Authorization' => "Bearer {$token}",
            'Accept' => 'application/json',
        ])->postJson('/api/v1/learning-records/reschedule-session', $payload);
    }

    private function seedCourse(int $campusId = 1): array
    {
        $director = User::create([
            'LoginName' => 'dir-2001-' . uniqid() . '@example.com', 'Name' => '主任', 'PSW' => 'x',
            'type' => 'A', 'phone' => '090' . random_int(1000000, 9999999), 'MustChangePassword' => false,
        ]);
        UserCampus::create(['CampusID' => $campusId, 'UserID' => $director->id, 'Admin' => 1, 'Approved' => 1]);
        $token = bin2hex(random_bytes(16));
        AuthToken::create(['user_id' => $director->id, 'token' => $token, 'expires_at' => now()->addDay()]);

        $teacher = User::create([
            'LoginName' => 't-2001-' . uniqid() . '@example.com', 'Name' => '老師', 'PSW' => 'x',
            'type' => 'T', 'phone' => '091' . random_int(1000000, 9999999), 'MustChangePassword' => false,
        ]);
        UserCampus::create(['CampusID' => $campusId, 'UserID' => $teacher->id, 'Admin' => 0, 'Approved' => 1]);

        $student = Student::create([
            'name' => '調課測試生-' . uniqid(), 'CampusID' => $campusId, 'ClassID' => 1,
            'enable' => 1, 'MDT' => now(), 'Notify_Token' => '',
        ]);
        $sc = StudentClass::create([
            'StudentID' => $student->id, 'GradeID' => 1, 'SubjectID' => 1,
            'TeacherID' => $teacher->id, 'ClassType' => 'one_on_one',
            'by1' => 1, 'Period' => 4, 'StartDate' => '2026-01-01', 'TotalHours' => 20,
            'SessionCount' => 10, 'SessionDuration' => 120, 'RemainingSessions' => 10, 'UsedSessions' => 0,
            'Charge' => 1600, 'Pay' => 16000, 'Paid' => 0, 'Rate' => 800, 'Stop' => 0,
            'MDate' => now(), 'ScheduleMode' => 'count',
        ]);

        return [$token, (int) $sc->ID];
    }
}
