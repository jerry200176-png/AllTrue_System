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
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * PRD 四項系統問題修正 §PRD-C DoD 回歸守護（2026-04-18, plan 8c1673b9）.
 *
 * 背景：興隆主任回報「游靜鈴薪資有誤、Ruth 蔣正確」。
 * AI Agent 偵錯結論：兩位 part_time 老師在相同 `payroll_branch_rules` 之下經由
 * 完全相同的 FinanceController 計算路徑（branch_default rule + tie-break 併堂 +
 * CONCURRENCY_START_TOLERANCE_MINUTES=15）產出薪資；沒有程式邏輯分支差異。
 * 直觀上 Ruth 看起來比較「有被扣」是因為她教兄弟姊妹 group class 觸發併堂
 * tie-break（primary + bonus, non-primary net=0），而非游被特殊處理。
 *
 * 本測試守護 DoD：相同分校下的兩位 part_time 老師、相同 class_type + grade_level、
 * 完全獨立（無併堂）的 session，effective_rate 與 session_salary 必須相同。
 * 若此測試 fail 代表有回歸（例如有人又給某位老師加了 hardcode 分支），須立刻檢查。
 */
class PayrollRateConsistencyTest extends TestCase
{
    use RefreshDatabase;

    public function test_two_part_time_teachers_share_branch_default_rate(): void
    {
        $dir = $this->createDirector('dir-rate-consistency@test.com', [1]);

        // 兩位 part_time 老師在同一分校（branch_id=1，branch_default rule fallback）
        $youTid  = $this->createPartTimeTeacher(1, 'you-jingling@test.com', '游靜鈴');
        $ruthTid = $this->createPartTimeTeacher(1, 'ruth-chiang@test.com', 'Ruth蔣');

        // 相同 class_type + 相同 grade_level（GradeID=8, junior）
        $stuA = $this->createStudent(1, 'rate-A');
        $stuB = $this->createStudent(1, 'rate-B');
        $scA  = $this->makeStudentClass($stuA, $youTid, 8, 'one_on_two');
        $scB  = $this->makeStudentClass($stuB, $ruthTid, 8, 'one_on_two');

        // 兩位老師各自獨立一堂（避免觸發併堂），才能純比對基礎費率
        $this->makeApprovedLR($scA, $youTid,  '2026-04-03', '14:00', '16:00');
        $this->makeApprovedLR($scB, $ruthTid, '2026-04-04', '14:00', '16:00');

        $res = $this->withHeaders([
            'Authorization' => 'Bearer '.$dir['token'],
            'Accept'        => 'application/json',
        ])->getJson('/api/v1/finance/parttime-payroll?month=2026-04&branch_id=1');

        $res->assertOk();
        $teachers = collect($res->json('teachers'));
        $this->assertCount(2, $teachers, '應有兩位 part_time 老師入帳');

        $you  = $teachers->firstWhere('teacher_id', $youTid);
        $ruth = $teachers->firstWhere('teacher_id', $ruthTid);
        $this->assertNotNull($you);
        $this->assertNotNull($ruth);

        // 核心 DoD：相同 class_type+grade_level 下 total_salary、hours 完全一致
        $this->assertSame(
            $you['total_salary'],
            $ruth['total_salary'],
            '同 branch_default rule 下兩位 part_time 老師獨立課的 session_salary 必須一致'
        );
        $this->assertSame(
            $you['total_hours'],
            $ruth['total_hours'],
            'contractedDurationMinutes 對兩位老師應產出相同小時數'
        );

        // junior 2h: 350 × 2 = 700
        $this->assertEquals(700, $you['total_salary']);
        $this->assertEquals(700, $ruth['total_salary']);
    }

