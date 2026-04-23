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
 * AC-001: 刷卡在窗口內 → ClassSession.Status 更新為 attended
 * AC-002: 刷卡遲到（>15min after StartTime）→ ClassSession.Status = late
 * AC-003: ClassSession.Status 已非 scheduled 時不覆寫（guard）
 * AC-004: StudentSingIn.TeacherID 正確填入（非 null）
 * AC-005: 無匹配課程（self_study）→ ClassSession 不更新
 *
 * ⚠️ CI only — 不可在 /home/admin/backend 直接執行（RefreshDatabase 會清 production DB）
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

    private function makeClassSession(
        int     $studentClassId,
        string  $date,
        string  $start,
        string  $end,
        string  $status = 'scheduled'
    ): ClassSession {
        return ClassSession::create([
            'StudentClassID' => $studentClassId,
            'SessionDate'    => $date,
            'StartTime'      => $start,
            'EndTime'        => $end,
            'Status'         => $status,
        ]);
    }

    /** POST /api/v1/swipe-rfid with a fixed swipe_at timestamp */
    private function swipeAt(string $rfid, string $swipeAt): \Illuminate\Testing\TestResponse
    {
        return $this->postJson('/api/v1/swipe-rfid', [
            'branch_code' => (string) $this->campus->id,
            'rfid'        => $rfid,
            'swipe_at'    => $swipeAt,
        ], [
            'Authorization' => 'Bearer sync-test-token-xyz',
        ]);
    }

    // ── AC-001: on-time swipe → ClassSession.Status = attended ───────────────

    /**
     * @test
     * FR-001, FR-002：學生在 StartTime-30min ～ StartTime+15min 之間刷卡
     * → ClassSession.Status 應更新為 'attended'
     */
    public function swipe_on_time_updates_class_session_to_attended(): void
    {
        $student = $this->makeStudent();
        $sc      = $this->makeStudentClass($student->id);

        $sessionDate = now()->toDateString();
        $startTime   = '10:00:00';
        $endTime     = '12:00:00';
        $session     = $this->makeClassSession($sc->ID, $sessionDate, $startTime, $endTime);

        // swipe 10 minutes after start → on-time (≤ 15 min grace)
        $swipeAt = $sessionDate . ' 10:10:00';

        $res = $this->swipeAt($student->RFID, $swipeAt);
        $res->assertStatus(201);

        $session->refresh();
        $this->assertEquals('attended', $session->Status, 'ClassSession.Status should be attended after on-time swipe');
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

        $sessionDate = now()->toDateString();
        $startTime   = '10:00:00';
        $endTime     = '12:00:00';
        $session     = $this->makeClassSession($sc->ID, $sessionDate, $startTime, $endTime);

        // swipe 20 minutes after start → late (> 15 min grace)
        $swipeAt = $sessionDate . ' 10:20:00';

        $res = $this->swipeAt($student->RFID, $swipeAt);
        $res->assertStatus(201);

        $session->refresh();
        $this->assertEquals('late', $session->Status, 'ClassSession.Status should be late when swipe is > 15min after StartTime');
    }

    // ── AC-003: guard — do not overwrite non-scheduled status ────────────────

    /**
     * @test
     * FR-003：若 ClassSession.Status 已為 'attended'（老師已手動點名）
     * → 學生刷卡後 ClassSession.Status 應維持 'attended'，不被覆寫
     */
    public function swipe_does_not_overwrite_already_attended_session(): void
    {
        $student = $this->makeStudent();
        $sc      = $this->makeStudentClass($student->id);

        $sessionDate = now()->toDateString();
        $startTime   = '10:00:00';
        $endTime     = '12:00:00';
        $session     = $this->makeClassSession($sc->ID, $sessionDate, $startTime, $endTime, 'attended');

        $swipeAt = $sessionDate . ' 10:05:00';

        $res = $this->swipeAt($student->RFID, $swipeAt);

        $session->refresh();
        $this->assertEquals('attended', $session->Status, 'ClassSession.Status must not be overwritten when already attended');
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

        $sessionDate = now()->toDateString();
        $startTime   = '14:00:00';
        $endTime     = '16:00:00';
        $this->makeClassSession($sc->ID, $sessionDate, $startTime, $endTime);

        $swipeAt = $sessionDate . ' 14:05:00';
        $res     = $this->swipeAt($student->RFID, $swipeAt);
        $res->assertStatus(201);

        $signIn = StudentSignIn::where('StudentID', $student->id)->latest('id')->first();
        $this->assertNotNull($signIn, 'StudentSingIn record should be created');
        $this->assertEquals(42, (int) $signIn->TeacherID, 'TeacherID should match StudentClass.TeacherID');
    }

    // ── AC-005: self_study — no ClassSession update ───────────────────────────

    /**
     * @test
     * FR-001：無匹配課程（self_study）→ ClassSession 不更新；Memo = 'self_study'
     *
     * 學生刷卡但今日無排課（或時間在窗口外）→ 建立 self_study 記錄
     * → 已存在的任何 ClassSession 狀態不改變
     */
    public function swipe_with_no_matching_class_does_not_update_any_session(): void
    {
        $student = $this->makeStudent();
        $sc      = $this->makeStudentClass($student->id);

        // Session at 10:00, but student swipes at 23:00 — far outside any window
        $sessionDate = now()->toDateString();
        $session     = $this->makeClassSession($sc->ID, $sessionDate, '10:00:00', '12:00:00');

        $swipeAt = $sessionDate . ' 23:00:00';
        $res     = $this->swipeAt($student->RFID, $swipeAt);
        $res->assertStatus(201);

        $signIn = StudentSignIn::where('StudentID', $student->id)->latest('id')->first();
        $this->assertEquals('self_study', $signIn->Memo, 'Late-night swipe with no window match should be self_study');

        $session->refresh();
        $this->assertEquals('scheduled', $session->Status, 'ClassSession.Status must remain scheduled for self_study swipe');
    }
}
