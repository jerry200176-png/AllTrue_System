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
 * Batch 1 Tech Debt — TD-004, TD-006, TD-007, TD-009
 *
 * 測試在 production 程式碼修改前先寫（Red → Green TDD）。
 * 每個測試描述預期行為；目前程式碼尚未實作，CI 應全數失敗（紅燈）。
 *
 * ⚠️ CI only — 不可在 /home/admin/backend 直接執行（RefreshDatabase 會清 production DB）
 */
class SwipeRfidEdgeCaseTest extends TestCase
{
    use RefreshDatabase;

    private Campus $campus;
    private int $subjectId;

    protected function setUp(): void
    {
        parent::setUp();

        $this->campus = Campus::create([
            'name'           => 'EdgeCampus',
            'Token'          => 'edge-token-abc',
            'code'           => 'edge',
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
            'Subject_Name' => 'EdgeSubject',
        ]);
        $this->subjectId = $subject->id;
    }

    // ── Helpers ──────────────────────────────────────────────────────────────

    private function makeStudent(): Student
    {
        static $n = 0;
        $n++;
        return Student::create([
            'name'     => "EdgeStudent{$n}",
            'CampusID' => $this->campus->id,
            'ClassID'  => 1,
            'RFID'     => "EDGE-RFID-{$n}",
            'enable'   => 1,
        ]);
    }

