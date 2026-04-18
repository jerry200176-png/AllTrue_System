<?php

namespace Tests\Feature;

use App\Models\AuthToken;
use App\Models\ClassSession;
use App\Models\LearningRecord;
use App\Models\PayrollMonthStatus;
use App\Models\Student;
use App\Models\StudentClass;
use App\Models\User;
use App\Models\UserCampus;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class ParttimePayrollTest extends TestCase
{
    use RefreshDatabase;

    // ──────────────────────────────────────────
    // Golden calculation: one-on-one high school
    // ──────────────────────────────────────────
    public function test_golden_one_on_one_high_school_rate(): void
    {
        $dir = $this->createDirector('dir-payroll-1@test.com', [1]);
        $tid = $this->createPartTimeTeacher(1, 'pt1@test.com', '兼職甲');
        $stu = $this->createStudent(1, '高中生A');

        $sc = $this->makeStudentClass($stu, $tid, 10, 'one_on_one');
        $this->makeApprovedLR($sc, $tid, '2026-04-01', '16:00', '18:00');

        $res = $this->withHeaders($this->authHeaders($dir['token']))
            ->getJson('/api/v1/finance/parttime-payroll?month=2026-04&branch_id=1');

        $res->assertOk();
        $data = $res->json();
        $this->assertCount(1, $data['teachers']);
        $teacher = $data['teachers'][0];
        $this->assertEquals(800, $teacher['total_salary']); // 400/h * 2h
        $this->assertEquals(2, $teacher['total_hours']);
        $this->assertEquals(2, $teacher['high_hours']);
    }

    // ──────────────────────────────────────────
    // Golden: one-on-two junior high
    // ──────────────────────────────────────────
    public function test_golden_one_on_two_junior_rate(): void
    {
        $dir = $this->createDirector('dir-payroll-2@test.com', [1]);
        $tid = $this->createPartTimeTeacher(1, 'pt2@test.com', '兼職乙');
        $stu = $this->createStudent(1, '國中生B');

        $sc = $this->makeStudentClass($stu, $tid, 7, 'one_on_two');
        $this->makeApprovedLR($sc, $tid, '2026-04-02', '10:00', '12:00');

        $res = $this->withHeaders($this->authHeaders($dir['token']))
            ->getJson('/api/v1/finance/parttime-payroll?month=2026-04&branch_id=1');

        $res->assertOk();
        $teacher = $res->json('teachers.0');
        // base 350/h * 2h = 700 (headcountBonus removed; single LR = no concurrency)
        $this->assertEquals(700, $teacher['total_salary']);
        $this->assertEquals(2, $teacher['junior_hours']);
    }

    // ──────────────────────────────────────────
    // Golden: one-on-three elementary
    // ──────────────────────────────────────────
    public function test_golden_one_on_three_elementary_rate(): void
    {
        $dir = $this->createDirector('dir-payroll-3@test.com', [1]);
        $tid = $this->createPartTimeTeacher(1, 'pt3@test.com', '兼職丙');
        $stu = $this->createStudent(1, '國小生C');

        $sc = $this->makeStudentClass($stu, $tid, 3, 'one_on_three');
        $this->makeApprovedLR($sc, $tid, '2026-04-03', '14:00', '16:00');

        $res = $this->withHeaders($this->authHeaders($dir['token']))
            ->getJson('/api/v1/finance/parttime-payroll?month=2026-04&branch_id=1');

        $res->assertOk();
        $teacher = $res->json('teachers.0');
        // base 300/h * 2h = 600 (headcountBonus removed; single LR = no concurrency)
        $this->assertEquals(600, $teacher['total_salary']);
        $this->assertEquals(2, $teacher['elementary_hours']);
    }

    // ──────────────────────────────────────────
    // Golden: tutoring always 200/h, no headcount bonus
    // ──────────────────────────────────────────
    public function test_golden_tutoring_rate_no_bonus(): void
    {
        $dir = $this->createDirector('dir-payroll-4@test.com', [1]);
        $tid = $this->createPartTimeTeacher(1, 'pt4@test.com', '兼職丁');
        $stu = $this->createStudent(1, '輔導生D');

        $sc = $this->makeStudentClass($stu, $tid, 7, 'tutoring');
        $this->makeApprovedLR($sc, $tid, '2026-04-04', '09:00', '11:00');

        $res = $this->withHeaders($this->authHeaders($dir['token']))
            ->getJson('/api/v1/finance/parttime-payroll?month=2026-04&branch_id=1');

        $res->assertOk();
        $teacher = $res->json('teachers.0');
        // tutoring 200/h * 2h = 400
        $this->assertEquals(400, $teacher['total_salary']);
        $this->assertEquals(2, $teacher['tutoring_hours']);
    }

    // ──────────────────────────────────────────
    // Only part_time teachers show up
    // ──────────────────────────────────────────
    public function test_excludes_full_time_teachers(): void
    {
        $dir = $this->createDirector('dir-payroll-5@test.com', [1]);
        $ptId = $this->createPartTimeTeacher(1, 'pt5@test.com', '兼職');
        $ftId = $this->createFullTimeTeacher(1, 'ft5@test.com', '專職');
        $stu1 = $this->createStudent(1, '學生E');
        $stu2 = $this->createStudent(1, '學生F');

        $sc1 = $this->makeStudentClass($stu1, $ptId, 10, 'one_on_one');
        $sc2 = $this->makeStudentClass($stu2, $ftId, 10, 'one_on_one');
        $this->makeApprovedLR($sc1, $ptId, '2026-04-05', '10:00', '12:00');
        $this->makeApprovedLR($sc2, $ftId, '2026-04-05', '14:00', '16:00');

        $res = $this->withHeaders($this->authHeaders($dir['token']))
            ->getJson('/api/v1/finance/parttime-payroll?month=2026-04&branch_id=1');

        $res->assertOk();
        $this->assertCount(1, $res->json('teachers'));
        $this->assertEquals('兼職', $res->json('teachers.0.teacher_name'));
    }

    // ──────────────────────────────────────────
    // Only approved + active records count
    // ──────────────────────────────────────────
    public function test_excludes_pending_and_voided_records(): void
    {
        $dir = $this->createDirector('dir-payroll-6@test.com', [1]);
        $tid = $this->createPartTimeTeacher(1, 'pt6@test.com', '兼職戊');
        $stu = $this->createStudent(1, '學生G');

        $sc = $this->makeStudentClass($stu, $tid, 7, 'one_on_one');

        // Approved
        $this->makeApprovedLR($sc, $tid, '2026-04-10', '10:00', '12:00');
        // Pending — should be excluded
        $this->makeLR($sc, $tid, '2026-04-11', '10:00', '12:00', 'pending');
        // Voided — should be excluded
        $voided = $this->makeLR($sc, $tid, '2026-04-12', '10:00', '12:00', 'approved');
        $voided->update(['VoidedAt' => now()]);

        $res = $this->withHeaders($this->authHeaders($dir['token']))
            ->getJson('/api/v1/finance/parttime-payroll?month=2026-04&branch_id=1');

        $res->assertOk();
        $this->assertEquals(1, $res->json('teachers.0.session_count'));
    }

    // ──────────────────────────────────────────
    // Branch isolation
    // ──────────────────────────────────────────
    public function test_branch_isolation(): void
    {
        $dir1 = $this->createDirector('dir-b1@test.com', [1]);
        $dir2 = $this->createDirector('dir-b2@test.com', [2]);
        $tid = $this->createPartTimeTeacher(1, 'pt7@test.com', '兼職己');
        $stu1 = $this->createStudent(1, '分校1學生');
        $stu2 = $this->createStudent(2, '分校2學生');

        $sc1 = $this->makeStudentClass($stu1, $tid, 10, 'one_on_one');
        $sc2 = $this->makeStudentClass($stu2, $tid, 10, 'one_on_one');
        $this->makeApprovedLR($sc1, $tid, '2026-04-15', '10:00', '12:00');
        $this->makeApprovedLR($sc2, $tid, '2026-04-15', '14:00', '16:00');

        // Director 1 sees only branch 1
        $r1 = $this->withHeaders($this->authHeaders($dir1['token']))
            ->getJson('/api/v1/finance/parttime-payroll?month=2026-04&branch_id=1');
        $r1->assertOk();
        $this->assertEquals(1, $r1->json('teachers.0.session_count'));

        // Director 2 sees only branch 2
        $r2 = $this->withHeaders($this->authHeaders($dir2['token']))
            ->getJson('/api/v1/finance/parttime-payroll?month=2026-04&branch_id=2');
        $r2->assertOk();
        $this->assertEquals(1, $r2->json('teachers.0.session_count'));
    }

    // ──────────────────────────────────────────
    // Teacher sessions endpoint pagination
    // ──────────────────────────────────────────
    public function test_teacher_sessions_pagination(): void
    {
        $dir = $this->createDirector('dir-payroll-8@test.com', [1]);
        $tid = $this->createPartTimeTeacher(1, 'pt8@test.com', '分頁老師');
        $stu = $this->createStudent(1, '學生H');
        $sc = $this->makeStudentClass($stu, $tid, 10, 'one_on_one');

        for ($d = 1; $d <= 5; $d++) {
            $this->makeApprovedLR($sc, $tid, sprintf('2026-04-%02d', $d), '10:00', '12:00');
        }

        $res = $this->withHeaders($this->authHeaders($dir['token']))
            ->getJson("/api/v1/finance/parttime-payroll/{$tid}/sessions?month=2026-04&branch_id=1&per_page=2&page=1");

        $res->assertOk();
        $this->assertCount(2, $res->json('sessions'));
        $this->assertEquals(5, $res->json('meta.total'));
        $this->assertEquals(3, $res->json('meta.last_page'));
    }

    // ──────────────────────────────────────────
    // Lock & reopen workflow
    // ──────────────────────────────────────────
    public function test_lock_and_reopen_workflow(): void
    {
        $dir = $this->createDirector('dir-payroll-9@test.com', [1]);
        $superAdmin = $this->createSuperAdmin('sa-payroll@test.com', [1]);

        // Lock
        $r1 = $this->withHeaders($this->authHeaders($dir['token']))
            ->postJson('/api/v1/finance/parttime-payroll/lock', ['month' => '2026-04', 'branch_id' => 1]);
        $r1->assertOk();
        $this->assertEquals('locked', $r1->json('status'));

        // Double lock should fail
        $r2 = $this->withHeaders($this->authHeaders($dir['token']))
            ->postJson('/api/v1/finance/parttime-payroll/lock', ['month' => '2026-04', 'branch_id' => 1]);
        $r2->assertStatus(422);

        // Director cannot reopen
        $r3 = $this->withHeaders($this->authHeaders($dir['token']))
            ->postJson('/api/v1/finance/parttime-payroll/reopen', ['month' => '2026-04', 'branch_id' => 1, 'reason' => 'test']);
        $r3->assertStatus(403);

        // Super admin can reopen
        $r4 = $this->withHeaders($this->authHeaders($superAdmin['token']))
            ->postJson('/api/v1/finance/parttime-payroll/reopen', ['month' => '2026-04', 'branch_id' => 1, 'reason' => '重新計算']);
        $r4->assertOk();
        $this->assertEquals('draft', $r4->json('status'));

        // Verify audit log
        $this->assertDatabaseHas('payroll_audit_log', ['branch_id' => 1, 'month' => '2026-04', 'action' => 'lock']);
        $this->assertDatabaseHas('payroll_audit_log', ['branch_id' => 1, 'month' => '2026-04', 'action' => 'reopen', 'reason' => '重新計算']);
    }

    // ──────────────────────────────────────────
    // Export endpoint returns CSV
    // ──────────────────────────────────────────
    public function test_export_returns_csv(): void
    {
        $dir = $this->createDirector('dir-payroll-10@test.com', [1]);
        $tid = $this->createPartTimeTeacher(1, 'pt10@test.com', '匯出老師');
        $stu = $this->createStudent(1, '學生X');
        $sc = $this->makeStudentClass($stu, $tid, 10, 'one_on_one');
        $this->makeApprovedLR($sc, $tid, '2026-04-07', '14:00', '16:00');

        $res = $this->withHeaders($this->authHeaders($dir['token']))
            ->get('/api/v1/finance/parttime-payroll/export?month=2026-04&branch_id=1');

        $res->assertOk();
        $content = $res->streamedContent();
        $this->assertStringContainsString('老師姓名', $content);
        $this->assertStringContainsString('匯出老師', $content);
    }

    // ──────────────────────────────────────────
    // Month validation
    // ──────────────────────────────────────────
    public function test_invalid_month_returns_422(): void
    {
        $dir = $this->createDirector('dir-payroll-11@test.com', [1]);

        $res = $this->withHeaders($this->authHeaders($dir['token']))
            ->getJson('/api/v1/finance/parttime-payroll?month=bad&branch_id=1');

        $res->assertStatus(422);
    }

    // ──────────────────────────────────────────
    // Concurrency bonus: two 1-on-1 sessions overlap 1h
    // ──────────────────────────────────────────
    public function test_concurrency_bonus_two_sessions_overlap_1h(): void
    {
        $dir = $this->createDirector('dir-cb1@test.com', [1]);
        $tid = $this->createPartTimeTeacher(1, 'ptcb1@test.com', '併堂甲');
        $stuA = $this->createStudent(1, '學生A');
        $stuB = $this->createStudent(1, '學生B');

        $scA = $this->makeStudentClass($stuA, $tid, 10, 'one_on_one');
        $scB = $this->makeStudentClass($stuB, $tid, 10, 'one_on_one');

        $this->makeApprovedLR($scA, $tid, '2026-04-10', '17:00', '19:00');
        $this->makeApprovedLR($scB, $tid, '2026-04-10', '18:00', '20:00');

        $res = $this->withHeaders($this->authHeaders($dir['token']))
            ->getJson('/api/v1/finance/parttime-payroll?month=2026-04&branch_id=1');

        $res->assertOk();
        $teacher = $res->json('teachers.0');
        // PRD-F (2026-04-18)：30-min slice 模型，重疊部份算並堂。
        // 17:00-18:00 A solo 400 + 18:00-19:00 A+B (400+50) + 19:00-20:00 B solo 400 = 1250
        $this->assertEquals(1250, $teacher['total_salary']);
    }

    // ──────────────────────────────────────────
    // Concurrency bonus: 0.5h overlap
    // ──────────────────────────────────────────
    public function test_concurrency_bonus_half_hour_overlap(): void
    {
        $dir = $this->createDirector('dir-cb2@test.com', [1]);
        $tid = $this->createPartTimeTeacher(1, 'ptcb2@test.com', '併堂乙');
        $stuA = $this->createStudent(1, '學生C');
        $stuB = $this->createStudent(1, '學生D');

        $scA = $this->makeStudentClass($stuA, $tid, 10, 'one_on_one');
        $scB = $this->makeStudentClass($stuB, $tid, 10, 'one_on_one');

        $this->makeApprovedLR($scA, $tid, '2026-04-10', '17:00', '19:00');
        $this->makeApprovedLR($scB, $tid, '2026-04-10', '18:30', '20:00');

        $res = $this->withHeaders($this->authHeaders($dir['token']))
            ->getJson('/api/v1/finance/parttime-payroll?month=2026-04&branch_id=1');

        $res->assertOk();
        $teacher = $res->json('teachers.0');
        // PRD-F：30-min slice。A 17:00-19:00、B 18:30-20:30（contracted 120min）→ 重疊 18:30-19:00。
        // 17:00-18:30 A solo 400×1.5=600 + 18:30-19:00 並堂 (400+50)×0.5=225 + 19:00-20:30 B solo 400×1.5=600 = 1425
        $this->assertEquals(1425, $teacher['total_salary']);
    }

    // ──────────────────────────────────────────
    // Concurrency bonus: 2h full overlap
    // ──────────────────────────────────────────
    public function test_concurrency_bonus_two_hour_overlap(): void
    {
        $dir = $this->createDirector('dir-cb3@test.com', [1]);
        $tid = $this->createPartTimeTeacher(1, 'ptcb3@test.com', '併堂丙');
        $stuA = $this->createStudent(1, '學生E');
        $stuB = $this->createStudent(1, '學生F');

        $scA = $this->makeStudentClass($stuA, $tid, 10, 'one_on_one');
        $scB = $this->makeStudentClass($stuB, $tid, 10, 'one_on_one');

        $this->makeApprovedLR($scA, $tid, '2026-04-10', '17:00', '20:00');
        $this->makeApprovedLR($scB, $tid, '2026-04-10', '18:00', '20:00');

        $res = $this->withHeaders($this->authHeaders($dir['token']))
            ->getJson('/api/v1/finance/parttime-payroll?month=2026-04&branch_id=1');

        $res->assertOk();
        $teacher = $res->json('teachers.0');
        // PRD-F：contracted=120min，A 17:00-19:00、B 18:00-20:00 → 重疊 18:00-19:00。
        // 17:00-18:00 solo 400 + 18:00-19:00 並堂 450 + 19:00-20:00 solo 400 = 1250
        $this->assertEquals(1250, $teacher['total_salary']);
    }

    // ──────────────────────────────────────────
    // Concurrency: one_on_two + one_on_one overlap 1h (k=3 students)
    // ──────────────────────────────────────────
    public function test_concurrency_bonus_mixed_class_types(): void
    {
        $dir = $this->createDirector('dir-cb4@test.com', [1]);
        $tid = $this->createPartTimeTeacher(1, 'ptcb4@test.com', '併堂丁');
        $stuA = $this->createStudent(1, '學生G');
        $stuB = $this->createStudent(1, '學生H');

        $scA = $this->makeStudentClass($stuA, $tid, 10, 'one_on_two');
        $scB = $this->makeStudentClass($stuB, $tid, 10, 'one_on_one');

        $this->makeApprovedLR($scA, $tid, '2026-04-10', '17:00', '19:00');
        $this->makeApprovedLR($scB, $tid, '2026-04-10', '18:00', '20:00');

        $res = $this->withHeaders($this->authHeaders($dir['token']))
            ->getJson('/api/v1/finance/parttime-payroll?month=2026-04&branch_id=1');

        $res->assertOk();
        $teacher = $res->json('teachers.0');
        // PRD-F：17:00-18:00 A solo 400 + 18:00-19:00 A+B 並堂 (400+50) + 19:00-20:00 B solo 400 = 1250
        $this->assertEquals(1250, $teacher['total_salary']);
    }

    // ──────────────────────────────────────────
    // Concurrency: tutoring + one_on_one overlap
    // ──────────────────────────────────────────
    public function test_concurrency_bonus_tutoring_plus_regular(): void
    {
        $dir = $this->createDirector('dir-cb5@test.com', [1]);
        $tid = $this->createPartTimeTeacher(1, 'ptcb5@test.com', '併堂戊');
        $stuA = $this->createStudent(1, '學生I');
        $stuB = $this->createStudent(1, '學生J');

        $scA = $this->makeStudentClass($stuA, $tid, 7, 'tutoring');
        $scB = $this->makeStudentClass($stuB, $tid, 3, 'one_on_one');

        $this->makeApprovedLR($scA, $tid, '2026-04-10', '17:00', '19:00');
        $this->makeApprovedLR($scB, $tid, '2026-04-10', '18:00', '20:00');

        $res = $this->withHeaders($this->authHeaders($dir['token']))
            ->getJson('/api/v1/finance/parttime-payroll?month=2026-04&branch_id=1');

        $res->assertOk();
        $teacher = $res->json('teachers.0');
        // PRD-F：max-base 在並堂段取高值（elementary 300 > tutoring 200）。
        // 17:00-18:00 A solo 200 + 18:00-19:00 A+B max=300,(300+50)=350 + 19:00-20:00 B solo 300 = 850
        $this->assertEquals(850, $teacher['total_salary']);
    }

    // ──────────────────────────────────────────
    // Concurrency: three sessions fully overlap 1h (k=3 students)
    // ──────────────────────────────────────────
    public function test_concurrency_bonus_three_sessions_overlap(): void
    {
        $dir = $this->createDirector('dir-cb6@test.com', [1]);
        $tid = $this->createPartTimeTeacher(1, 'ptcb6@test.com', '併堂己');
        $stuA = $this->createStudent(1, '學生K');
        $stuB = $this->createStudent(1, '學生L');
        $stuC = $this->createStudent(1, '學生M');

        $scA = $this->makeStudentClass($stuA, $tid, 10, 'one_on_one');
        $scB = $this->makeStudentClass($stuB, $tid, 10, 'one_on_one');
        $scC = $this->makeStudentClass($stuC, $tid, 10, 'one_on_one');

        $this->makeApprovedLR($scA, $tid, '2026-04-10', '18:00', '19:00');
        $this->makeApprovedLR($scB, $tid, '2026-04-10', '18:00', '19:00');
        $this->makeApprovedLR($scC, $tid, '2026-04-10', '18:00', '19:00');

        $res = $this->withHeaders($this->authHeaders($dir['token']))
            ->getJson('/api/v1/finance/parttime-payroll?month=2026-04&branch_id=1');

        $res->assertOk();
        $teacher = $res->json('teachers.0');
        // v1.4 tie-break: all 3 same start 18:00 (diff=0，容忍度內) 且契約 2h 全重疊
        // A (primary, lowest lr_id): 400*2h + (3-1)*50*2h = 800 + 200 = 1000
        // B, C (non-primary): 400*2h - 400*2h = 0 each
        // total = 1000
        $this->assertEquals(1000, $teacher['total_salary']);
    }

    // ──────────────────────────────────────────
    // No overlap → concurrency bonus = 0, same as before
    // ──────────────────────────────────────────
    public function test_concurrency_bonus_no_overlap_regression(): void
    {
        $dir = $this->createDirector('dir-cb7@test.com', [1]);
        $tid = $this->createPartTimeTeacher(1, 'ptcb7@test.com', '併堂庚');
        $stuA = $this->createStudent(1, '學生N');
        $stuB = $this->createStudent(1, '學生O');

        $scA = $this->makeStudentClass($stuA, $tid, 10, 'one_on_one');
        $scB = $this->makeStudentClass($stuB, $tid, 10, 'one_on_one');

        $this->makeApprovedLR($scA, $tid, '2026-04-10', '14:00', '16:00');
        $this->makeApprovedLR($scB, $tid, '2026-04-10', '17:00', '19:00');

        $res = $this->withHeaders($this->authHeaders($dir['token']))
            ->getJson('/api/v1/finance/parttime-payroll?month=2026-04&branch_id=1');

        $res->assertOk();
        $teacher = $res->json('teachers.0');
        // A: 400*2h = 800, B: 400*2h = 800, no overlap → total = 1600
        $this->assertEquals(1600, $teacher['total_salary']);
    }

    // ──────────────────────────────────────────
    // LR missing StartTime → excluded from concurrency, bonus = 0
    // ──────────────────────────────────────────
    public function test_concurrency_bonus_missing_time_excluded(): void
    {
        $dir = $this->createDirector('dir-cb8@test.com', [1]);
        $tid = $this->createPartTimeTeacher(1, 'ptcb8@test.com', '併堂辛');
        $stuA = $this->createStudent(1, '學生P');
        $stuB = $this->createStudent(1, '學生Q');

        $scA = $this->makeStudentClass($stuA, $tid, 10, 'one_on_one');
        $scB = $this->makeStudentClass($stuB, $tid, 10, 'one_on_one');

        $this->makeApprovedLR($scA, $tid, '2026-04-10', '17:00', '19:00');
        $lrB = $this->makeApprovedLR($scB, $tid, '2026-04-10', '18:00', '20:00');
        $lrB->update(['StartTime' => null, 'EndTime' => null]);

        $res = $this->withHeaders($this->authHeaders($dir['token']))
            ->getJson('/api/v1/finance/parttime-payroll?month=2026-04&branch_id=1');

        $res->assertOk();
        $teacher = $res->json('teachers.0');
        // A: 400*2h = 800, no valid overlap partner → bonus 0
        // B: 400 * fallback 2h = 800, no bonus (excluded)
        // total = 1600
        $this->assertEquals(1600, $teacher['total_salary']);
    }

    // ──────────────────────────────────────────
    // Session detail returns concurrency_bonus_amount field
    // ──────────────────────────────────────────
    public function test_session_detail_includes_concurrency_bonus(): void
    {
        $dir = $this->createDirector('dir-cb9@test.com', [1]);
        $tid = $this->createPartTimeTeacher(1, 'ptcb9@test.com', '併堂壬');
        $stuA = $this->createStudent(1, '學生R');
        $stuB = $this->createStudent(1, '學生S');

        $scA = $this->makeStudentClass($stuA, $tid, 10, 'one_on_one');
        $scB = $this->makeStudentClass($stuB, $tid, 10, 'one_on_one');

        $this->makeApprovedLR($scA, $tid, '2026-04-10', '17:00', '19:00');
        $this->makeApprovedLR($scB, $tid, '2026-04-10', '18:00', '20:00');

        $res = $this->withHeaders($this->authHeaders($dir['token']))
            ->getJson("/api/v1/finance/parttime-payroll/{$tid}/sessions?month=2026-04&branch_id=1");

        $res->assertOk();
        $sessions = $res->json('sessions');
        $this->assertCount(2, $sessions);

        $bonuses = array_column($sessions, 'concurrency_bonus_amount');
        sort($bonuses);
        // PRD-F (2026-04-18)：A baseline 400×2=800，attributed 400(17-18 solo)+450(18-19 並堂)=850，delta=+50。
        //                     B baseline 400×2=800，attributed 400(19-20 solo)=400，delta=-400。
        $this->assertEquals([-400, 50], $bonuses);

        foreach ($sessions as $s) {
            $this->assertArrayHasKey('concurrency_bonus_amount', $s);
        }
    }

    // ──────────────────────────────────────────
    // One-on-two with both students present (2 LR same slot)
    // ──────────────────────────────────────────
    public function test_one_on_two_both_students_present_concurrency(): void
    {
        $dir = $this->createDirector('dir-hp1@test.com', [1]);
        $tid = $this->createPartTimeTeacher(1, 'pthp1@test.com', '兼職HP');
        $stuA = $this->createStudent(1, '一對二A');
        $stuB = $this->createStudent(1, '一對二B');

        $scA = $this->makeStudentClass($stuA, $tid, 7, 'one_on_two');
        $scB = $this->makeStudentClass($stuB, $tid, 7, 'one_on_two');

        $this->makeApprovedLR($scA, $tid, '2026-04-10', '10:00', '12:00');
        $this->makeApprovedLR($scB, $tid, '2026-04-10', '10:00', '12:00');

        $res = $this->withHeaders($this->authHeaders($dir['token']))
            ->getJson('/api/v1/finance/parttime-payroll?month=2026-04&branch_id=1');

        $res->assertOk();
        $teacher = $res->json('teachers.0');
        // v1.4 tie-break: 同 start 10:00 (diff=0，容忍度內) 同層 junior 完全重疊 2h
        // A (primary): 350*2h + (2-1)*50*2h = 700 + 100 = 800
        // B (non-primary): 350*2h - 350*2h = 0
        // total = 800
        $this->assertEquals(800, $teacher['total_salary']);
    }

    // ──────────────────────────────────────────
    // Branch rule headcount_bonus no longer affects calculation
    // ──────────────────────────────────────────
    public function test_branch_rule_headcount_bonus_ignored(): void
    {
        $dir = $this->createDirector('dir-reg1@test.com', [1]);
        $tid = $this->createPartTimeTeacher(1, 'ptreg1@test.com', '兼職REG');
        $stu = $this->createStudent(1, '回歸生');

        DB::table('payroll_branch_rules')->insert([
            'branch_id' => 1,
            'base_rates' => json_encode(['high' => 400, 'junior' => 350, 'elementary' => 300, 'tutoring' => 200]),
            'headcount_bonus' => 200,
            'created_at' => now(),
        ]);

        $sc = $this->makeStudentClass($stu, $tid, 7, 'one_on_two');
        $this->makeApprovedLR($sc, $tid, '2026-04-05', '14:00', '16:00');

        $res = $this->withHeaders($this->authHeaders($dir['token']))
            ->getJson('/api/v1/finance/parttime-payroll?month=2026-04&branch_id=1');

        $res->assertOk();
        $teacher = $res->json('teachers.0');
        // headcount_bonus=200 is ignored; base 350*2h = 700, no concurrency
        $this->assertEquals(700, $teacher['total_salary']);
    }

    // ──────────────────────────────────────────
    // Level dominance: junior beats tutoring, 2h full overlap
    // ──────────────────────────────────────────
    public function test_level_dominance_junior_over_tutoring(): void
    {
        $dir = $this->createDirector('dir-ld1@test.com', [1]);
        $tid = $this->createPartTimeTeacher(1, 'ptld1@test.com', '層級甲');
        $stuA = $this->createStudent(1, '國中層級A');
        $stuB = $this->createStudent(1, '輔導層級B');

        $scA = $this->makeStudentClass($stuA, $tid, 7, 'one_on_two');  // junior, 350/h
        $scB = $this->makeStudentClass($stuB, $tid, 7, 'tutoring');    // tutoring overrides, 200/h

        $this->makeApprovedLR($scA, $tid, '2026-04-15', '17:00', '19:00');
        $this->makeApprovedLR($scB, $tid, '2026-04-15', '17:00', '19:00');

        $res = $this->withHeaders($this->authHeaders($dir['token']))
            ->getJson('/api/v1/finance/parttime-payroll?month=2026-04&branch_id=1');

        $res->assertOk();
        $teacher = $res->json('teachers.0');
        // A (junior, weight=3, dominant): 350*2h=700 + (2-1)*50*2h=100 => 800
        // B (tutoring, weight=1, dominated): 200*2h=400 + (-200*2h)=-400 => max(0,0) = 0
        // total = 800
        $this->assertEquals(800, $teacher['total_salary']);
    }

    // ──────────────────────────────────────────
    // Same level overlap: both earn concurrency bonus
    // ──────────────────────────────────────────
    public function test_same_level_both_earn_concurrency(): void
    {
        $dir = $this->createDirector('dir-sl1@test.com', [1]);
        $tid = $this->createPartTimeTeacher(1, 'ptsl1@test.com', '同層甲');
        $stuA = $this->createStudent(1, '同層A');
        $stuB = $this->createStudent(1, '同層B');

        $scA = $this->makeStudentClass($stuA, $tid, 8, 'one_on_one');  // junior, 350/h
        $scB = $this->makeStudentClass($stuB, $tid, 8, 'one_on_one');  // junior, 350/h

        $this->makeApprovedLR($scA, $tid, '2026-04-08', '14:00', '16:00');
        $this->makeApprovedLR($scB, $tid, '2026-04-08', '14:00', '16:00');

        $res = $this->withHeaders($this->authHeaders($dir['token']))
            ->getJson('/api/v1/finance/parttime-payroll?month=2026-04&branch_id=1');

        $res->assertOk();
        $teacher = $res->json('teachers.0');
        // Same level (junior, weight=3): both dominant
        // Each: 350*2h=700 + (2-1)*50*2h=100 => 800
        // total = 1600
        $this->assertEquals(800, $teacher['total_salary']);
    }

    // ──────────────────────────────────────────
    // Student grade update cascades to StudentClass.GradeID
    // ──────────────────────────────────────────
    public function test_student_grade_update_cascades_to_studentclass(): void
    {
        $dir = $this->createDirector('dir-gc1@test.com', [1]);
        $tid = $this->createPartTimeTeacher(1, 'ptgc1@test.com', '升級甲');
        $stu = $this->createStudent(1, '升級生');

        $sc = $this->makeStudentClass($stu, $tid, 7, 'one_on_one');  // GradeID=7 (J1)
        $this->assertEquals(7, $sc->fresh()->GradeID);

        // Update student grade from J1 to J2
        $res = $this->withHeaders($this->authHeaders($dir['token']))
            ->putJson("/api/v1/students/{$stu->id}", ['grade' => 'J2']);

        $res->assertOk();
        $this->assertEquals(8, $sc->fresh()->GradeID);  // J2 → ClassID=8 cascaded
    }

    // ──────────────────────────────────────────
    // Helpers
    // ──────────────────────────────────────────

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

    private function createSuperAdmin(string $email, array $campusIds): array
    {
        $user = User::create([
            'LoginName' => $email, 'Name' => 'SuperAdmin', 'PSW' => 'secret',
            'type' => 'S', 'phone' => '0911999999', 'MustChangePassword' => false,
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
            'type' => 'T', 'phone' => '0922111111', 'MustChangePassword' => false,
            'employment_type' => 'part_time',
        ]);
        UserCampus::create(['CampusID' => $campusId, 'UserID' => $user->id, 'Admin' => 0, 'Approved' => 1]);
        return (int) $user->id;
    }

    private function createFullTimeTeacher(int $campusId, string $email, string $name): int
    {
        $user = User::create([
            'LoginName' => $email, 'Name' => $name, 'PSW' => 'secret',
            'type' => 'T', 'phone' => '0922222222', 'MustChangePassword' => false,
            'employment_type' => 'full_time',
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
        return $this->makeLR($sc, $teacherId, $date, $start, $end, 'approved');
    }

    private function makeLR(StudentClass $sc, int $teacherId, string $date, string $start, string $end, string $status): LearningRecord
    {
        $cs = ClassSession::create([
            'StudentClassID' => $sc->ID, 'SessionDate' => $date,
            'StartTime' => "{$start}:00", 'EndTime' => "{$end}:00",
            'Status' => 'completed', 'Note' => '',
        ]);
        return LearningRecord::create([
            'StudentClassID' => $sc->ID, 'ClassSessionID' => $cs->id,
            'TeacherID' => $teacherId, 'Content' => 'test', 'Subject' => 'Math',
            'Status' => $status, 'SessionDate' => $date,
            'StartTime' => "{$start}:00", 'EndTime' => "{$end}:00",
            'SessionDeducted' => $status === 'approved',
            'ApprovedBy' => $status === 'approved' ? $teacherId : null,
            'ApprovedAt' => $status === 'approved' ? now() : null,
        ]);
    }

    private function authHeaders(string $token): array
    {
        return ['Authorization' => "Bearer {$token}", 'Accept' => 'application/json'];
    }
}
