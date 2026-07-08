<?php

namespace Tests\Feature;

use App\Models\AuthToken;
use App\Models\ClassSession;
use App\Models\LearningRecord;
use App\Models\Schedule;
use App\Models\Student;
use App\Models\StudentClass;
use App\Models\User;
use App\Models\UserCampus;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Regression tests for the substitute/reschedule 4/20-style incident:
 * - Substitute teacher display consistency across endpoints
 * - Reschedule must not create duplicate sessions on target date
 * - Consecutive reschedule A→B→C must not leave orphan copies
 * - Session total count must be conserved after reschedule/substitute
 * - Remap must keep schedules table in sync with ClassSession dates
 */
class SubstituteRescheduleRegressionTest extends TestCase
{
    use RefreshDatabase;

    public function test_substitute_teacher_consistent_across_class_sessions_index(): void
    {
        [$dirToken, $regularTeacherId, $subTeacherId, $session] = $this->seedScenario();
        $courseId = (int) $session->StudentClassID;

        $this->postSubstitute($dirToken, $session->id, $subTeacherId)->assertOk();

        $idx = $this->withHeaders([
            'Authorization' => "Bearer {$dirToken}",
            'Accept' => 'application/json',
        ])->getJson("/api/v1/class-sessions?student_class_id={$courseId}&per_page=100");

        $idx->assertOk();
        $row = collect($idx->json('data'))->firstWhere('id', $session->id);

        $this->assertNotNull($row);
        $this->assertSame($subTeacherId, (int) ($row['teacher_id'] ?? 0),
            'class-sessions index should return substitute teacher_id');
        $this->assertNotEmpty($row['teacher_name'] ?? '');
    }

    public function test_substitute_join_uses_start_time_for_same_day_disambiguation(): void
    {
        [$dirToken, $regularTeacherId, $subTeacherId, $courseId] = $this->seedSameDayTwoSessionScenario();

        $sessions = ClassSession::where('StudentClassID', $courseId)
            ->orderBy('StartTime')
            ->get();

        $this->assertCount(2, $sessions);
        $morningSession = $sessions[0];
        $afternoonSession = $sessions[1];

        $this->postSubstitute($dirToken, $afternoonSession->id, $subTeacherId)->assertOk();

        $idx = $this->withHeaders([
            'Authorization' => "Bearer {$dirToken}",
            'Accept' => 'application/json',
        ])->getJson("/api/v1/class-sessions?student_class_id={$courseId}&per_page=100");

        $idx->assertOk();
        $rows = collect($idx->json('data'));

        $morningRow = $rows->firstWhere('id', $morningSession->id);
        $afternoonRow = $rows->firstWhere('id', $afternoonSession->id);

        $this->assertSame($regularTeacherId, (int) ($morningRow['teacher_id'] ?? 0),
            'Morning session should still show the regular teacher');
        $this->assertSame($subTeacherId, (int) ($afternoonRow['teacher_id'] ?? 0),
            'Afternoon session should show the substitute teacher');
    }

    public function test_reschedule_does_not_create_duplicate_sessions_on_target_date(): void
    {
        [$dirToken, $courseId, $session] = $this->seedRescheduleScenario();

        $countBefore = ClassSession::where('StudentClassID', $courseId)->count();

        $this->doReschedule($dirToken, $courseId, $session, '2026-04-22');

        $targetDateCount = ClassSession::where('StudentClassID', $courseId)
            ->whereDate('SessionDate', '2026-04-22')
            ->whereNotIn('Status', ['cancelled'])
            ->count();

        $this->assertSame(1, $targetDateCount,
            'After reschedule, target date must have exactly 1 active session');
    }