    private function makeStudentClass(int $studentId, array $attrs = []): StudentClass
    {
        return StudentClass::create(array_merge([
            'StudentID'    => $studentId,
            'GradeID'      => 1,
            'SubjectID'    => $this->subjectId,
            'TeacherID'    => 1,
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
        ?string $end,
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

    private function swipe(string $rfid): \Illuminate\Testing\TestResponse
    {
        return $this->postJson('/api/v1/swipe-rfid', [
            'branch_code' => (string) $this->campus->id,
            'rfid'        => $rfid,
        ], [
            'Authorization' => 'Bearer edge-token-abc',
        ]);
    }

    // ── TD-004: leave session 不應被刷卡命中 ─────────────────────────────────

    /**
     * @test
     * TD-004: findMatchingClass 不應比對 Status='leave' 的 ClassSession。
     * 學生已請假，當天若來補習班刷卡，應建立 self_study 而非 swipe-rfid。
     *
     * 現況（紅燈）：code 無 Status 過濾 → 命中 leave session → Memo='swipe-rfid'
     * 修復後（綠燈）：leave session 被排除 → Memo='self_study'
     */
    public function swipe_does_not_match_leave_status_session(): void
    {
        $student = $this->makeStudent();
        $sc = $this->makeStudentClass($student->id);

        $today     = now()->toDateString();
        $startTime = now()->format('H:i:s');
        $endTime   = now()->addHours(2)->format('H:i:s');

        $this->makeClassSession($sc->ID, $today, $startTime, $endTime, 'leave');

        $res = $this->swipe($student->RFID);

        $res->assertStatus(201)->assertJsonPath('action', 'sign_in');

        $signIn = StudentSignIn::where('StudentID', $student->id)->first();
        $this->assertNotNull($signIn);

        $this->assertSame('self_study', $signIn->Memo,
            'TD-004: 請假堂次（Status=leave）不應被刷卡命中，應建立 self_study 記錄');
        $this->assertNull($signIn->StudentClassID,
            'TD-004: self_study 記錄的 StudentClassID 應為 null');
    }

    // ── TD-006: 學生刷卡 debounce ─────────────────────────────────────────────

    /**
     * @test
     * TD-006: 60 秒內的第二次刷卡應被 debounce 忽略，不觸發 sign_out。
     *
     * 現況（紅燈）：無 debounce → 第二次刷卡觸發 sign_out → openRecord 被關閉
     * 修復後（綠燈）：回傳 duplicate_ignored，openRecord 仍開放
     */
    public function student_second_swipe_within_debounce_window_is_ignored(): void
    {
        $student = $this->makeStudent();

        // 第一次刷卡：sign_in
        $res1 = $this->swipe($student->RFID);
        $res1->assertStatus(201)->assertJsonPath('action', 'sign_in');

        $openRecord = StudentSignIn::where('StudentID', $student->id)
            ->whereNull('SignOutDT')
            ->first();
        $this->assertNotNull($openRecord, '第一次刷卡應建立 openRecord');

        // 30 秒後（debounce 60 秒視窗內）再次刷卡
        $this->travel(30)->seconds();

        $res2 = $this->swipe($student->RFID);

        $res2->assertOk()->assertJsonPath('action', 'duplicate_ignored');

        // openRecord 仍未被關閉
        $openRecord->refresh();
        $this->assertNull($openRecord->SignOutDT,
            'TD-006: debounce 視窗內第二次刷卡不應觸發 sign_out，openRecord 應保持開放');
    }

    // ── TD-007: 同一 ClassSession 已有 active StudentSignIn 時不應重複建立 ────

    /**
     * @test
     * TD-007: 老師手動點名後，學生刷卡不應再建立第二筆 StudentSignIn。
     *
     * 現況（紅燈）：無重複檢查 → 建立第二筆 → DB 出現兩筆記錄
     * 修復後（綠燈）：回傳 duplicate_ignored，仍只有 1 筆記錄
     */
    public function swipe_is_ignored_when_class_session_has_active_signin(): void
    {
        $student = $this->makeStudent();
        $sc      = $this->makeStudentClass($student->id);

        $today     = now()->toDateString();
        $startTime = now()->format('H:i:s');
        $endTime   = now()->addHours(2)->format('H:i:s');

        $session = $this->makeClassSession($sc->ID, $today, $startTime, $endTime);

        // 老師手動點名（已含 SignOutDT，代表該堂已有 active record）
        StudentSignIn::create([
            'StudentClassID'  => $sc->ID,
            'StudentID'       => $student->id,
            'TeacherID'       => 1,
            'SubjectID'       => $this->subjectId,
            'GradeID'         => 1,
            'Memo'            => 'manual',
            'SignInDT'        => now()->toDateTimeString(),
            'SignOutDT'       => now()->addHours(2)->toDateTimeString(),
            'MDT'             => now(),
            'ClassSessionID'  => $session->id,
            'Status'          => 'present',
            'CampusID'        => $this->campus->id,
            'PersonType'      => 'student',
            'SessionDeducted' => true,
        ]);

        // 學生刷卡 → 應被忽略
        $res = $this->swipe($student->RFID);

        $res->assertOk()->assertJsonPath('action', 'duplicate_ignored');

        $count = StudentSignIn::where('StudentID', $student->id)
            ->where('ClassSessionID', $session->id)
            ->whereNull('VoidedAt')
            ->count();

        $this->assertSame(1, $count,
            'TD-007: ClassSession 已有 active StudentSignIn，刷卡不應再新增一筆');
    }

    // ── TD-009: backfillPresenceWindow — EndTime=null 不應寫 SignOutDT=00:00 ──

    /**
     * @test
     * TD-009: ClassSession.EndTime 為 null 時，backfillPresenceWindow 應跳過，
     * 不建立 SignOutDT='00:00:00' 的語義錯誤記錄。
     *
     * 現況（紅燈）：Carbon::parse(null) → '00:00:00' 靜默寫入 DB
     * 修復後（綠燈）：null EndTime 的 session 被跳過，不產生記錄
     */
    public function backfill_skips_session_with_null_end_time(): void
    {
        $student = $this->makeStudent();
        $scA     = $this->makeStudentClass($student->id);
        $scB     = $this->makeStudentClass($student->id);

        $today = now()->toDateString();

        // Session A: 正常，刷卡時間在 10:00 前後 30 分鐘內
        $sessionA = $this->makeClassSession($scA->ID, $today, '10:00:00', '12:00:00');

        // Session B: EndTime=null（壞資料），落在 sign-in 與 sign-out 之間
        $sessionB = $this->makeClassSession($scB->ID, $today, '11:00:00', null);

        // 10:00 刷進（配對 Session A）
        $this->travelTo(now()->setTime(10, 0));
        $resIn = $this->swipe($student->RFID);
        $resIn->assertStatus(201)->assertJsonPath('action', 'sign_in');

        // 13:00 刷出（觸發 backfillPresenceWindow，Session B 落在窗口內）
        $this->travelTo(now()->setTime(13, 0));
        $resOut = $this->swipe($student->RFID);
        $resOut->assertOk()->assertJsonPath('action', 'sign_out');

        // Session B 不應產生 SignOutDT='00:00:00' 的錯誤記錄
        $badRecord = StudentSignIn::where('StudentID', $student->id)
            ->where('ClassSessionID', $sessionB->id)
            ->whereNull('VoidedAt')
            ->where('SignOutDT', 'like', '%00:00:00')
            ->first();

        $this->assertNull($badRecord,
            'TD-009: EndTime=null 的 ClassSession 不應補建 SignOutDT=00:00 的記錄');
    }
}
