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
 * Batch 4 Tech Debt — TD-008
 *
 * 測試 `student-signin:close-orphans` 指令（每日凌晨修補跨日孤兒 SignIn）。
 * 孤兒定義：SignOutDT = null 且 SignInDT < today（昨天或更早的未刷出記錄）
 *
 * 現況（紅燈）：指令不存在 → artisan 呼叫失敗
 * 修復後（綠燈）：SignOutDT 填入正確時間，今日記錄不受影響
 *
 * ⚠️ CI only — 不可在 production 跑（RefreshDatabase）
 */
class CloseOrphanStudentSignInsTest extends TestCase
{
    use RefreshDatabase;

    private Campus $campus;
    private int $subjectId;

    protected function setUp(): void
    {
        parent::setUp();

        $this->campus = Campus::create([
            'name'           => 'OrphanCampus',
            'Token'          => 'orphan-token-test',
            'code'           => 'orphan',
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
            'Subject_Name' => 'OrphanSubject',
        ]);
        $this->subjectId = $subject->id;
    }

    private function makeStudent(): Student
    {
        static $n = 0;
        $n++;
        return Student::create([
            'name'     => "OrphanStudent{$n}",
            'CampusID' => $this->campus->id,
            'ClassID'  => 1,
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
            'RoomID'       => '',
            'StartDate'    => now()->subYear(),
            'Stop'         => 0,
            'SessionCount' => 10,
            'ScheduleMode' => 'count',
        ]);
    }

    private function makeClassSession(int $scId, string $date, string $endTime): ClassSession
    {
        return ClassSession::create([
            'StudentClassID' => $scId,
            'SessionDate'    => $date,
            'StartTime'      => '10:00:00',
            'EndTime'        => $endTime,
            'Status'         => 'attended',
        ]);
    }

    private function makeOrphan(int $studentId, int $scId, string $signInDT): StudentSignIn
    {
        return StudentSignIn::create([
            'StudentClassID'  => $scId,
            'StudentID'       => $studentId,
            'TeacherID'       => 1,
            'SubjectID'       => $this->subjectId,
            'GradeID'         => 1,
            'Memo'            => 'swipe-rfid',
            'SignInDT'        => $signInDT,
            'SignOutDT'       => null,
            'MDT'             => $signInDT,
            'Status'          => 'present',
            'CampusID'        => $this->campus->id,
            'PersonType'      => 'student',
            'SessionDeducted' => false,
        ]);
    }

    // ── Tests ─────────────────────────────────────────────────────────────────

    /**
     * @test
     * TD-008 (1/3): 昨天孤兒 SignIn → SignOutDT = 當日最後一堂 ClassSession.EndTime。
     *
     * 現況（紅燈）：指令不存在 → artisan 回傳非 0 exit code
     * 修復後（綠燈）：SignOutDT = 昨天 15:00
     */
    public function test_orphan_gets_sign_out_from_last_session_end_time(): void
    {
        $yesterday = now()->subDay()->toDateString();

        $student = $this->makeStudent();
        $sc      = $this->makeStudentClass($student->id);
        $this->makeClassSession($sc->ID, $yesterday, '15:00:00');

        $orphan = $this->makeOrphan($student->id, $sc->ID, $yesterday . ' 10:00:00');

        $this->artisan('student-signin:close-orphans')->assertExitCode(0);

        $orphan->refresh();

        $this->assertNotNull($orphan->SignOutDT,
            'TD-008: 孤兒記錄應被自動補上 SignOutDT');

        $this->assertStringContainsString('15:00', $orphan->SignOutDT,
            'TD-008: SignOutDT 應等於最後一堂課的 EndTime（15:00）');
    }

    /**
     * @test
     * TD-008 (2/3): 昨天孤兒但無 ClassSession → SignOutDT = 昨天 22:00（fallback）。
     *
     * 現況（紅燈）：指令不存在
     * 修復後（綠燈）：SignOutDT = 昨天 22:00
     */
    public function test_orphan_without_session_falls_back_to_2200(): void
    {
        $yesterday = now()->subDay()->toDateString();

        $student = $this->makeStudent();
        $sc      = $this->makeStudentClass($student->id);
        // 刻意不建立 ClassSession

        $orphan = $this->makeOrphan($student->id, $sc->ID, $yesterday . ' 09:00:00');

        $this->artisan('student-signin:close-orphans')->assertExitCode(0);

        $orphan->refresh();

        $this->assertNotNull($orphan->SignOutDT,
            'TD-008: 無 ClassSession 孤兒也應被補上 SignOutDT（fallback 22:00）');

        $this->assertStringContainsString('22:00', $orphan->SignOutDT,
            'TD-008: 無 ClassSession 時 SignOutDT 應 fallback 為 22:00');
    }

    /**
     * @test
     * TD-008 (3/3): 今天的 open SignIn 不應被指令關閉（迴歸保護）。
     *
     * 此測試修復前後均應通過。
     */
    public function test_todays_open_signin_is_not_touched(): void
    {
        $today   = now()->toDateString();
        $student = $this->makeStudent();
        $sc      = $this->makeStudentClass($student->id);

        $todayRecord = $this->makeOrphan($student->id, $sc->ID, $today . ' 10:00:00');

        $this->artisan('student-signin:close-orphans')->assertExitCode(0);

        $todayRecord->refresh();

        $this->assertNull($todayRecord->SignOutDT,
            'TD-008: 今日 open 記錄不應被指令修改，SignOutDT 應保持 null');
    }
}
