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
 * FR-001/004 coverage: reschedule-session precise location via
 * (student_class_id, old_date, old_start_time), 422 on mismatch, fallback
 * preserved when old_start_time is omitted, and synchronous update of
 * the paired schedules substitute rows inside one transaction.
 */
class RescheduleSessionPrecisionTest extends TestCase
{
    use RefreshDatabase;

    public function test_same_day_two_slots_precise_match_moves_only_the_targeted_session(): void
    {
        [$token, $courseId, $morning, $afternoon] = $this->seedTwoSameDaySessions();

        $this->postReschedule($token, [
            'student_class_id' => $courseId,
            'old_date' => '2026-06-01',
            'old_start_time' => '14:00',
            'new_date' => '2026-06-02',
            'start_time' => '17:00',
            'end_time' => '19:00',
        ])->assertOk();

        $morning->refresh();
        $afternoon->refresh();

        $this->assertSame('2026-06-01', substr((string) $morning->SessionDate, 0, 10),
            'Morning session should NOT be moved');
        $this->assertSame('09:00', substr((string) $morning->StartTime, 0, 5));

        $this->assertSame('2026-06-02', substr((string) $afternoon->SessionDate, 0, 10),
            'Afternoon session should be moved to new date');
        $this->assertSame('17:00', substr((string) $afternoon->StartTime, 0, 5));
    }

    public function test_old_start_time_mismatch_returns_422(): void
    {
        // FR-004: when old_start_time is provided but doesn't match any session, return 422.

        [$token, $courseId, , ] = $this->seedTwoSameDaySessions();

        $res = $this->postReschedule($token, [
            'student_class_id' => $courseId,
            'old_date' => '2026-06-01',
            'old_start_time' => '08:00',
            'new_date' => '2026-06-02',
            'start_time' => '17:00',
            'end_time' => '19:00',
        ]);

        $res->assertStatus(422);
        $this->assertStringContainsString('找不到指定堂次', (string) $res->json('message'));
    }

    public function test_omitting_old_start_time_falls_back_to_first_by_date(): void
    {
        [$token, $courseId, $morning, $afternoon] = $this->seedTwoSameDaySessions();

        $this->postReschedule($token, [
            'student_class_id' => $courseId,
            'old_date' => '2026-06-01',
            'new_date' => '2026-06-03',
            'start_time' => '10:00',
            'end_time' => '12:00',
        ])->assertOk();

        $morning->refresh();
        $afternoon->refresh();

        // Exactly one of the two sessions should have moved; the other stays.
        $moved = collect([$morning, $afternoon])
            ->filter(fn ($s) => substr((string) $s->SessionDate, 0, 10) === '2026-06-03')
            ->count();
        $this->assertSame(1, $moved, 'Fallback path must still move exactly one session');
    }

    public function test_reschedule_syncs_substitute_schedules_row_in_same_transaction(): void
    {
        // FR-004: same-day time change must sync the substitute schedules row's start/end times.

        [$token, $courseId, $session, $subTeacherId] = $this->seedSessionWithSubstitute();

        // Pre-condition: substitute row exists with the session's original times.
        $substituteBefore = Schedule::where('student_course_id', $courseId)
            ->whereDate('schedule_date', '2026-07-10')
            ->where('status', 'scheduled')
            ->whereNotNull('original_schedule_id')
            ->first();
        $this->assertNotNull($substituteBefore, 'Seed must include a substitute schedules row');
        $this->assertSame('10:00', substr((string) $substituteBefore->start_time, 0, 5));

        $this->postReschedule($token, [
            'student_class_id' => $courseId,
            'old_date' => '2026-07-10',
            'old_start_time' => '10:00',
            'new_date' => '2026-07-10',
            'start_time' => '17:00',
            'end_time' => '19:00',
        ])->assertOk();

        $session->refresh();
        $this->assertSame('17:00', substr((string) $session->StartTime, 0, 5));

        $substituteAfter = Schedule::where('id', $substituteBefore->id)->first();
        $this->assertSame('17:00', substr((string) $substituteAfter->start_time, 0, 5),
            'FR-004: substitute schedules row start_time must be synced to new time');
        $this->assertSame('19:00', substr((string) $substituteAfter->end_time, 0, 5));

        // FR-004 scope: the rescheduled anchor row stays at the original time as a
        // historical marker; only the substitute (scheduled + original_schedule_id)
        // row follows the ClassSession into its new slot.
        $rescheduledAnchor = Schedule::where('student_course_id', $courseId)
            ->whereDate('schedule_date', '2026-07-10')
            ->where('status', 'rescheduled')
            ->first();
        $this->assertNotNull($rescheduledAnchor);
        $this->assertSame('10:00', substr((string) $rescheduledAnchor->start_time, 0, 5),
            'FR-004: anchor row must be left at the original time, not moved');

        // Also verify ClassSessionController::index JOIN still resolves the substitute
        // teacher_id after the reschedule — this is the main integration guarantee of FR-004.
        $idx = $this->withHeaders([
            'Authorization' => "Bearer {$token}",
            'Accept' => 'application/json',
        ])->getJson("/api/v1/class-sessions?student_class_id={$courseId}&per_page=50");
        $idx->assertOk();
        $row = collect($idx->json('data'))->firstWhere('id', $session->id);
        $this->assertNotNull($row);
        $this->assertSame($subTeacherId, (int) ($row['teacher_id'] ?? 0),
            'Substitute teacher must remain resolved after reschedule');
    }

