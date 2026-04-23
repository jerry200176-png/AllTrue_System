<?php

namespace Tests\Feature;

use App\Models\Campus;
use App\Models\ClassSession;
use App\Models\Student;
use App\Models\StudentClass;
use App\Models\StudentSignIn;
use App\Models\Subject;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Bug Fix: swipe_classsession_sync
 *
 * Covers: FR-001 ~ FR-006 (bugfix_swipe_attendance_sync_2026-04-23.md)
 *
 * AC-001: 刷卡在窗口內（≤15min after StartTime）→ ClassSession.Status = attended
 * AC-002: 刷卡遲到（>15min after StartTime）→ ClassSession.Status = late
 * AC-003: ClassSession.Status 已非 scheduled 時不覆寫（guard）
 * AC-004: StudentSingIn.TeacherID 正確填入（非 null）
 * AC-005: 無匹配課程（self_study）→ ClassSession 不更新
 *
 * ⚠️ CI only — 不可在 /home/admin/backend 直接執行（RefreshDatabase 會清 production DB）
 *
 * Timing note: swipe_at is always now() in the controller (no override param).
 * Sessions are built relative to now() so the controller's own clock matches.
 */
class SwipeClassSessionSyncTest extends TestCase
{
    use RefreshDatabase;

    private Campus $campus;
    private int $subjectId;
    private int $teacherId;

    protected function setUp(): void
    {
        parent::setUp();

        $this->campus = Campus::create([
            'name'           => 'SyncTestCampus',
            'Token'          => 'sync-test-token-xyz',
            'code'           => 'sync',
            'Current'        => 0,
            'LineNotifyID'   => '',
            'Client_ID'      => '',
            'Client_Secret'  => '',
            'LIFFID'         => '',
            'LIFF_URL'       => '',
            'URL'            => '',
            'TelegramToken'  => '',
            'TelegramChatID' => '',
            'TelegramURL'    => '',
            'TeachLIFFID'    => '',
            'TeachLIFF_URL'  => '',
        ]);

        $subject = Subject::create([
            'School_id'    => 1,
            'Grade_no'     => 0,
            'Subject_Name' => 'SyncSubject',
        ]);
        $this->subjectId = $subject->id;
        $this->teacherId = 42;
    }

    // ── Helpers ──────────────────────────────────────────────────────────────

    private function makeStudent(): Student
    {
        static $n = 0;
        $n++;
        return Student::create([
            'name'     => "SyncStudent{$n}",
            'CampusID' => $this->campus->id,
            'ClassID'  => 1,
            'RFID'     => "SYNC-RFID-{$n}",
            'enable'   => 1,
        ]);
    }

    private function makeStudentClass(int $studentId, array $attrs = []): StudentClass
    {
        return StudentClass::create(array_merge([
            'StudentID'    => $studentId,
            'GradeID'      => 1,
            'SubjectID'    => $this->subjectId,
            'TeacherID'    => $this->teacherId,
            'by1'          => 0,
            'TotalHours'   => 2,
            'RoomID'       => '',
            'StartDate'    => now()->subYear(),
            'Stop'         => 0,
            'SessionCount' => 10,
            'ScheduleMode' => 'count',
        ], $attrs));
    }

    /**
     * Build a ClassSession relative to now().
     *
     * @param  int     $startedMinutesAgo  Positive = started N minutes ago (ongoing session)
     * @param  int     $durationMinutes    Session length in minutes
     * @param  string  $status
     */
    private function makeOngoingSession(
        int    $studentClassId,
        int    $startedMinutesAgo,
        int    $durationMinutes = 120,
        string $status = 'scheduled'
    ): ClassSession {
        $start = now()->subMinutes($startedMinutesAgo);
        $end   = $start->copy()->addMinutes($durationMinutes);

        return ClassSession::create([
            'StudentClassID' => $studentClassId,
            'SessionDate'    => $start->toDateString(),
            'StartTime'      => $start->format('H:i:s'),
            'EndTime'        => $end->format('H:i:s'),
            'Status'         => $status,
        ]);
    }

    private function swipe(string $rfid): \Illuminate\Testing\TestResponse
    {
        return $this->postJson('/api/v1/swipe-rfid', [
            'branch_code' => (string) $this->campus->id,
            'rfid'        => $rfid,
        ], [
            'Authorization' => 'Bearer sync-test-token-xyz',
        ]);
    }

    // ── AC-001: on-time swipe → ClassSession.Status = attended ───────────────

