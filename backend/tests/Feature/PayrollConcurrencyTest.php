<?php

namespace Tests\Feature;

use App\Models\AuthToken;
use App\Models\ClassSession;
use App\Models\LearningRecord;
use App\Models\Student;
use App\Models\StudentClass;
use App\Models\User;
use App\Models\UserCampus;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * PRD §4.3 v1.4 同層級重疊薪資 tie-break 守護測試。
 *
 * v1.3 的「同層級每筆 LR 各保留全額基礎薪 + 各得加給」造成老師一個物理
 * 授課時段被重複計算 N 份基礎薪。v1.4 修正：同層級也採 tie-break，以
 * lr_id 最小者為主導者（primary）；其他同層 LR 在重疊段扣除
 * base_rate × Δt，基本費歸 0（再經 max(0, ...) 保底）。
 *
 * 本檔案集中守護 v1.4 規則，對照 docs/AI_REGRESSION_LESSONS.md。
 * 原有的 ParttimePayrollTest 也已更新為 v1.4 預期值，互為交叉驗證。
 */
class PayrollConcurrencyTest extends TestCase
{
    use RefreshDatabase;

    // ──────────────────────────────────────────
    // 同層級 n=2 完全重疊：primary 得 base+bonus，其他 net=0
    // ──────────────────────────────────────────
    public function test_same_level_n2_full_overlap_tie_break(): void
    {
        $dir = $this->createDirector('dir-sl-n2@test.com', [1]);
        $tid = $this->createPartTimeTeacher(1, 'pt-sl-n2@test.com', '同層n2');
        $stuA = $this->createStudent(1, 'n2-A');
        $stuB = $this->createStudent(1, 'n2-B');

        $scA = $this->makeStudentClass($stuA, $tid, 8, 'one_on_one'); // junior 350/h
        $scB = $this->makeStudentClass($stuB, $tid, 8, 'one_on_one'); // junior 350/h

        $this->makeApprovedLR($scA, $tid, '2026-04-03', '14:00', '16:00');
        $this->makeApprovedLR($scB, $tid, '2026-04-03', '14:00', '16:00');

        $teacher = $this->fetchPayroll($dir['token']);

        // A (primary): 350*2h + (2-1)*50*2h = 700 + 100 = 800
        // B (non-primary): 350*2h - 350*2h = 0, max(0,0) = 0
        $this->assertEquals(800, $teacher['total_salary']);
    }

    // ──────────────────────────────────────────
    // 同層級 n=3 完全重疊（Ruth蔣 04-03 案例）
    // ──────────────────────────────────────────
    public function test_same_level_n3_full_overlap_ruth_case(): void
    {
        $dir = $this->createDirector('dir-sl-n3@test.com', [1]);
        $tid = $this->createPartTimeTeacher(1, 'pt-sl-n3@test.com', 'Ruth蔣');
        $stuA = $this->createStudent(1, '陳則佑');
        $stuB = $this->createStudent(1, '張正甫');
        $stuC = $this->createStudent(1, '張正崗');

        // 全部國中英文，時薪 350，同層級
        $scA = $this->makeStudentClass($stuA, $tid, 8, 'one_on_three');
        $scB = $this->makeStudentClass($stuB, $tid, 8, 'one_on_two');
        $scC = $this->makeStudentClass($stuC, $tid, 8, 'one_on_two');

        $this->makeApprovedLR($scA, $tid, '2026-04-03', '18:00', '20:00');
        $this->makeApprovedLR($scB, $tid, '2026-04-03', '18:00', '20:00');
        $this->makeApprovedLR($scC, $tid, '2026-04-03', '18:00', '20:00');

        $teacher = $this->fetchPayroll($dir['token']);

        // A (primary, lr_id 最小): 350*2h + (3-1)*50*2h = 700 + 200 = 900
        // B, C (non-primary): 350*2h - 350*2h = 0 each
        // total = 900（而非 v1.3 的 750+825+825=2400）
        $this->assertEquals(900, $teacher['total_salary']);
    }