    public function test_consecutive_reschedule_a_b_c_does_not_leave_orphan_copies(): void
    {
        [$dirToken, $courseId, $session] = $this->seedRescheduleScenario();

        $this->doReschedule($dirToken, $courseId, $session, '2026-04-22');

        $movedSession = ClassSession::where('StudentClassID', $courseId)
            ->whereDate('SessionDate', '2026-04-22')
            ->whereNotIn('Status', ['cancelled'])
            ->first();
        $this->assertNotNull($movedSession);

        $this->doReschedule($dirToken, $courseId, $movedSession, '2026-04-25');

        $dateACounts = ClassSession::where('StudentClassID', $courseId)
            ->whereDate('SessionDate', '2026-04-20')
            ->whereNotIn('Status', ['cancelled', 'leave', 'leave_adjusted'])
            ->count();
        $dateBCounts = ClassSession::where('StudentClassID', $courseId)
            ->whereDate('SessionDate', '2026-04-22')
            ->whereNotIn('Status', ['cancelled', 'leave', 'leave_adjusted'])
            ->count();
        $dateCCounts = ClassSession::where('StudentClassID', $courseId)
            ->whereDate('SessionDate', '2026-04-25')
            ->whereNotIn('Status', ['cancelled', 'leave', 'leave_adjusted'])
            ->count();

        $this->assertSame(0, $dateACounts, 'Original date A should have 0 active sessions after move');
        $this->assertSame(0, $dateBCounts, 'Intermediate date B should have 0 active sessions after second move');
        $this->assertSame(1, $dateCCounts, 'Final date C should have exactly 1 active session');
    }

    public function test_session_total_count_preserved_after_reschedule_and_substitute(): void
    {
        [$dirToken, $regularTeacherId, $subTeacherId, $session] = $this->seedScenario();
        $courseId = (int) $session->StudentClassID;

        $totalBefore = ClassSession::where('StudentClassID', $courseId)
            ->whereNotIn('Status', ['cancelled'])
            ->count();

        $this->postSubstitute($dirToken, $session->id, $subTeacherId)->assertOk();

        $sessions = ClassSession::where('StudentClassID', $courseId)
            ->where('Status', 'scheduled')
            ->orderBy('SessionDate')
            ->get();
        if ($sessions->count() > 1) {
            $target = $sessions->last();
            $this->doReschedule($dirToken, $courseId, $target, '2026-05-10');
        }

        $totalAfter = ClassSession::where('StudentClassID', $courseId)
            ->whereNotIn('Status', ['cancelled'])
            ->count();

        $this->assertSame($totalBefore, $totalAfter,
            'Total non-cancelled session count must not change after substitute+reschedule');
    }

    public function test_schedule_store_does_not_create_duplicate_scheduled_rows(): void
    {
        [$dirToken, $courseId, $session] = $this->seedRescheduleScenario();

        $this->doReschedule($dirToken, $courseId, $session, '2026-04-22');

        $scheduledCount = Schedule::where('student_course_id', $courseId)
            ->whereDate('schedule_date', '2026-04-22')
            ->where('status', 'scheduled')
            ->count();

        $this->assertSame(1, $scheduledCount, 'Should have exactly 1 scheduled record on target date');

        $rescheduledRow = Schedule::where('student_course_id', $courseId)
            ->whereDate('schedule_date', '2026-04-20')
            ->where('status', 'rescheduled')
            ->first();
        $this->assertNotNull($rescheduledRow);

        $token = $this->getDirectorToken($dirToken);
        $this->withHeaders([
            'Authorization' => "Bearer {$dirToken}",
            'Accept' => 'application/json',
        ])->postJson('/api/v1/schedules', [
            'student_id' => (int) Student::first()->id,
            'teacher_id' => null,
            'subject' => 'Math',
            'day_of_week' => 3,
            'start_time' => '14:00',
            'end_time' => '16:00',
            'duration_hours' => 2,
            'class_type' => 'one_on_one',
            'status' => 'scheduled',
            'type' => 'normal',
            'deduction' => 1,
            'branch_id' => 1,
            'schedule_date' => '2026-04-22',
            'student_course_id' => $courseId,
            'original_schedule_id' => $rescheduledRow->id,
        ]);

        $scheduledCountAfter = Schedule::where('student_course_id', $courseId)
            ->whereDate('schedule_date', '2026-04-22')
            ->where('status', 'scheduled')
            ->count();

        $this->assertSame(1, $scheduledCountAfter,
            'Duplicate POST should upsert, not create a second row');
    }

    // ── Helpers ──────────────────────────────────────────────────

