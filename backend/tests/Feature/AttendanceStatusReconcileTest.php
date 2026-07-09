<?php

namespace Tests\Feature;

use App\Models\AuthToken;
use App\Models\ClassSession;
use App\Models\Student;
use App\Models\StudentClass;
use App\Models\StudentSignIn;
use App\Models\User;
use App\Models\UserCampus;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * PRD-A: ClassSession.Status vs StudentSignIn.Status reconciliation.
 *
 * Regression guards:
 *   - When an active StudentSignIn exists with Status=present/late, the
 *     /class-sessions list must surface `status=attended`/`late` even if the
 *     underlying ClassSession.Status is stale (`scheduled` or `absent`).
 *   - When attendance is recorded via /api/v1/attendance (store), the
 *     ClassSession.Status must be synced via applyAttendanceEffects.
 *   - Voided sign-ins must not influence the effective status.
 *   - The backfill command must correct in-window stale records and log
 *     out-of-window ones without touching them.
 */
class AttendanceStatusReconcileTest extends TestCase
{
    use RefreshDatabase;

    public function test_class_sessions_list_overrides_absent_when_active_present_signin_exists(): void
    {
        $token = $this->createDirectorToken([1], 'dir-prda-a@test.com');
        $teacherId = $this->createTeacher(1, 'teacher-prda-a@test.com');
        $student = $this->createStudent(1, '沈宇璿測試');

        $courseId = $this->bootstrapCourse($student->id, $teacherId, 4);

        $today = Carbon::now()->toDateString();
        $session = ClassSession::create([
            'StudentClassID' => $courseId,
            'SessionDate' => $today,
            'StartTime' => '17:00',
            'EndTime' => '19:00',
            // Simulate the stale state: ClassSession=absent while an active
            // present sign-in exists (the reported bug).
            'Status' => 'absent',
            'Note' => '',
        ]);

        StudentSignIn::create([
            'StudentClassID' => $courseId,
            'StudentID' => $student->id,
            'TeacherID' => $teacherId,
            'SignInDT' => $today . ' 17:00:00',
            'MDT' => now(),
            'ClassSessionID' => $session->id,
            'Status' => 'present',
            'CampusID' => 1,
            'PersonType' => 'student',
            'SessionDeducted' => false,
        ]);

        $res = $this->withHeaders([
            'Authorization' => "Bearer {$token}",
            'Accept' => 'application/json',
        ])->getJson("/api/v1/class-sessions?branch_id=1&start={$today}&end={$today}");

        $res->assertOk();
        $rows = $res->json('data') ?? [];
        $hit = collect($rows)->firstWhere('id', $session->id);
        $this->assertNotNull($hit, 'Today session should appear in list');
        $this->assertSame('attended', $hit['status'], 'Effective status must reflect active present sign-in');
    }

    public function test_class_sessions_list_respects_voided_signin(): void
    {
        $token = $this->createDirectorToken([1], 'dir-prda-b@test.com');
        $teacherId = $this->createTeacher(1, 'teacher-prda-b@test.com');
        $student = $this->createStudent(1, '作廢簽到測試');

        $courseId = $this->bootstrapCourse($student->id, $teacherId, 4);

        $today = Carbon::now()->toDateString();
        $session = ClassSession::create([
            'StudentClassID' => $courseId,
            'SessionDate' => $today,
            'StartTime' => '16:00',
            'EndTime' => '18:00',
            'Status' => 'absent',
            'Note' => '',
        ]);

        // A voided sign-in must be ignored.
        StudentSignIn::create([
            'StudentClassID' => $courseId,
            'StudentID' => $student->id,
            'TeacherID' => $teacherId,
            'SignInDT' => $today . ' 16:00:00',
            'MDT' => now(),
            'ClassSessionID' => $session->id,
            'Status' => 'present',
            'CampusID' => 1,
            'PersonType' => 'student',
            'SessionDeducted' => false,
            'VoidedAt' => now(),
            'VoidReason' => '測試用作廢',
        ]);

        $res = $this->withHeaders([
            'Authorization' => "Bearer {$token}",
            'Accept' => 'application/json',
        ])->getJson("/api/v1/class-sessions?branch_id=1&start={$today}&end={$today}");

        $res->assertOk();
        $rows = $res->json('data') ?? [];
        $hit = collect($rows)->firstWhere('id', $session->id);
        $this->assertNotNull($hit);
        $this->assertSame('absent', $hit['status'], 'Voided sign-in must not override ClassSession.Status');
    }