    // ──────────────────────────────────────────
    // 同層級部分重疊：非主導 LR 重疊段基本費歸 0，非重疊段保留
    // ──────────────────────────────────────────
    public function test_same_level_partial_overlap(): void
    {
        $dir = $this->createDirector('dir-sl-po@test.com', [1]);
        $tid = $this->createPartTimeTeacher(1, 'pt-sl-po@test.com', '部分重疊');
        $stuA = $this->createStudent(1, 'po-A');
        $stuB = $this->createStudent(1, 'po-B');

        $scA = $this->makeStudentClass($stuA, $tid, 8, 'one_on_one'); // junior 350/h
        $scB = $this->makeStudentClass($stuB, $tid, 8, 'one_on_one'); // junior 350/h

        $this->makeApprovedLR($scA, $tid, '2026-04-05', '14:00', '16:00');
        $this->makeApprovedLR($scB, $tid, '2026-04-05', '15:00', '17:00');

        $teacher = $this->fetchPayroll($dir['token']);

        // Overlap: 15:00-16:00 (1h)
        // A (primary, 2h): 350*2h + (2-1)*50*1h = 700 + 50 = 750
        // B (non-primary, 2h): 350*2h - 350*1h = 700 - 350 = 350
        // total = 1100
        $this->assertEquals(1100, $teacher['total_salary']);
    }

    // ──────────────────────────────────────────
    // 不同層級回歸守護：高層主導、低層扣基本費，行為與 v1.3 一致
    // ──────────────────────────────────────────
    public function test_different_level_regression_unchanged(): void
    {
        $dir = $this->createDirector('dir-dl@test.com', [1]);
        $tid = $this->createPartTimeTeacher(1, 'pt-dl@test.com', '不同層');
        $stuH = $this->createStudent(1, '高中生');
        $stuJ = $this->createStudent(1, '國中生');

        $scH = $this->makeStudentClass($stuH, $tid, 11, 'one_on_one'); // high 400/h
        $scJ = $this->makeStudentClass($stuJ, $tid, 8, 'one_on_one');  // junior 350/h

        $this->makeApprovedLR($scH, $tid, '2026-04-06', '14:00', '16:00');
        $this->makeApprovedLR($scJ, $tid, '2026-04-06', '14:00', '16:00');

        $teacher = $this->fetchPayroll($dir['token']);

        // 高中 weight=4 > 國中 weight=3
        // H (高層，唯一最高層 → primary): 400*2h + (2-1)*50*2h = 800 + 100 = 900
        // J (低層，dominated): 350*2h - 350*2h = 0, max(0,0) = 0
        // total = 900
        $this->assertEquals(900, $teacher['total_salary']);
    }

    // ──────────────────────────────────────────
    // 多個最高層級並存：最高層內也走 tie-break
    // ──────────────────────────────────────────
    public function test_multiple_max_level_tie_break_within_dominant(): void
    {
        $dir = $this->createDirector('dir-mm@test.com', [1]);
        $tid = $this->createPartTimeTeacher(1, 'pt-mm@test.com', '多最高層');
        $stuH1 = $this->createStudent(1, '高中生1');
        $stuH2 = $this->createStudent(1, '高中生2');
        $stuJ  = $this->createStudent(1, '國中生');

        $scH1 = $this->makeStudentClass($stuH1, $tid, 11, 'one_on_one'); // high 400/h
        $scH2 = $this->makeStudentClass($stuH2, $tid, 11, 'one_on_one'); // high 400/h
        $scJ  = $this->makeStudentClass($stuJ, $tid, 8, 'one_on_one');   // junior 350/h

        // H1 先建立 → lr_id 最小 → primary
        $this->makeApprovedLR($scH1, $tid, '2026-04-07', '14:00', '16:00');
        $this->makeApprovedLR($scH2, $tid, '2026-04-07', '14:00', '16:00');
        $this->makeApprovedLR($scJ,  $tid, '2026-04-07', '14:00', '16:00');

        $teacher = $this->fetchPayroll($dir['token']);

        // n=3 overlap: 2 個 high (weight=4) + 1 個 junior (weight=3)
        // primary 是 H1 (lr_id 最小於 max level 集合)
        // H1 (primary): 400*2h + (3-1)*50*2h = 800 + 200 = 1000
        // H2 (non-primary 但同最高層): 400*2h - 400*2h = 0
        // J  (被低層 dominated): 350*2h - 350*2h = 0
        // total = 1000
        $this->assertEquals(1000, $teacher['total_salary']);
    }