    private function seedScenario(): array
    {
        $campusId = 1;

        $director = User::create([
            'LoginName' => 'dir-reg@example.com', 'Name' => '主任Reg', 'PSW' => 'x',
            'type' => 'A', 'phone' => '0900200001', 'MustChangePassword' => false,
        ]);
        UserCampus::create(['CampusID' => $campusId, 'UserID' => $director->id, 'Admin' => 1, 'Approved' => 1]);
        $dirToken = bin2hex(random_bytes(16));
        AuthToken::create(['user_id' => $director->id, 'token' => $dirToken, 'expires_at' => now()->addDay()]);

        $regular = User::create([
            'LoginName' => 'reg-teacher@example.com', 'Name' => '正班Reg', 'PSW' => 'x',
            'type' => 'T', 'phone' => '0900200002', 'MustChangePassword' => false,
        ]);
        UserCampus::create(['CampusID' => $campusId, 'UserID' => $regular->id, 'Admin' => 0, 'Approved' => 1]);

        $sub = User::create([
            'LoginName' => 'sub-reg@example.com', 'Name' => '代課Reg', 'PSW' => 'x',
            'type' => 'T', 'phone' => '0900200003', 'MustChangePassword' => false,
        ]);
        UserCampus::create(['CampusID' => $campusId, 'UserID' => $sub->id, 'Admin' => 0, 'Approved' => 1]);

        $student = Student::create([
            'name' => '回歸測試生', 'CampusID' => $campusId, 'ClassID' => 1,
            'enable' => 1, 'MDT' => now(), 'Notify_Token' => '',
        ]);
        $sc = StudentClass::create([
            'StudentID' => $student->id, 'GradeID' => 1, 'SubjectID' => 1,
            'TeacherID' => $regular->id, 'ClassType' => 'one_on_one',
            'by1' => 1, 'Period' => 4, 'StartDate' => '2026-04-01', 'TotalHours' => 20,
            'SessionCount' => 10, 'SessionDuration' => 120, 'RemainingSessions' => 8, 'UsedSessions' => 2,
            'Charge' => 1600, 'Pay' => 16000, 'Paid' => 0, 'Rate' => 800, 'Stop' => 0,
            'MDate' => now(), 'ScheduleMode' => 'count',
        ]);

        ClassSession::create(['StudentClassID' => $sc->ID, 'SessionDate' => '2026-04-07', 'StartTime' => '14:00', 'EndTime' => '16:00', 'Status' => 'attended']);
        ClassSession::create(['StudentClassID' => $sc->ID, 'SessionDate' => '2026-04-14', 'StartTime' => '14:00', 'EndTime' => '16:00', 'Status' => 'attended']);
        $s3 = ClassSession::create(['StudentClassID' => $sc->ID, 'SessionDate' => '2026-04-20', 'StartTime' => '14:00', 'EndTime' => '16:00', 'Status' => 'scheduled']);
        ClassSession::create(['StudentClassID' => $sc->ID, 'SessionDate' => '2026-04-28', 'StartTime' => '14:00', 'EndTime' => '16:00', 'Status' => 'scheduled']);
        ClassSession::create(['StudentClassID' => $sc->ID, 'SessionDate' => '2026-05-05', 'StartTime' => '14:00', 'EndTime' => '16:00', 'Status' => 'scheduled']);

        return [$dirToken, (int) $regular->id, (int) $sub->id, $s3];
    }

    private function seedSameDayTwoSessionScenario(): array
    {
        $campusId = 1;

        $director = User::create([
            'LoginName' => 'dir-sd@example.com', 'Name' => '主任SD', 'PSW' => 'x',
            'type' => 'A', 'phone' => '0900300001', 'MustChangePassword' => false,
        ]);
        UserCampus::create(['CampusID' => $campusId, 'UserID' => $director->id, 'Admin' => 1, 'Approved' => 1]);
        $dirToken = bin2hex(random_bytes(16));
        AuthToken::create(['user_id' => $director->id, 'token' => $dirToken, 'expires_at' => now()->addDay()]);

        $regular = User::create([
            'LoginName' => 'reg-sd@example.com', 'Name' => '正班SD', 'PSW' => 'x',
            'type' => 'T', 'phone' => '0900300002', 'MustChangePassword' => false,
        ]);
        UserCampus::create(['CampusID' => $campusId, 'UserID' => $regular->id, 'Admin' => 0, 'Approved' => 1]);

        $sub = User::create([
            'LoginName' => 'sub-sd@example.com', 'Name' => '代課SD', 'PSW' => 'x',
            'type' => 'T', 'phone' => '0900300003', 'MustChangePassword' => false,
        ]);
        UserCampus::create(['CampusID' => $campusId, 'UserID' => $sub->id, 'Admin' => 0, 'Approved' => 1]);

        $student = Student::create([
            'name' => '同日雙堂生', 'CampusID' => $campusId, 'ClassID' => 1,
            'enable' => 1, 'MDT' => now(), 'Notify_Token' => '',
        ]);
        $sc = StudentClass::create([
            'StudentID' => $student->id, 'GradeID' => 1, 'SubjectID' => 1,
            'TeacherID' => $regular->id, 'ClassType' => 'one_on_one',
            'by1' => 1, 'Period' => 4, 'StartDate' => '2026-04-01', 'TotalHours' => 20,
            'SessionCount' => 10, 'SessionDuration' => 120, 'RemainingSessions' => 8, 'UsedSessions' => 2,
            'Charge' => 1600, 'Pay' => 16000, 'Paid' => 0, 'Rate' => 800, 'Stop' => 0,
            'MDate' => now(), 'ScheduleMode' => 'count',
        ]);

        ClassSession::create(['StudentClassID' => $sc->ID, 'SessionDate' => '2026-04-20', 'StartTime' => '09:00', 'EndTime' => '11:00', 'Status' => 'scheduled']);
        ClassSession::create(['StudentClassID' => $sc->ID, 'SessionDate' => '2026-04-20', 'StartTime' => '14:00', 'EndTime' => '16:00', 'Status' => 'scheduled']);

        return [$dirToken, (int) $regular->id, (int) $sub->id, (int) $sc->ID];
    }