    /**
     * @test
     * FR-001, FR-002：學生在 StartTime-30min ～ StartTime+15min 之間刷卡（準時）
     * → ClassSession.Status 應更新為 'attended'
     */
    public function swipe_on_time_updates_class_session_to_attended(): void
    {
        $student = $this->makeStudent();
        $sc      = $this->makeStudentClass($student->id);

        // Session started 10 min ago → still within 15-min grace → on-time
        $session = $this->makeOngoingSession($sc->ID, startedMinutesAgo: 10);

        $res = $this->swipe($student->RFID);
        $res->assertStatus(201);

        $session->refresh();
        $this->assertEquals(
            'attended',
            $session->Status,
            'ClassSession.Status should be attended after on-time swipe (within 15-min grace)'
        );
    }

    // ── AC-002: late swipe → ClassSession.Status = late ──────────────────────

    /**
     * @test
     * FR-001, FR-002：學生在 StartTime+15min 之後刷卡（遲到）
     * → ClassSession.Status 應更新為 'late'
     */
    public function swipe_late_updates_class_session_to_late(): void
    {
        $student = $this->makeStudent();
        $sc      = $this->makeStudentClass($student->id);

        // Session started 20 min ago → past 15-min grace → late
        $session = $this->makeOngoingSession($sc->ID, startedMinutesAgo: 20);

        $res = $this->swipe($student->RFID);
        $res->assertStatus(201);

        $session->refresh();
        $this->assertEquals(
            'late',
            $session->Status,
            'ClassSession.Status should be late when swipe is > 15min after StartTime'
        );
    }

    // ── AC-003: guard — do not overwrite non-scheduled status ────────────────

    /**
     * @test
     * FR-003：若 ClassSession.Status 已為 'attended'（老師已手動點名）
     * → 學生刷卡後 ClassSession.Status 應維持 'attended'，不被覆寫
     *
     * Scenario: session status pre-set to 'attended' (no existing StudentSingIn),
     * student swipes → new StudentSingIn created, but ClassSession.Status unchanged.
     */
    public function swipe_does_not_overwrite_already_attended_session(): void
    {
        $student = $this->makeStudent();
        $sc      = $this->makeStudentClass($student->id);

        // Already attended (e.g., director manually set it)
        $session = $this->makeOngoingSession($sc->ID, startedMinutesAgo: 10, status: 'attended');

        $this->swipe($student->RFID);

        $session->refresh();
        $this->assertEquals(
            'attended',
            $session->Status,
            'ClassSession.Status must not be overwritten when already attended'
        );
    }

    // ── AC-004: TeacherID is correctly set ───────────────────────────────────

    /**
     * @test
     * FR-005：刷卡比對到 StudentClass.TeacherID = 42
     * → StudentSingIn.TeacherID 應為 42（非 null）
     */
    public function swipe_sets_teacher_id_on_signin(): void
    {
        $student = $this->makeStudent();
        $sc      = $this->makeStudentClass($student->id, ['TeacherID' => 42]);

        // Ongoing session started 5 min ago
        $this->makeOngoingSession($sc->ID, startedMinutesAgo: 5);

        $res = $this->swipe($student->RFID);
        $res->assertStatus(201);

        $signIn = StudentSignIn::where('StudentID', $student->id)->latest('id')->first();
        $this->assertNotNull($signIn, 'StudentSingIn record should be created');
        $this->assertNotNull($signIn->TeacherID, 'TeacherID should not be null');
        $this->assertEquals(42, (int) $signIn->TeacherID, 'TeacherID should match StudentClass.TeacherID');
    }

    // ── AC-005: self_study — no ClassSession update ───────────────────────────

    /**
     * @test
     * FR-001：無匹配課程（時間窗口外）→ Memo = 'self_study'，ClassSession 不更新
     *
     * Session ended 3 hours ago — swipeAt (now) is past EndTime, outside window.
     */
    public function swipe_with_no_matching_class_does_not_update_any_session(): void
    {
        $student = $this->makeStudent();
        $sc      = $this->makeStudentClass($student->id);

        // Session ended 3 hours ago: startedMinutesAgo=300 (5h ago), duration=120min → ended 3h ago
        $session = $this->makeOngoingSession($sc->ID, startedMinutesAgo: 300, durationMinutes: 120);

        $res = $this->swipe($student->RFID);
        $res->assertStatus(201);

        $signIn = StudentSignIn::where('StudentID', $student->id)->latest('id')->first();
        $this->assertNotNull($signIn, 'A StudentSingIn should still be created (self_study)');
        $this->assertEquals('self_study', $signIn->Memo, 'Swipe outside window should be self_study');

        $session->refresh();
        $this->assertEquals(
            'scheduled',
            $session->Status,
            'ClassSession.Status must remain scheduled when no matching session found'
        );
    }
}