    // ──────────────────────────────────────────
    // 交換 LR 建立順序：tie-break 換人但總薪資不變（R-002 業界原則）
    // ──────────────────────────────────────────
    public function test_tie_break_swap_preserves_total_salary(): void
    {
        // 情境 1：A 先建立
        $dir1 = $this->createDirector('dir-sw1@test.com', [1]);
        $tid1 = $this->createPartTimeTeacher(1, 'pt-sw1@test.com', '順序1');
        $s1A = $this->createStudent(1, 'sw1-A');
        $s1B = $this->createStudent(1, 'sw1-B');
        $sc1A = $this->makeStudentClass($s1A, $tid1, 8, 'one_on_one');
        $sc1B = $this->makeStudentClass($s1B, $tid1, 8, 'one_on_one');
        $this->makeApprovedLR($sc1A, $tid1, '2026-04-08', '14:00', '16:00');
        $this->makeApprovedLR($sc1B, $tid1, '2026-04-08', '14:00', '16:00');

        // 情境 2：B 先建立
        $dir2 = $this->createDirector('dir-sw2@test.com', [1]);
        $tid2 = $this->createPartTimeTeacher(1, 'pt-sw2@test.com', '順序2');
        $s2A = $this->createStudent(1, 'sw2-A');
        $s2B = $this->createStudent(1, 'sw2-B');
        $sc2A = $this->makeStudentClass($s2A, $tid2, 8, 'one_on_one');
        $sc2B = $this->makeStudentClass($s2B, $tid2, 8, 'one_on_one');
        $this->makeApprovedLR($sc2B, $tid2, '2026-04-08', '14:00', '16:00'); // B 先
        $this->makeApprovedLR($sc2A, $tid2, '2026-04-08', '14:00', '16:00');

        $t1 = $this->fetchPayroll($dir1['token']);
        $t2 = $this->fetchPayroll($dir2['token']);

        // 財務上等值：哪筆是 primary 不影響老師總收入
        $this->assertEquals($t1['total_salary'], $t2['total_salary']);
        $this->assertEquals(800, $t1['total_salary']);
    }

    // ──────────────────────────────────────────
    // 無重疊：不進入 tie-break，各自計基礎薪
    // ──────────────────────────────────────────
    public function test_no_overlap_no_tie_break(): void
    {
        $dir = $this->createDirector('dir-no@test.com', [1]);
        $tid = $this->createPartTimeTeacher(1, 'pt-no@test.com', '無重疊');
        $stuA = $this->createStudent(1, 'no-A');
        $stuB = $this->createStudent(1, 'no-B');

        $scA = $this->makeStudentClass($stuA, $tid, 8, 'one_on_one');
        $scB = $this->makeStudentClass($stuB, $tid, 8, 'one_on_one');

        $this->makeApprovedLR($scA, $tid, '2026-04-09', '14:00', '16:00');
        $this->makeApprovedLR($scB, $tid, '2026-04-09', '17:00', '19:00');

        $teacher = $this->fetchPayroll($dir['token']);

        // 無重疊 → 各自 350*2h = 700
        // total = 1400
        $this->assertEquals(1400, $teacher['total_salary']);
    }

    // ──────────────────────────────────────────
    // LR 無 StartTime → 排除於重疊計算外
    // ──────────────────────────────────────────
    public function test_missing_time_excluded_from_tie_break(): void
    {
        $dir = $this->createDirector('dir-mt@test.com', [1]);
        $tid = $this->createPartTimeTeacher(1, 'pt-mt@test.com', '無時間');
        $stuA = $this->createStudent(1, 'mt-A');
        $stuB = $this->createStudent(1, 'mt-B');

        $scA = $this->makeStudentClass($stuA, $tid, 8, 'one_on_one');
        $scB = $this->makeStudentClass($stuB, $tid, 8, 'one_on_one');

        $this->makeApprovedLR($scA, $tid, '2026-04-10', '14:00', '16:00');
        $lrB = $this->makeApprovedLR($scB, $tid, '2026-04-10', '14:00', '16:00');
        $lrB->update(['StartTime' => null, 'EndTime' => null]);

        $teacher = $this->fetchPayroll($dir['token']);

        // A 無 overlap partner → 350*2h = 700
        // B 無時間 fallback SessionDuration 120min → 350*2h = 700
        // total = 1400
        $this->assertEquals(1400, $teacher['total_salary']);
    }