    private function seedRescheduleScenario(): array
    {
        [$dirToken, $regularTeacherId, , $session] = $this->seedScenario();
        return [$dirToken, (int) $session->StudentClassID, $session];
    }

    private function postSubstitute(string $token, int $sessionId, int $subTeacherId)
    {
        return $this->withHeaders([
            'Authorization' => "Bearer {$token}",
            'Accept' => 'application/json',
        ])->postJson("/api/v1/class-sessions/{$sessionId}/substitute", [
            'substitute_teacher_id' => $subTeacherId,
            'reason' => 'regression test',
        ]);
    }

    private function doReschedule(string $dirToken, int $courseId, ClassSession $session, string $newDate): void
    {
        $sessionDate = substr((string) $session->SessionDate, 0, 10);
        $startTime = substr((string) $session->StartTime, 0, 5);
        $endTime = substr((string) $session->EndTime, 0, 5);
        $studentId = (int) StudentClass::where('ID', $courseId)->value('StudentID');

        $existingRes = Schedule::where('student_course_id', $courseId)
            ->whereDate('schedule_date', $sessionDate)
            ->where('status', 'rescheduled')
            ->first();

        $originalId = $existingRes ? (int) $existingRes->id : null;

        if (!$originalId) {
            $rescheduled = $this->withHeaders([
                'Authorization' => "Bearer {$dirToken}",
                'Accept' => 'application/json',
            ])->postJson('/api/v1/schedules', [
                'student_id' => $studentId,
                'teacher_id' => null,
                'subject' => 'Math',
                'day_of_week' => (int) Carbon::parse($sessionDate)->dayOfWeekIso,
                'start_time' => $startTime,
                'end_time' => $endTime,
                'duration_hours' => 2,
                'class_type' => 'one_on_one',
                'status' => 'rescheduled',
                'type' => 'normal',
                'deduction' => 0,
                'branch_id' => 1,
                'schedule_date' => $sessionDate,
                'student_course_id' => $courseId,
            ]);
            $originalId = $rescheduled->json('id');
        }

        $newDow = (int) Carbon::parse($newDate)->dayOfWeekIso;
        $this->withHeaders([
            'Authorization' => "Bearer {$dirToken}",
            'Accept' => 'application/json',
        ])->postJson('/api/v1/schedules', [
            'student_id' => $studentId,
            'teacher_id' => null,
            'subject' => 'Math',
            'day_of_week' => $newDow,
            'start_time' => $startTime,
            'end_time' => $endTime,
            'duration_hours' => 2,
            'class_type' => 'one_on_one',
            'status' => 'scheduled',
            'type' => 'normal',
            'deduction' => 1,
            'branch_id' => 1,
            'schedule_date' => $newDate,
            'student_course_id' => $courseId,
            'original_schedule_id' => $originalId,
        ]);

        $this->withHeaders([
            'Authorization' => "Bearer {$dirToken}",
            'Accept' => 'application/json',
        ])->postJson('/api/v1/learning-records/reschedule-session', [
            'student_class_id' => $courseId,
            'old_date' => $sessionDate,
            'new_date' => $newDate,
            'start_time' => $startTime,
            'end_time' => $endTime,
        ]);
    }

    private function getDirectorToken(string $token): string
    {
        return $token;
    }
}
