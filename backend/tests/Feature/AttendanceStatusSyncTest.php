<?php

namespace Tests\Feature;

use App\Models\AuthToken;
use App\Models\ClassSession;
use App\Models\Student;
use App\Models\StudentClass;
use App\Models\StudentSignIn;
use App\Models\Subject;
use App\Models\User;
use App\Models\UserCampus;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Batch 2 Tech Debt — TD-005
 *
 * 驗證：PATCH /api/v1/class-sessions/:id 變更 ClassSession.Status 時，
 * 對應的 active StudentSignIn.Status 必須同步更新。
 *
 * 現況（紅燈）：ClassSessionController::update 只更新 ClassSession.Status，
 *   StudentSignIn.Status 保持舊值，30 秒後 fetchRecords 回傳的狀態回滾。
 * 修復後（綠燈）：status 轉移時同步更新 StudentSignIn.Status。
 *
 * ⚠️ CI only — 不可在 production 跑（RefreshDatabase）
 */
class AttendanceStatusSyncTest extends TestCase
{
    use RefreshDatabase;

    // ClassSession status → StudentSignIn status 對照表
    private const CS_TO_SI_STATUS = [
        'attended' => 'present',
        'late'     => 'late',
        'absent'   => 'absent',
    ];

    // ── Helpers ──────────────────────────────────────────────────────────────

    private function makeDirectorToken(int $campusId, string $email): string
    {
        static $n = 0;
        $n++;
        $user = User::create([
            'LoginName'          => $email,
            'Name'               => "主任{$n}",
            'PSW'                => 'secret',
            'type'               => 'A',
            'phone'              => "091{$n}111{$n}11",
            'MustChangePassword' => false,
        ]);
        UserCampus::create(['CampusID' => $campusId, 'UserID' => $user->id, 'Admin' => 1, 'Approved' => 1]);
        $raw = bin2hex(random_bytes(16));
        AuthToken::create(['user_id' => $user->id, 'token' => $raw, 'expires_at' => now()->addDay()]);
        return $raw;
    }

    private function makeStudent(int $campusId): Student
    {
        static $n = 0;
        $n++;
        return Student::create([
            'name'         => "SyncStudent{$n}",
            'CampusID'     => $campusId,
            'ClassID'      => 1,
            'enable'       => 1,
            'MDT'          => now(),
            'Notify_Token' => '',
        ]);
    }

    private function makeStudentClass(int $studentId, int $subjectId): StudentClass
    {
        return StudentClass::create([
            'StudentID'    => $studentId,
            'GradeID'      => 1,
            'SubjectID'    => $subjectId,
            'TeacherID'    => 1,
            'by1'          => 0,
            'TotalHours'   => 2,
            'RoomID'       => '',
            'StartDate'    => now()->subYear(),
            'Stop'         => 0,
            'SessionCount' => 10,
            'ScheduleMode' => 'count',
        ]);
    }

    private function makeClassSession(int $studentClassId, string $status): ClassSession
    {
        return ClassSession::create([
            'StudentClassID' => $studentClassId,
            'SessionDate'    => now()->toDateString(),
            'StartTime'      => '10:00:00',
            'EndTime'        => '12:00:00',
            'Status'         => $status,
        ]);
    }

    private function makeStudentSignIn(int $studentId, int $studentClassId, int $sessionId, string $siStatus): StudentSignIn
    {
        return StudentSignIn::create([
            'StudentID'       => $studentId,
            'StudentClassID'  => $studentClassId,
            'ClassSessionID'  => $sessionId,
            'TeacherID'       => 1,
            'GradeID'         => 1,
            'Memo'            => 'manual',
            'SignInDT'        => now()->toDateTimeString(),
            'SignOutDT'       => now()->addHours(2)->toDateTimeString(),
            'MDT'             => now(),
            'Status'          => $siStatus,
            'CampusID'        => 1,
            'PersonType'      => 'student',
            'SessionDeducted' => true,
        ]);
    }

    private function patchSession(string $token, int $sessionId, string $csStatus): \Illuminate\Testing\TestResponse
    {
        return $this->withHeaders([
            'Authorization' => "Bearer {$token}",
            'Accept'        => 'application/json',
        ])->patchJson("/api/v1/class-sessions/{$sessionId}", ['status' => $csStatus]);
    }

    // ── Tests ─────────────────────────────────────────────────────────────────