    // ──────────────────────────────────────────────────────────
    // Helpers
    // ──────────────────────────────────────────────────────────

    private function fetchPayroll(string $token): array
    {
        $res = $this->withHeaders([
            'Authorization' => "Bearer {$token}",
            'Accept'        => 'application/json',
        ])->getJson('/api/v1/finance/parttime-payroll?month=2026-04&branch_id=1');

        $res->assertOk();
        return $res->json('teachers.0');
    }

    private function createDirector(string $email, array $campusIds): array
    {
        $user = User::create([
            'LoginName' => $email, 'Name' => '主任', 'PSW' => 'secret',
            'type' => 'A', 'phone' => '0911000000', 'MustChangePassword' => false,
        ]);
        foreach ($campusIds as $cid) {
            UserCampus::create(['CampusID' => $cid, 'UserID' => $user->id, 'Admin' => 1, 'Approved' => 1]);
        }
        $token = bin2hex(random_bytes(16));
        AuthToken::create(['user_id' => $user->id, 'token' => $token, 'expires_at' => now()->addDay()]);
        return ['token' => $token, 'user_id' => (int) $user->id];
    }

    private function createPartTimeTeacher(int $campusId, string $email, string $name): int
    {
        $user = User::create([
            'LoginName' => $email, 'Name' => $name, 'PSW' => 'secret',
            'type' => 'T', 'phone' => '0922' . rand(100000, 999999),
            'MustChangePassword' => false, 'employment_type' => 'part_time',
        ]);
        UserCampus::create(['CampusID' => $campusId, 'UserID' => $user->id, 'Admin' => 0, 'Approved' => 1]);
        return (int) $user->id;
    }

    private function createStudent(int $campusId, string $name): Student
    {
        return Student::create([
            'name' => $name, 'CampusID' => $campusId, 'ClassID' => 7,
            'enable' => 1, 'MDT' => now(), 'Notify_Token' => '',
        ]);
    }

    private function makeStudentClass(Student $stu, int $teacherId, int $gradeId, string $classType): StudentClass
    {
        return StudentClass::create([
            'StudentID' => $stu->id, 'TeacherID' => $teacherId, 'GradeID' => $gradeId,
            'SubjectID' => 1, 'ClassType' => $classType, 'by1' => 1,
            'ScheduleMode' => 'count', 'SessionCount' => 10, 'RemainingSessions' => 9,
            'UsedSessions' => 1, 'Rate' => 500, 'Charge' => 5000, 'Pay' => 0, 'Paid' => 0,
            'Period' => 4, 'SessionDuration' => 120, 'TotalHours' => 20,
            'StartDate' => '2026-04-01', 'EndDate' => '2026-06-30',
            'week' => 2, 'time' => '16:00:00', 'RoomID' => 'R1', 'Stop' => 0, 'MDate' => now(),
        ]);
    }

    private function makeApprovedLR(StudentClass $sc, int $teacherId, string $date, string $start, string $end): LearningRecord
    {
        $cs = ClassSession::create([
            'StudentClassID' => $sc->ID, 'SessionDate' => $date,
            'StartTime' => "{$start}:00", 'EndTime' => "{$end}:00",
            'Status' => 'completed', 'Note' => '',
        ]);
        return LearningRecord::create([
            'StudentClassID' => $sc->ID, 'ClassSessionID' => $cs->id,
            'TeacherID' => $teacherId, 'Content' => 'test', 'Subject' => 'Math',
            'Status' => 'approved', 'SessionDate' => $date,
            'StartTime' => "{$start}:00", 'EndTime' => "{$end}:00",
            'Hours' => 2, 'Note' => '',
        ]);
    }
}