    public function test_attendance_store_syncs_class_session_status(): void
    {
        $directorToken = $this->createDirectorToken([1], 'dir-prda-c@test.com');
        $teacherId = $this->createTeacher(1, 'teacher-prda-c@test.com');
        $teacherToken = $this->createTokenForUser($teacherId);
        $student = $this->createStudent(1, '點名同步測試');

        $courseId = $this->bootstrapCourse($student->id, $teacherId, 4);

        $yesterday = Carbon::now()->subDay()->toDateString();
        $session = ClassSession::create([
            'StudentClassID' => $courseId,
            'SessionDate' => $yesterday,
            'StartTime' => '16:00',
            'EndTime' => '18:00',
            'Status' => 'scheduled',
            'Note' => '',
        ]);

        $res = $this->withHeaders([
            'Authorization' => "Bearer {$teacherToken}",
            'Accept' => 'application/json',
        ])->postJson('/api/v1/attendance', [
            'StudentID' => $student->id,
            'StudentClassID' => $courseId,
            'ClassSessionID' => $session->id,
            'Status' => 'present',
        ]);

        $res->assertCreated();
        $session->refresh();
        $this->assertSame('attended', strtolower((string) $session->Status), 'ClassSession.Status must be synced to attended');

        // And the list API returns attended too (uses the derived override,
        // which is a no-op here because cs.Status is already attended).
        $resList = $this->withHeaders([
            'Authorization' => "Bearer {$directorToken}",
            'Accept' => 'application/json',
        ])->getJson("/api/v1/class-sessions?branch_id=1&start={$yesterday}&end={$yesterday}");
        $resList->assertOk();
        $hit = collect($resList->json('data') ?? [])->firstWhere('id', $session->id);
        $this->assertNotNull($hit);
        $this->assertSame('attended', $hit['status']);
    }

    public function test_backfill_command_fixes_in_window_and_logs_out_of_window(): void
    {
        $teacherId = $this->createTeacher(1, 'teacher-prda-d@test.com');
        $student = $this->createStudent(1, 'backfill測試');
        $courseId = $this->bootstrapCourse($student->id, $teacherId, 4);

        // In-window stale session (2 days ago)
        $inWindowDate = Carbon::now()->subDays(2)->toDateString();
        $inSession = ClassSession::create([
            'StudentClassID' => $courseId,
            'SessionDate' => $inWindowDate,
            'StartTime' => '16:00',
            'EndTime' => '18:00',
            'Status' => 'absent',
            'Note' => '',
        ]);
        StudentSignIn::create([
            'StudentClassID' => $courseId, 'StudentID' => $student->id, 'TeacherID' => $teacherId,
            'SignInDT' => $inWindowDate . ' 16:00:00', 'MDT' => now(),
            'ClassSessionID' => $inSession->id, 'Status' => 'present',
            'CampusID' => 1, 'PersonType' => 'student', 'SessionDeducted' => false,
        ]);

        // Out-of-window stale session (20 days ago)
        $outDate = Carbon::now()->subDays(20)->toDateString();
        $outSession = ClassSession::create([
            'StudentClassID' => $courseId,
            'SessionDate' => $outDate,
            'StartTime' => '16:00',
            'EndTime' => '18:00',
            'Status' => 'absent',
            'Note' => '',
        ]);
        StudentSignIn::create([
            'StudentClassID' => $courseId, 'StudentID' => $student->id, 'TeacherID' => $teacherId,
            'SignInDT' => $outDate . ' 16:00:00', 'MDT' => now(),
            'ClassSessionID' => $outSession->id, 'Status' => 'present',
            'CampusID' => 1, 'PersonType' => 'student', 'SessionDeducted' => false,
        ]);

        $this->artisan('attendance:reconcile-session-status', ['--days' => 7])
            ->assertExitCode(0);

        $inSession->refresh();
        $outSession->refresh();
        $this->assertSame('attended', strtolower((string) $inSession->Status), 'In-window session should be reconciled');
        $this->assertSame('absent', strtolower((string) $outSession->Status), 'Out-of-window session must NOT be auto-fixed');

        // CSV report exists
        $today = Carbon::now()->toDateString();
        $report = storage_path('logs/backfill-report-' . $today . '.csv');
        $this->assertFileExists($report);
    }