    public function test_reschedule_without_substitute_is_unaffected(): void
    {
        [$token, $courseId, $session] = $this->seedPlainSession();

        $this->postReschedule($token, [
            'student_class_id' => $courseId,
            'old_date' => '2026-08-01',
            'old_start_time' => '10:00',
            'new_date' => '2026-08-02',
            'start_time' => '17:00',
            'end_time' => '19:00',
        ])->assertOk();

        $session->refresh();
        $this->assertSame('2026-08-02', substr((string) $session->SessionDate, 0, 10));
        $this->assertSame('17:00', substr((string) $session->StartTime, 0, 5));

        // No substitute rows existed; sync helper must be a no-op.
        $this->assertSame(0, Schedule::where('student_course_id', $courseId)->count());
    }

    // ── Helpers ──────────────────────────────────────────────────

    private function postReschedule(string $token, array $payload)
    {
        return $this->withHeaders([
            'Authorization' => "Bearer {$token}",
            'Accept' => 'application/json',
        ])->postJson('/api/v1/learning-records/reschedule-session', $payload);
    }

    private function createDirector(int $campusId = 1): array
    {
        $director = User::create([
            'LoginName' => 'dir-' . uniqid() . '@example.com', 'Name' => '主任', 'PSW' => 'x',
            'type' => 'A', 'phone' => '090' . random_int(1000000, 9999999), 'MustChangePassword' => false,
        ]);
        UserCampus::create(['CampusID' => $campusId, 'UserID' => $director->id, 'Admin' => 1, 'Approved' => 1]);
        $token = bin2hex(random_bytes(16));
        AuthToken::create(['user_id' => $director->id, 'token' => $token, 'expires_at' => now()->addDay()]);
        return [$token, (int) $director->id];
    }

    private function createTeacher(int $campusId = 1, string $prefix = 't'): int
    {
        $t = User::create([
            'LoginName' => "{$prefix}-" . uniqid() . '@example.com', 'Name' => '老師', 'PSW' => 'x',
            'type' => 'T', 'phone' => '091' . random_int(1000000, 9999999), 'MustChangePassword' => false,
        ]);
        UserCampus::create(['CampusID' => $campusId, 'UserID' => $t->id, 'Admin' => 0, 'Approved' => 1]);
        return (int) $t->id;
    }

    private function createCourse(int $teacherId, int $campusId = 1): array
    {
        $student = Student::create([
            'name' => '測試生-' . uniqid(), 'CampusID' => $campusId, 'ClassID' => 1,
            'enable' => 1, 'MDT' => now(), 'Notify_Token' => '',
        ]);
        $sc = StudentClass::create([
            'StudentID' => $student->id, 'GradeID' => 1, 'SubjectID' => 1,
            'TeacherID' => $teacherId, 'ClassType' => 'one_on_one',
            'by1' => 1, 'Period' => 4, 'StartDate' => '2026-01-01', 'TotalHours' => 20,
            'SessionCount' => 10, 'SessionDuration' => 120, 'RemainingSessions' => 10, 'UsedSessions' => 0,
            'Charge' => 1600, 'Pay' => 16000, 'Paid' => 0, 'Rate' => 800, 'Stop' => 0,
            'RoomID' => '1', 'MDate' => now(), 'ScheduleMode' => 'count',
        ]);
        return [(int) $student->id, (int) $sc->ID];
    }

    private function seedTwoSameDaySessions(): array
    {
        [$token, ] = $this->createDirector();
        $teacherId = $this->createTeacher(1, 'reg');
        [, $courseId] = $this->createCourse($teacherId);

        $morning = ClassSession::create([
            'StudentClassID' => $courseId, 'SessionDate' => '2026-06-01',
            'StartTime' => '09:00', 'EndTime' => '11:00', 'Status' => 'scheduled',
        ]);
        $afternoon = ClassSession::create([
            'StudentClassID' => $courseId, 'SessionDate' => '2026-06-01',
            'StartTime' => '14:00', 'EndTime' => '16:00', 'Status' => 'scheduled',
        ]);

        return [$token, $courseId, $morning, $afternoon];
    }

    private function seedPlainSession(): array
    {
        [$token, ] = $this->createDirector();
        $teacherId = $this->createTeacher(1, 'plain');
        [, $courseId] = $this->createCourse($teacherId);

        $session = ClassSession::create([
            'StudentClassID' => $courseId, 'SessionDate' => '2026-08-01',
            'StartTime' => '10:00', 'EndTime' => '12:00', 'Status' => 'scheduled',
        ]);

        return [$token, $courseId, $session];
    }

    private function seedSessionWithSubstitute(): array
    {
        [$token, ] = $this->createDirector();
        $regularId = $this->createTeacher(1, 'main');
        $subId = $this->createTeacher(1, 'sub');
        [$studentId, $courseId] = $this->createCourse($regularId);

        $session = ClassSession::create([
            'StudentClassID' => $courseId, 'SessionDate' => '2026-07-10',
            'StartTime' => '10:00', 'EndTime' => '12:00', 'Status' => 'scheduled',
        ]);

        // Route the substitute through the real API so the schedules rows are in their
        // canonical shape. This guards the FR-004 sync path against layout drift.
        $this->withHeaders([
            'Authorization' => "Bearer {$token}",
            'Accept' => 'application/json',
        ])->postJson("/api/v1/class-sessions/{$session->id}/substitute", [
            'substitute_teacher_id' => $subId,
            'reason' => 'FR-004 test',
        ])->assertOk();

        return [$token, $courseId, $session, $subId];
    }
}
