<?php

namespace Tests\Feature;

use App\Models\AuthToken;
use App\Models\ClassSession;
use App\Models\Schedule;
use App\Models\Student;
use App\Models\StudentClass;
use App\Models\User;
use App\Models\UserCampus;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * PRD: 代課 + 調課組合操作授課老師顯示修正
 *
 * FR-001: submitReschedule (frontend) skips payload2 insert when a substitute
 *         scheduled row already exists for the anchor originalId.
 * FR-002: syncSchedulesForRescheduledSession (backend) purges duplicate
 *         scheduled rows after moving the substitute row to the new date.
 *
 * These tests focus on the BACKEND side of both defences:
 *   1. Happy path — substitute then reschedule produces exactly one
 *      scheduled row with the SUBSTITUTE teacher on the new date.
 *   2. Reverse order — reschedule then substitute keeps the display
 *      correct (baseline already covered elsewhere, included for safety).
 *   3. Edge — race condition where a duplicate scheduled row with the
 *      ORIGINAL teacher exists on the new date before reschedule-session
 *      runs; FR-002 must purge it.
 *   4. Regression — plain reschedule (no substitute) must still create
 *      the scheduled row normally; FR-002 must NOT delete it.
 */
class SubstituteReschedulesCombinationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow(Carbon::parse('2026-04-18 08:00:00', 'Asia/Taipei'));
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    public function test_substitute_then_reschedule_shows_substitute_teacher(): void
    {
        [$dirToken, $regularTeacherId, $subTeacherId, $session] = $this->seedScenario('sub-then-res');
        $courseId = (int) $session->StudentClassID;

        $this->postSubstitute($dirToken, $session->id, $subTeacherId)->assertOk();
        $this->doReschedule($dirToken, $courseId, $session->fresh(), '2026-04-22');

        $scheduledRows = Schedule::where('student_course_id', $courseId)
            ->whereDate('schedule_date', '2026-04-22')
            ->where('status', 'scheduled')
            ->get();

        $this->assertCount(1, $scheduledRows,
            'After substitute+reschedule, target date must have exactly 1 scheduled row');
        $this->assertSame($subTeacherId, (int) $scheduledRows->first()->teacher_id,
            'The surviving scheduled row must carry the substitute teacher_id');

        $idx = $this->withHeaders([
            'Authorization' => "Bearer {$dirToken}",
            'Accept' => 'application/json',
        ])->getJson("/api/v1/class-sessions?student_class_id={$courseId}&per_page=100");
        $idx->assertOk();
        $row = collect($idx->json('data'))->first(function ($r) {
            $sessionDate = (string) ($r['session_date'] ?? ($r['SessionDate'] ?? ''));
            return str_starts_with($sessionDate, '2026-04-22');
        });
        $this->assertNotNull($row);
        $this->assertSame($subTeacherId, (int) ($row['teacher_id'] ?? 0),
            'class-sessions index must resolve the substitute teacher on the new date');
    }

    public function test_reschedule_then_substitute_shows_substitute_teacher(): void
    {
        [$dirToken, $regularTeacherId, $subTeacherId, $session] = $this->seedScenario('res-then-sub');
        $courseId = (int) $session->StudentClassID;

        $this->doReschedule($dirToken, $courseId, $session, '2026-04-22');

        $movedSession = ClassSession::where('StudentClassID', $courseId)
            ->whereDate('SessionDate', '2026-04-22')
            ->whereNotIn('Status', ['cancelled'])
            ->first();
        $this->assertNotNull($movedSession);

        $this->postSubstitute($dirToken, $movedSession->id, $subTeacherId)->assertOk();

        $scheduledRows = Schedule::where('student_course_id', $courseId)
            ->whereDate('schedule_date', '2026-04-22')
            ->where('status', 'scheduled')
            ->get();

        $this->assertCount(1, $scheduledRows,
            'After reschedule+substitute, target date must have exactly 1 scheduled row');
        $this->assertSame($subTeacherId, (int) $scheduledRows->first()->teacher_id,
            'The surviving scheduled row must carry the substitute teacher_id');
    }

    public function test_duplicate_scheduled_row_on_new_date_is_purged_by_sync(): void
    {
        // Simulate the race: the substitute row already exists at the ORIGINAL
        // date when a stale duplicate (created by a racy frontend POST) is
        // sitting on the NEW date under the SAME anchor. After
        // reschedule-session runs, FR-002 must leave exactly one scheduled
        // row pointing to the substitute teacher.
        [$dirToken, $regularTeacherId, $subTeacherId, $session] = $this->seedScenario('purge');
        $courseId = (int) $session->StudentClassID;
        $studentId = (int) $session->studentClass->StudentID;
        $originalDate = substr((string) $session->SessionDate, 0, 10);
        $startTime = substr((string) $session->StartTime, 0, 5);
        $endTime = substr((string) $session->EndTime, 0, 5);

        // 1) Substitute at original date+time.
        $this->postSubstitute($dirToken, $session->id, $subTeacherId)->assertOk();

        $substituteRow = Schedule::where('student_course_id', $courseId)
            ->whereDate('schedule_date', $originalDate)
            ->where('status', 'scheduled')
            ->whereNotNull('original_schedule_id')
            ->first();
        $this->assertNotNull($substituteRow,
            'Substitute setup must produce a scheduled row with original_schedule_id');
        $anchorId = (int) $substituteRow->original_schedule_id;

        // 2) Planted duplicate on the NEW date under the SAME anchor, with
        //    the ORIGINAL teacher (mimics SmartCalendar.submitReschedule's
        //    buggy payload2 insert before FR-001 was in place).
        Schedule::create([
            'student_id' => $studentId,
            'teacher_id' => $regularTeacherId,
            'subject' => 'Math',
            'day_of_week' => (int) Carbon::parse('2026-04-22')->dayOfWeekIso,
            'start_time' => $startTime,
            'end_time' => $endTime,
            'duration_hours' => 2,
            'class_type' => 'one_on_one',
            'status' => 'scheduled',
            'type' => 'normal',
            'deduction' => 1,
            'branch_id' => 1,
            'schedule_date' => '2026-04-22',
            'original_schedule_id' => $anchorId,
            'student_course_id' => $courseId,
        ]);

        // 3) reschedule-session. syncSchedulesForRescheduledSession should
        //    move $substituteRow to 2026-04-22 and then purge the planted
        //    duplicate (same anchor, not in the moved set).
        $this->withHeaders([
            'Authorization' => "Bearer {$dirToken}",
            'Accept' => 'application/json',
        ])->postJson('/api/v1/learning-records/reschedule-session', [
            'student_class_id' => $courseId,
            'old_date' => $originalDate,
            'old_start_time' => $startTime,
            'new_date' => '2026-04-22',
            'start_time' => $startTime,
            'end_time' => $endTime,
        ])->assertOk();

        $rowsOnNewDate = Schedule::where('student_course_id', $courseId)
            ->whereDate('schedule_date', '2026-04-22')
            ->where('status', 'scheduled')
            ->where('original_schedule_id', $anchorId)
            ->get();

        $this->assertCount(1, $rowsOnNewDate,
            'FR-002 must purge the duplicate; exactly one scheduled row must remain on the new date');
        $this->assertSame($subTeacherId, (int) $rowsOnNewDate->first()->teacher_id,
            'The surviving row must carry the substitute teacher_id (stale duplicate purged)');
    }

    public function test_plain_reschedule_without_substitute_still_creates_scheduled_row(): void
    {
        // Regression: make sure FR-002 does NOT delete legitimate scheduled
        // rows when there is no substitute (i.e. no rows in the moved set).
        [$dirToken, $regularTeacherId, , $session] = $this->seedScenario('plain');
        $courseId = (int) $session->StudentClassID;

        $this->doReschedule($dirToken, $courseId, $session, '2026-04-22');

        $targetDateCount = ClassSession::where('StudentClassID', $courseId)
            ->whereDate('SessionDate', '2026-04-22')
            ->whereNotIn('Status', ['cancelled'])
            ->count();
        $this->assertSame(1, $targetDateCount,
            'Plain reschedule must still move the ClassSession to the new date');

        $scheduledCount = Schedule::where('student_course_id', $courseId)
            ->whereDate('schedule_date', '2026-04-22')
            ->where('status', 'scheduled')
            ->count();
        $this->assertSame(1, $scheduledCount,
            'Plain reschedule must still create exactly 1 scheduled row on the new date');
    }

    // ── Helpers ──────────────────────────────────────────────────

    private function seedScenario(string $suffix): array
    {
        $campusId = 1;

        $director = User::create([
            'LoginName' => "dir-{$suffix}@example.com", 'Name' => "主任{$suffix}", 'PSW' => 'x',
            'type' => 'A', 'phone' => '0911' . substr(md5($suffix), 0, 6), 'MustChangePassword' => false,
        ]);
        UserCampus::create(['CampusID' => $campusId, 'UserID' => $director->id, 'Admin' => 1, 'Approved' => 1]);
        $dirToken = bin2hex(random_bytes(16));
        AuthToken::create(['user_id' => $director->id, 'token' => $dirToken, 'expires_at' => now()->addDay()]);

        $regular = User::create([
            'LoginName' => "reg-{$suffix}@example.com", 'Name' => "正班{$suffix}", 'PSW' => 'x',
            'type' => 'T', 'phone' => '0912' . substr(md5($suffix), 0, 6), 'MustChangePassword' => false,
        ]);
        UserCampus::create(['CampusID' => $campusId, 'UserID' => $regular->id, 'Admin' => 0, 'Approved' => 1]);

        $sub = User::create([
            'LoginName' => "sub-{$suffix}@example.com", 'Name' => "代課{$suffix}", 'PSW' => 'x',
            'type' => 'T', 'phone' => '0913' . substr(md5($suffix), 0, 6), 'MustChangePassword' => false,
        ]);
        UserCampus::create(['CampusID' => $campusId, 'UserID' => $sub->id, 'Admin' => 0, 'Approved' => 1]);

        $student = Student::create([
            'name' => "組合測試生{$suffix}", 'CampusID' => $campusId, 'ClassID' => 1,
            'enable' => 1, 'MDT' => now(), 'Notify_Token' => '',
        ]);
        $sc = StudentClass::create([
            'StudentID' => $student->id, 'GradeID' => 1, 'SubjectID' => 1,
            'TeacherID' => $regular->id, 'ClassType' => 'one_on_one',
            'by1' => 1, 'Period' => 4, 'StartDate' => '2026-04-01', 'TotalHours' => 20,
            'SessionCount' => 10, 'SessionDuration' => 120, 'RemainingSessions' => 8, 'UsedSessions' => 2,
            'Charge' => 1600, 'Pay' => 16000, 'Paid' => 0, 'Rate' => 800, 'Stop' => 0,
            'RoomID' => '1', 'MDate' => now(), 'ScheduleMode' => 'count',
        ]);

        ClassSession::create(['StudentClassID' => $sc->ID, 'SessionDate' => '2026-04-07', 'StartTime' => '14:00', 'EndTime' => '16:00', 'Status' => 'attended']);
        ClassSession::create(['StudentClassID' => $sc->ID, 'SessionDate' => '2026-04-14', 'StartTime' => '14:00', 'EndTime' => '16:00', 'Status' => 'attended']);
        $s3 = ClassSession::create(['StudentClassID' => $sc->ID, 'SessionDate' => '2026-04-20', 'StartTime' => '14:00', 'EndTime' => '16:00', 'Status' => 'scheduled']);
        ClassSession::create(['StudentClassID' => $sc->ID, 'SessionDate' => '2026-04-28', 'StartTime' => '14:00', 'EndTime' => '16:00', 'Status' => 'scheduled']);

        return [$dirToken, (int) $regular->id, (int) $sub->id, $s3];
    }

    private function postSubstitute(string $token, int $sessionId, int $subTeacherId)
    {
        return $this->withHeaders([
            'Authorization' => "Bearer {$token}",
            'Accept' => 'application/json',
        ])->postJson("/api/v1/class-sessions/{$sessionId}/substitute", [
            'substitute_teacher_id' => $subTeacherId,
            'reason' => 'combination test',
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

        // FR-001 mirror: only insert payload2 if no substitute row exists
        // for this anchor. We reproduce the real frontend guard here so the
        // Feature tests exercise both defences together.
        $alreadySubstituted = Schedule::where('student_course_id', $courseId)
            ->where('status', 'scheduled')
            ->where('original_schedule_id', $originalId)
            ->exists();

        if (!$alreadySubstituted) {
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
        }

        $this->withHeaders([
            'Authorization' => "Bearer {$dirToken}",
            'Accept' => 'application/json',
        ])->postJson('/api/v1/learning-records/reschedule-session', [
            'student_class_id' => $courseId,
            'old_date' => $sessionDate,
            'old_start_time' => $startTime,
            'new_date' => $newDate,
            'start_time' => $startTime,
            'end_time' => $endTime,
        ]);
    }
}