    public function test_backfill_dry_run_does_not_update(): void
    {
        $teacherId = $this->createTeacher(1, 'teacher-prda-e@test.com');
        $student = $this->createStudent(1, 'dry-run測試');
        $courseId = $this->bootstrapCourse($student->id, $teacherId, 4);

        $dateStr = Carbon::now()->subDays(2)->toDateString();
        $session = ClassSession::create([
            'StudentClassID' => $courseId,
            'SessionDate' => $dateStr,
            'StartTime' => '16:00',
            'EndTime' => '18:00',
            'Status' => 'absent',
            'Note' => '',
        ]);
        StudentSignIn::create([
            'StudentClassID' => $courseId, 'StudentID' => $student->id, 'TeacherID' => $teacherId,
            'SignInDT' => $dateStr . ' 16:00:00', 'MDT' => now(),
            'ClassSessionID' => $session->id, 'Status' => 'present',
            'CampusID' => 1, 'PersonType' => 'student', 'SessionDeducted' => false,
        ]);

        $this->artisan('attendance:reconcile-session-status', ['--dry-run' => true])
            ->assertExitCode(0);

        $session->refresh();
        $this->assertSame('absent', strtolower((string) $session->Status), 'Dry-run must not update');
    }

    // ── Helpers (same structure as AttendanceLeaveStatusContractTest) ──

    private function createDirectorToken(array $campusIds, string $loginName): string
    {
        $user = User::create([
            'LoginName' => $loginName, 'Name' => '主任', 'PSW' => 'secret',
            'type' => 'A', 'phone' => '0912000000', 'MustChangePassword' => false,
        ]);
        foreach ($campusIds as $cid) {
            UserCampus::create(['CampusID' => $cid, 'UserID' => $user->id, 'Admin' => 1, 'Approved' => 1]);
        }
        return $this->createTokenForUser($user->id);
    }

    private function createTeacher(int $campusId, string $loginName): int
    {
        $teacher = User::create([
            'LoginName' => $loginName, 'Name' => '老師', 'PSW' => 'secret',
            'type' => 'T', 'phone' => '0922000000', 'MustChangePassword' => false,
        ]);
        UserCampus::create(['CampusID' => $campusId, 'UserID' => $teacher->id, 'Admin' => 0, 'Approved' => 1]);
        return (int) $teacher->id;
    }

    private function createTokenForUser(int $userId): string
    {
        $raw = bin2hex(random_bytes(16));
        AuthToken::create(['user_id' => $userId, 'token' => $raw, 'expires_at' => now()->addDay()]);
        return $raw;
    }

    private function createStudent(int $campusId, string $name): Student
    {
        return Student::create([
            'name' => $name, 'CampusID' => $campusId, 'ClassID' => 1,
            'enable' => 1, 'MDT' => now(), 'Notify_Token' => '',
        ]);
    }

    private function bootstrapCourse(int $studentId, int $teacherId, int $count): int
    {
        $course = StudentClass::create([
            'StudentID' => $studentId, 'GradeID' => 1, 'SubjectID' => 1,
            'TeacherID' => $teacherId, 'by1' => 1, 'Period' => 4,
            'StartDate' => '2026-03-01', 'TotalHours' => 40, 'Memo' => 'prda-test',
            'Paid' => 0, 'Stop' => 0, 'ScheduleMode' => 'count',
            'SessionCount' => $count, 'RemainingSessions' => $count, 'UsedSessions' => 0,
            'SessionDuration' => 120, 'ClassType' => 'one_on_one',
            'MDate' => now(), 'Rate' => 500,
        ]);
        return (int) $course->ID;
    }
}
