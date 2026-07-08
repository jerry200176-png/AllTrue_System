<?php

namespace Tests\Feature;

use App\Models\Campus;
use App\Models\ClassSession;
use App\Models\Student;
use App\Models\StudentClass;
use App\Models\StudentSignIn;
use App\Models\Subject;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Batch 4 Tech Debt — TD-011
 *
 * 測試 findMatchingClass 匹配窗口邏輯改進。
 *
 * 現況（紅燈）：窗口為「距 StartTime ≤ 30 分鐘」
 *   → 遲到 35+ 分鐘學生 → self_study（錯誤）
 *   → 連排課 10:50 刷卡 → 匹配第二堂（10:00 的 Session 被忽略，選了近的 11:00）
 *
 * 修復後（綠燈）：窗口改為「StartTime - 30min ≤ 刷卡時間 ≤ EndTime」
 *   ongoing 優先 → 課中遲到正確匹配
 *
 * ⚠️ CI only — 不可在 production 跑（RefreshDatabase）
 */
class FindMatchingClassWindowTest extends TestCase
{
    use RefreshDatabase;

    private Campus $campus;
    private int $subjectId;

    protected function setUp(): void
    {
        parent::setUp();

        $this->campus = Campus::create([
            'name'           => 'WinCampus',
            'Token'          => 'window-token-win',
            'code'           => 'win',
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
            'Subject_Name' => 'WinSubject',
        ]);
        $this->subjectId = $subject->id;
    }

    private function makeStudent(): Student
    {
        static $n = 0;
        $n++;
        return Student::create([
            'name'     => "WinStudent{$n}",
            'CampusID' => $this->campus->id,
            'ClassID'  => 1,
            'RFID'     => "WIN-RFID-{$n}",
            'enable'   => 1,
        ]);
    }

    private function makeStudentClass(int $studentId): StudentClass
    {
        return StudentClass::create([
            'StudentID'    => $studentId,
            'GradeID'      => 1,
            'SubjectID'    => $this->subjectId,
            'TeacherID'    => 1,
            'by1'          => 0,
            'TotalHours'   => 2,
            'StartDate'    => now()->subYear(),
            'Stop'         => 0,
            'SessionCount' => 10,
            'ScheduleMode' => 'count',
        ]);
    }

    private function makeSession(int $scId, string $date, string $start, string $end): ClassSession
    {
        return ClassSession::create([
            'StudentClassID' => $scId,
            'SessionDate'    => $date,
            'StartTime'      => $start,
            'EndTime'        => $end,
            'Status'         => 'scheduled',
        ]);
    }

    private function swipeAt(string $rfid, Carbon $time): \Illuminate\Testing\TestResponse
    {
        $this->travelTo($time);
        return $this->postJson('/api/v1/swipe-rfid', [
            'branch_code' => (string) $this->campus->id,
            'rfid'        => $rfid,
        ], [
            'Authorization' => 'Bearer window-token-win',
        ]);
    }

    // ── Tests ─────────────────────────────────────────────────────────────────

    /**
     * @test
     * TD-011 (1/3): 遲到 45 分鐘（2 小時課程）→ 應匹配課堂，不歸為 self_study。
     *
     * 課程：10:00–12:00；刷卡：10:45（距 StartTime 45 min，超出舊 30 min 視窗）
     *
     * 現況（紅燈）：diff=45 > 30 → no match → Memo='self_study', ClassSessionID=null
     * 修復後（綠燈）：10:45 在 [09:30, 12:00] → 匹配 → Memo='swipe-rfid', ClassSessionID set
     */
    public function test_late_arrival_within_session_matches_class(): void
    {
        $today   = now()->toDateString();
        $student = $this->makeStudent();
        $sc      = $this->makeStudentClass($student->id);
        $session = $this->makeSession($sc->ID, $today, '10:00:00', '12:00:00');

        $res = $this->swipeAt($student->RFID, Carbon::parse($today . ' 10:45:00'));

        $res->assertStatus(201)->assertJsonPath('action', 'sign_in');

        $signIn = StudentSignIn::where('StudentID', $student->id)->first();
        $this->assertNotNull($signIn);

        $this->assertNotNull($signIn->ClassSessionID,
            'TD-011: 遲到 45 分鐘（2 小時課）應匹配 ClassSession，ClassSessionID 不應為 null');

        $this->assertSame($session->id, (int) $signIn->ClassSessionID,
            'TD-011: 應匹配到正確的 ClassSession');

        $this->assertSame('swipe-rfid', $signIn->Memo,
            'TD-011: 有匹配課堂時 Memo 應為 swipe-rfid，不應為 self_study');
    }

    /**
     * @test
     * TD-011 (2/3): 課後 5 分鐘刷卡 → 不匹配，歸為 self_study（迴歸保護）。
     *
     * 課程：10:00–11:00；刷卡：11:05（EndTime 之後 5 分鐘）
     * 修復前後均應通過。
     */
    public function test_arrival_after_session_ends_is_self_study(): void
    {
        $today   = now()->toDateString();
        $student = $this->makeStudent();
        $sc      = $this->makeStudentClass($student->id);
        $this->makeSession($sc->ID, $today, '10:00:00', '11:00:00');

        $res = $this->swipeAt($student->RFID, Carbon::parse($today . ' 11:05:00'));

        $res->assertStatus(201)->assertJsonPath('action', 'sign_in');

        $signIn = StudentSignIn::where('StudentID', $student->id)->first();
        $this->assertNotNull($signIn);

        $this->assertNull($signIn->ClassSessionID,
            'TD-011: 課後刷卡不應匹配任何 ClassSession');

        $this->assertSame('self_study', $signIn->Memo,
            'TD-011: 課後刷卡應歸為 self_study');
    }

    /**
     * @test
     * TD-011 (3/3): 連排課（10:00–11:00 + 11:00–12:00），10:50 刷卡 → 匹配第一堂（ongoing）。
     *
     * 10:50 在兩堂課的新窗口內：
     *   Session1 [09:30–11:00]: ongoing  (10:50 ≥ 10:00)
     *   Session2 [10:30–12:00]: upcoming (10:50 < 11:00)
     *
     * 現況（紅燈）：舊邏輯 Session1 diff=50>30（排除），Session2 diff=10≤30（選中）→ 選了第二堂（錯誤）
     * 修復後（綠燈）：ongoing 優先 → 選第一堂（正確）
     */
    public function test_consecutive_sessions_late_arrival_matches_ongoing_session(): void
    {
        $today    = now()->toDateString();
        $student  = $this->makeStudent();
        $sc1      = $this->makeStudentClass($student->id);
        $sc2      = $this->makeStudentClass($student->id);

        $session1 = $this->makeSession($sc1->ID, $today, '10:00:00', '11:00:00');
        $session2 = $this->makeSession($sc2->ID, $today, '11:00:00', '12:00:00');

        $res = $this->swipeAt($student->RFID, Carbon::parse($today . ' 10:50:00'));

        $res->assertStatus(201)->assertJsonPath('action', 'sign_in');

        $signIn = StudentSignIn::where('StudentID', $student->id)->first();
        $this->assertNotNull($signIn);

        $this->assertSame($session1->id, (int) $signIn->ClassSessionID,
            'TD-011: 10:50 應匹配正在進行的第一堂（session1），不應匹配尚未開始的第二堂（session2）');
    }
}