    public function test_group_class_bonus_only_applies_when_start_times_within_tolerance(): void
    {
        // 守護：Ruth 真正併堂（兄弟姊妹同時段）觸發 tie-break；
        // 游排定時段錯開 >15 分鐘不觸發併堂（v1.5 規則）。
        $dir = $this->createDirector('dir-tolerance@test.com', [1]);

        $ruthTid = $this->createPartTimeTeacher(1, 'ruth-group@test.com', 'Ruth蔣');
        $ruthA   = $this->createStudent(1, '張正樂');
        $ruthB   = $this->createStudent(1, '張正嵐');
        $scR_A   = $this->makeStudentClass($ruthA, $ruthTid, 7, 'one_on_two');
        $scR_B   = $this->makeStudentClass($ruthB, $ruthTid, 7, 'one_on_two');
        $this->makeApprovedLR($scR_A, $ruthTid, '2026-04-03', '19:30', '21:30');
        $this->makeApprovedLR($scR_B, $ruthTid, '2026-04-03', '19:30', '21:30');

        $youTid = $this->createPartTimeTeacher(1, 'you-staggered@test.com', '游靜鈴');
        $youA   = $this->createStudent(1, '謝欣彤');
        $youB   = $this->createStudent(1, '李昀霈');
        $scY_A  = $this->makeStudentClass($youA, $youTid, 7, 'one_on_three');
        $scY_B  = $this->makeStudentClass($youB, $youTid, 7, 'one_on_three');
        $this->makeApprovedLR($scY_A, $youTid, '2026-04-05', '09:30', '11:30');
        $this->makeApprovedLR($scY_B, $youTid, '2026-04-05', '10:00', '12:00');

        $res = $this->withHeaders([
            'Authorization' => 'Bearer '.$dir['token'],
            'Accept'        => 'application/json',
        ])->getJson('/api/v1/finance/parttime-payroll?month=2026-04&branch_id=1');
        $res->assertOk();
        $teachers = collect($res->json('teachers'));

        $ruth = $teachers->firstWhere('teacher_id', $ruthTid);
        $you  = $teachers->firstWhere('teacher_id', $youTid);

        // Ruth 併堂：primary 350*2 + (2-1)*50*2 = 800, non-primary 0 → 800
        $this->assertEquals(800, $ruth['total_salary']);

        // 2026-04-18 晚間規則更新（PRD-F，主任確認，取代 v1.5 CONCURRENCY_START_TOLERANCE_MINUTES=15）：
        // 30 分鐘 slice 模型，真實重疊部份算並堂、不重疊部份各自獨立。
        //   09:30-10:00 A solo：350 × 0.5h = 175
        //   10:00-11:30 A+B：max(350)+50 = 400/h × 1.5h = 600
        //   11:30-12:00 B solo：350 × 0.5h = 175
        //   總計 950
        $this->assertEquals(950, $you['total_salary']);
    }

    // ───────────────── Helpers ─────────────────

    private function createDirector(string $email, array $campusIds): array
    {
        $user = User::create([
            'LoginName' => $email, 'Name' => '主任', 'PSW' => 'secret',
            'type' => 'A', 'phone' => '0911000000', 'MustChangePassword' => false,
        ]);
        foreach ($campusIds as $cid) {
            UserCampus::create(['CampusID' => $cid, 'UserID' => $user->id, 'Admin' => 1, 'Approved' => 1]);
        }
        $token = AuthToken::create([
            'user_id' => $user->id, 'token' => 'tok-'.$user->id.'-'.uniqid(),
            'expires_at' => now()->addDay(),
        ]);
        return ['user' => $user, 'token' => $token->token];
    }

    private function createPartTimeTeacher(int $campusId, string $email, string $name): int
    {
        $user = User::create([
            'LoginName' => $email, 'Name' => $name, 'PSW' => 'secret',
            'type' => 'T', 'phone' => '0922111111', 'MustChangePassword' => false,
            'employment_type' => 'part_time',
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
            'week' => 2, 'time' => '16:00:00', 'Stop' => 0, 'MDate' => now(),
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