    /**
     * @test
     * TD-005 (1/3): attended → late 時 StudentSignIn.Status 應同步為 'late'
     *
     * 現況（紅燈）：StudentSignIn.Status 維持 'present'
     * 修復後（綠燈）：StudentSignIn.Status 更新為 'late'
     */
    public function patch_attended_to_late_syncs_student_sign_in_status(): void
    {
        $token   = $this->makeDirectorToken(1, 'sync-test-1@example.com');
        $student = $this->makeStudent(1);
        $subject = Subject::create(['School_id' => 1, 'Grade_no' => 0, 'Subject_Name' => 'SyncSubject1']);
        $sc      = $this->makeStudentClass($student->id, $subject->id);
        $session = $this->makeClassSession($sc->ID, 'attended');
        $signIn  = $this->makeStudentSignIn($student->id, $sc->ID, $session->id, 'present');

        $this->patchSession($token, $session->id, 'late')->assertOk();

        $signIn->refresh();
        $this->assertSame('late', $signIn->Status,
            'TD-005: attended→late 時 StudentSignIn.Status 應同步更新為 late');
    }

    /**
     * @test
     * TD-005 (2/3): attended → absent 時 StudentSignIn.Status 應同步為 'absent'
     *
     * 現況（紅燈）：StudentSignIn.Status 維持 'present'
     * 修復後（綠燈）：StudentSignIn.Status 更新為 'absent'
     */
    public function patch_attended_to_absent_syncs_student_sign_in_status(): void
    {
        $token   = $this->makeDirectorToken(1, 'sync-test-2@example.com');
        $student = $this->makeStudent(1);
        $subject = Subject::create(['School_id' => 1, 'Grade_no' => 0, 'Subject_Name' => 'SyncSubject2']);
        $sc      = $this->makeStudentClass($student->id, $subject->id);
        $session = $this->makeClassSession($sc->ID, 'attended');
        $signIn  = $this->makeStudentSignIn($student->id, $sc->ID, $session->id, 'present');

        $this->patchSession($token, $session->id, 'absent')->assertOk();

        $signIn->refresh();
        $this->assertSame('absent', $signIn->Status,
            'TD-005: attended→absent 時 StudentSignIn.Status 應同步更新為 absent');
    }

    /**
     * @test
     * TD-005 (3/3): absent → attended 時 StudentSignIn.Status 應同步為 'present'
     *
     * 現況（紅燈）：StudentSignIn.Status 維持 'absent'
     * 修復後（綠燈）：StudentSignIn.Status 更新為 'present'
     */
    public function patch_absent_to_attended_syncs_student_sign_in_status(): void
    {
        $token   = $this->makeDirectorToken(1, 'sync-test-3@example.com');
        $student = $this->makeStudent(1);
        $subject = Subject::create(['School_id' => 1, 'Grade_no' => 0, 'Subject_Name' => 'SyncSubject3']);
        $sc      = $this->makeStudentClass($student->id, $subject->id);
        $session = $this->makeClassSession($sc->ID, 'absent');
        $signIn  = $this->makeStudentSignIn($student->id, $sc->ID, $session->id, 'absent');

        $this->patchSession($token, $session->id, 'attended')->assertOk();

        $signIn->refresh();
        $this->assertSame('present', $signIn->Status,
            'TD-005: absent→attended 時 StudentSignIn.Status 應同步更新為 present');
    }

    /**
     * @test
     * TD-005 迴歸：voided StudentSignIn 不應被 status sync 影響
     */
    public function status_sync_does_not_touch_voided_sign_in(): void
    {
        $token   = $this->makeDirectorToken(1, 'sync-test-4@example.com');
        $student = $this->makeStudent(1);
        $subject = Subject::create(['School_id' => 1, 'Grade_no' => 0, 'Subject_Name' => 'SyncSubject4']);
        $sc      = $this->makeStudentClass($student->id, $subject->id);
        $session = $this->makeClassSession($sc->ID, 'attended');

        // Voided record — should NOT be updated
        $voided = StudentSignIn::create([
            'StudentID'       => $student->id,
            'StudentClassID'  => $sc->ID,
            'ClassSessionID'  => $session->id,
            'TeacherID'       => 1,
            'GradeID'         => 1,
            'Memo'            => 'manual',
            'SignInDT'        => now()->toDateTimeString(),
            'SignOutDT'       => now()->addHours(2)->toDateTimeString(),
            'MDT'             => now(),
            'Status'          => 'present',
            'CampusID'        => 1,
            'PersonType'      => 'student',
            'SessionDeducted' => true,
            'VoidedAt'        => now(),
        ]);

        $this->patchSession($token, $session->id, 'late')->assertOk();

        $voided->refresh();
        $this->assertSame('present', $voided->Status,
            'TD-005 迴歸：已 void 的 StudentSignIn 狀態不應被 sync 更新');
    }
}
