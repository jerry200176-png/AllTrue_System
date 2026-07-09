<?php

namespace Tests\Feature;

use App\Models\AuthToken;
use App\Models\ClassSession;
use App\Models\LearningRecord;
use App\Models\PayrollBranchRule;
use App\Models\PayrollMonthStatus;
use App\Models\PayrollTeacherBranchRule;
use App\Models\Student;
use App\Models\StudentClass;
use App\Models\User;
use App\Models\UserCampus;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class PayrollTeacherOverrideTest extends TestCase
{
    use RefreshDatabase;

    // ── TC-OVR-01: Override only affects target teacher ──

    public function test_override_only_affects_target_teacher(): void
    {
        $dir = $this->createDirector('dir-ovr1@test.com', [1]);
        $tidA = $this->createPartTimeTeacher(1, 'ptA@test.com', '老師A');
        $tidB = $this->createPartTimeTeacher(1, 'ptB@test.com', '老師B');
        $stu1 = $this->createStudent(1, '學生1');
        $stu2 = $this->createStudent(1, '學生2');

        PayrollBranchRule::create([
            'branch_id' => 1, 'base_rates' => ['high' => 400, 'junior' => 350, 'elementary' => 300, 'tutoring' => 200],
            'headcount_bonus' => 50, 'created_at' => now(),
        ]);

        $scA = $this->makeStudentClass($stu1, $tidA, 10, 'one_on_one');
        $scB = $this->makeStudentClass($stu2, $tidB, 10, 'one_on_one');
        $this->makeApprovedLR($scA, $tidA, '2026-04-01', '14:00', '16:00');
        $this->makeApprovedLR($scB, $tidB, '2026-04-02', '14:00', '16:00');

        PayrollTeacherBranchRule::create([
            'teacher_user_id' => $tidA, 'branch_id' => 1,
            'base_rates' => ['high' => 600, 'junior' => 500, 'elementary' => 400, 'tutoring' => 300],
            'headcount_bonus' => 100, 'created_at' => now(),
        ]);

        $res = $this->withHeaders($this->authHeaders($dir['token']))
            ->getJson('/api/v1/finance/parttime-payroll?month=2026-04&branch_id=1');

        $res->assertOk();
        $teachers = collect($res->json('teachers'));

        $a = $teachers->firstWhere('teacher_id', $tidA);
        $b = $teachers->firstWhere('teacher_id', $tidB);

        $this->assertEquals(1200, $a['total_salary']); // 600/h * 2h
        $this->assertEquals('teacher_override', $a['rule_source']);
        $this->assertEquals(800, $b['total_salary']);   // 400/h * 2h (branch default)
        $this->assertEquals('branch_default', $b['rule_source']);
    }

    // ── TC-OVR-02: Cross-branch isolation ──

    public function test_cross_branch_override_isolation(): void
    {
        $sa = $this->createSuperAdmin('sa-ovr2@test.com', [1, 2]);
        $tid = $this->createPartTimeTeacher(1, 'ptX@test.com', '跨校老師');
        UserCampus::create(['CampusID' => 2, 'UserID' => $tid, 'Admin' => 0, 'Approved' => 1]);

        $stu1 = $this->createStudent(1, '分校1學生');
        $stu2 = $this->createStudent(2, '分校2學生');

        PayrollBranchRule::create([
            'branch_id' => 1, 'base_rates' => ['high' => 400, 'junior' => 350, 'elementary' => 300, 'tutoring' => 200],
            'headcount_bonus' => 50, 'created_at' => now(),
        ]);
        PayrollBranchRule::create([
            'branch_id' => 2, 'base_rates' => ['high' => 400, 'junior' => 350, 'elementary' => 300, 'tutoring' => 200],
            'headcount_bonus' => 50, 'created_at' => now(),
        ]);

        $sc1 = $this->makeStudentClass($stu1, $tid, 10, 'one_on_one');
        $sc2 = $this->makeStudentClass($stu2, $tid, 10, 'one_on_one');
        $this->makeApprovedLR($sc1, $tid, '2026-04-01', '10:00', '12:00');
        $this->makeApprovedLR($sc2, $tid, '2026-04-01', '14:00', '16:00');

        PayrollTeacherBranchRule::create([
            'teacher_user_id' => $tid, 'branch_id' => 1,
            'base_rates' => ['high' => 700, 'junior' => 600, 'elementary' => 500, 'tutoring' => 400],
            'headcount_bonus' => 80, 'created_at' => now(),
        ]);

        $r1 = $this->withHeaders($this->authHeaders($sa['token']))
            ->getJson('/api/v1/finance/parttime-payroll?month=2026-04&branch_id=1');
        $r2 = $this->withHeaders($this->authHeaders($sa['token']))
            ->getJson('/api/v1/finance/parttime-payroll?month=2026-04&branch_id=2');

        $this->assertEquals(1400, $r1->json('teachers.0.total_salary')); // 700/h * 2h
        $this->assertEquals(800, $r2->json('teachers.0.total_salary'));  // 400/h * 2h (branch default)
    }

    // ── TC-OVR-03: Branch rule change doesn't affect overridden teacher ──

    public function test_branch_rule_change_does_not_affect_overridden(): void
    {
        $dir = $this->createDirector('dir-ovr3@test.com', [1]);
        $tidA = $this->createPartTimeTeacher(1, 'ptA3@test.com', '覆寫老師');
        $tidB = $this->createPartTimeTeacher(1, 'ptB3@test.com', '預設老師');
        $stu1 = $this->createStudent(1, '學生A3');
        $stu2 = $this->createStudent(1, '學生B3');

        PayrollBranchRule::create([
            'branch_id' => 1, 'base_rates' => ['high' => 400, 'junior' => 350, 'elementary' => 300, 'tutoring' => 200],
            'headcount_bonus' => 50, 'created_at' => now(),
        ]);

        $scA = $this->makeStudentClass($stu1, $tidA, 10, 'one_on_one');
        $scB = $this->makeStudentClass($stu2, $tidB, 10, 'one_on_one');
        $this->makeApprovedLR($scA, $tidA, '2026-04-01', '14:00', '16:00');
        $this->makeApprovedLR($scB, $tidB, '2026-04-02', '14:00', '16:00');

        PayrollTeacherBranchRule::create([
            'teacher_user_id' => $tidA, 'branch_id' => 1,
            'base_rates' => ['high' => 500, 'junior' => 450, 'elementary' => 400, 'tutoring' => 300],
            'headcount_bonus' => 60, 'created_at' => now(),
        ]);

        PayrollBranchRule::create([
            'branch_id' => 1, 'base_rates' => ['high' => 800, 'junior' => 700, 'elementary' => 600, 'tutoring' => 500],
            'headcount_bonus' => 200, 'created_at' => now(),
        ]);

        $res = $this->withHeaders($this->authHeaders($dir['token']))
            ->getJson('/api/v1/finance/parttime-payroll?month=2026-04&branch_id=1');

        $teachers = collect($res->json('teachers'));
        $a = $teachers->firstWhere('teacher_id', $tidA);
        $b = $teachers->firstWhere('teacher_id', $tidB);

        $this->assertEquals(1000, $a['total_salary']); // 500/h * 2h (teacher override unchanged)
        $this->assertEquals(1600, $b['total_salary']); // 800/h * 2h (new branch rule)
    }

    // ── TC-LOCK-01: Lock snapshots teacher rules ──

    public function test_lock_snapshots_teacher_rules(): void
    {
        $dir = $this->createDirector('dir-lock1@test.com', [1]);
        $tid = $this->createPartTimeTeacher(1, 'ptL1@test.com', '鎖帳老師');
        $stu = $this->createStudent(1, '學生L1');

        PayrollBranchRule::create([
            'branch_id' => 1, 'base_rates' => ['high' => 400, 'junior' => 350, 'elementary' => 300, 'tutoring' => 200],
            'headcount_bonus' => 50, 'created_at' => now(),
        ]);

        $sc = $this->makeStudentClass($stu, $tid, 10, 'one_on_one');
        $this->makeApprovedLR($sc, $tid, '2026-04-01', '14:00', '16:00');

        $overrideRule = PayrollTeacherBranchRule::create([
            'teacher_user_id' => $tid, 'branch_id' => 1,
            'base_rates' => ['high' => 500, 'junior' => 400, 'elementary' => 350, 'tutoring' => 250],
            'headcount_bonus' => 60, 'created_at' => now(),
        ]);

        $this->withHeaders($this->authHeaders($dir['token']))
            ->postJson('/api/v1/finance/parttime-payroll/lock', ['month' => '2026-04', 'branch_id' => 1])
            ->assertOk();

        $lockRow = PayrollMonthStatus::where('branch_id', 1)->where('month', '2026-04')->first();
        $this->assertNotNull($lockRow->teacher_rule_snapshots);
        $snapshots = $lockRow->teacher_rule_snapshots;
        $this->assertEquals($overrideRule->id, $snapshots[(string) $tid]);

        PayrollTeacherBranchRule::create([
            'teacher_user_id' => $tid, 'branch_id' => 1,
            'base_rates' => ['high' => 900, 'junior' => 800, 'elementary' => 700, 'tutoring' => 600],
            'headcount_bonus' => 200, 'created_at' => now(),
        ]);

        $res = $this->withHeaders($this->authHeaders($dir['token']))
            ->getJson('/api/v1/finance/parttime-payroll?month=2026-04&branch_id=1');

        $this->assertEquals(1000, $res->json('teachers.0.total_salary')); // Still old override: 500/h * 2h
    }

    // ── TC-LOCK-02: Reopen clears teacher snapshots ──

    public function test_reopen_clears_teacher_snapshots(): void
    {
        $sa = $this->createSuperAdmin('sa-lock2@test.com', [1]);
        $tid = $this->createPartTimeTeacher(1, 'ptL2@test.com', '重開老師');
        $stu = $this->createStudent(1, '學生L2');

        PayrollBranchRule::create([
            'branch_id' => 1, 'base_rates' => ['high' => 400, 'junior' => 350, 'elementary' => 300, 'tutoring' => 200],
            'headcount_bonus' => 50, 'created_at' => now(),
        ]);

        $sc = $this->makeStudentClass($stu, $tid, 10, 'one_on_one');
        $this->makeApprovedLR($sc, $tid, '2026-04-01', '14:00', '16:00');

        PayrollTeacherBranchRule::create([
            'teacher_user_id' => $tid, 'branch_id' => 1,
            'base_rates' => ['high' => 500, 'junior' => 400, 'elementary' => 350, 'tutoring' => 250],
            'headcount_bonus' => 60, 'created_at' => now(),
        ]);

        $this->withHeaders($this->authHeaders($sa['token']))
            ->postJson('/api/v1/finance/parttime-payroll/lock', ['month' => '2026-04', 'branch_id' => 1])
            ->assertOk();

        PayrollTeacherBranchRule::create([
            'teacher_user_id' => $tid, 'branch_id' => 1,
            'base_rates' => ['high' => 700, 'junior' => 600, 'elementary' => 500, 'tutoring' => 400],
            'headcount_bonus' => 80, 'created_at' => now(),
        ]);

        $this->withHeaders($this->authHeaders($sa['token']))
            ->postJson('/api/v1/finance/parttime-payroll/reopen', ['month' => '2026-04', 'branch_id' => 1, 'reason' => 'test'])
            ->assertOk();

        $lockRow = PayrollMonthStatus::where('branch_id', 1)->where('month', '2026-04')->first();
        $this->assertNull($lockRow->teacher_rule_snapshots);

        $res = $this->withHeaders($this->authHeaders($sa['token']))
            ->getJson('/api/v1/finance/parttime-payroll?month=2026-04&branch_id=1');

        $this->assertEquals(1400, $res->json('teachers.0.total_salary')); // New override: 700/h * 2h
    }

    // ── TC-REV-01: Revert to branch default ──

    public function test_revert_teacher_override(): void
    {
        $dir = $this->createDirector('dir-rev1@test.com', [1]);
        $tid = $this->createPartTimeTeacher(1, 'ptR1@test.com', '恢復老師');
        $stu = $this->createStudent(1, '學生R1');

        PayrollBranchRule::create([
            'branch_id' => 1, 'base_rates' => ['high' => 400, 'junior' => 350, 'elementary' => 300, 'tutoring' => 200],
            'headcount_bonus' => 50, 'created_at' => now(),
        ]);

        $sc = $this->makeStudentClass($stu, $tid, 10, 'one_on_one');
        $this->makeApprovedLR($sc, $tid, '2026-04-01', '14:00', '16:00');

        PayrollTeacherBranchRule::create([
            'teacher_user_id' => $tid, 'branch_id' => 1,
            'base_rates' => ['high' => 600, 'junior' => 500, 'elementary' => 400, 'tutoring' => 300],
            'headcount_bonus' => 100, 'created_at' => now(),
        ]);

        $res1 = $this->withHeaders($this->authHeaders($dir['token']))
            ->getJson('/api/v1/finance/parttime-payroll?month=2026-04&branch_id=1');
        $this->assertEquals(1200, $res1->json('teachers.0.total_salary')); // 600 * 2

        $this->withHeaders($this->authHeaders($dir['token']))
            ->deleteJson('/api/v1/finance/parttime-payroll/teacher-rules?branch_id=1&teacher_id=' . $tid)
            ->assertOk();

        $this->assertDatabaseHas('payroll_audit_log', ['branch_id' => 1, 'action' => 'teacher_rule_revert']);

        $res2 = $this->withHeaders($this->authHeaders($dir['token']))
            ->getJson('/api/v1/finance/parttime-payroll?month=2026-04&branch_id=1');
        $this->assertEquals(800, $res2->json('teachers.0.total_salary')); // branch default 400 * 2
        $this->assertEquals('branch_default', $res2->json('teachers.0.rule_source'));
    }

    // ── TC-403-01: Cross-campus PUT forbidden ──

    public function test_put_teacher_rules_cross_campus_403(): void
    {
        $dir = $this->createDirector('dir-403@test.com', [1]);
        $tid = $this->createPartTimeTeacher(2, 'pt403@test.com', '他校老師');

        $res = $this->withHeaders($this->authHeaders($dir['token']))
            ->putJson('/api/v1/finance/parttime-payroll/teacher-rules', [
                'branch_id' => 2, 'teacher_id' => $tid,
                'base_rates' => ['high' => 500, 'junior' => 400, 'elementary' => 300, 'tutoring' => 200],
                'headcount_bonus' => 50,
            ]);

        $res->assertStatus(403);
    }

    // ── TC-422-01: Validation errors ──

    public function test_put_teacher_rules_validation_422(): void
    {
        $dir = $this->createDirector('dir-422@test.com', [1]);
        $tid = $this->createPartTimeTeacher(1, 'pt422@test.com', '驗證老師');

        $res = $this->withHeaders($this->authHeaders($dir['token']))
            ->putJson('/api/v1/finance/parttime-payroll/teacher-rules', [
                'branch_id' => 1, 'teacher_id' => $tid,
                'base_rates' => ['high' => 50, 'junior' => 350, 'elementary' => 300, 'tutoring' => 200],
                'headcount_bonus' => 50,
            ]);

        $res->assertStatus(422);
        $this->assertStringContainsString('high', $res->json('error'));
    }

    // ── Non part-time teacher rejected ──

    public function test_put_teacher_rules_rejects_fulltime(): void
    {
        $dir = $this->createDirector('dir-ft@test.com', [1]);
        $ftId = $this->createFullTimeTeacher(1, 'ft-rej@test.com', '正職老師');

        $res = $this->withHeaders($this->authHeaders($dir['token']))
            ->putJson('/api/v1/finance/parttime-payroll/teacher-rules', [
                'branch_id' => 1, 'teacher_id' => $ftId,
                'base_rates' => ['high' => 500, 'junior' => 400, 'elementary' => 300, 'tutoring' => 200],
                'headcount_bonus' => 50,
            ]);

        $res->assertStatus(422);
        $this->assertStringContainsString('part_time', $res->json('error'));
    }

    // ── TC-CSV-01: Export includes rule_source ──

    public function test_export_includes_rule_source_column(): void
    {
        $dir = $this->createDirector('dir-csv@test.com', [1]);
        $tid = $this->createPartTimeTeacher(1, 'ptCSV@test.com', 'CSV老師');
        $stu = $this->createStudent(1, '學生CSV');

        PayrollBranchRule::create([
            'branch_id' => 1, 'base_rates' => ['high' => 400, 'junior' => 350, 'elementary' => 300, 'tutoring' => 200],
            'headcount_bonus' => 50, 'created_at' => now(),
        ]);

        $sc = $this->makeStudentClass($stu, $tid, 10, 'one_on_one');
        $this->makeApprovedLR($sc, $tid, '2026-04-01', '14:00', '16:00');

        PayrollTeacherBranchRule::create([
            'teacher_user_id' => $tid, 'branch_id' => 1,
            'base_rates' => ['high' => 600, 'junior' => 500, 'elementary' => 400, 'tutoring' => 300],
            'headcount_bonus' => 100, 'created_at' => now(),
        ]);

        $res = $this->withHeaders($this->authHeaders($dir['token']))
            ->get('/api/v1/finance/parttime-payroll/export?month=2026-04&branch_id=1');

        $res->assertOk();
        $content = $res->streamedContent();
        $this->assertStringContainsString('費率來源', $content);
        $this->assertStringContainsString('個別費率', $content);
    }

    // ── Sessions endpoint includes rule_source ──

    public function test_sessions_include_rule_source(): void
    {
        $dir = $this->createDirector('dir-sess@test.com', [1]);
        $tid = $this->createPartTimeTeacher(1, 'ptSess@test.com', '明細老師');
        $stu = $this->createStudent(1, '學生Sess');

        PayrollBranchRule::create([
            'branch_id' => 1, 'base_rates' => ['high' => 400, 'junior' => 350, 'elementary' => 300, 'tutoring' => 200],
            'headcount_bonus' => 50, 'created_at' => now(),
        ]);

        $sc = $this->makeStudentClass($stu, $tid, 10, 'one_on_one');
        $this->makeApprovedLR($sc, $tid, '2026-04-01', '14:00', '16:00');

        PayrollTeacherBranchRule::create([
            'teacher_user_id' => $tid, 'branch_id' => 1,
            'base_rates' => ['high' => 600, 'junior' => 500, 'elementary' => 400, 'tutoring' => 300],
            'headcount_bonus' => 100, 'created_at' => now(),
        ]);

        $res = $this->withHeaders($this->authHeaders($dir['token']))
            ->getJson("/api/v1/finance/parttime-payroll/{$tid}/sessions?month=2026-04&branch_id=1");

        $res->assertOk();
        $this->assertEquals('teacher_override', $res->json('sessions.0.rule_source'));
        $this->assertEquals(1200, $res->json('sessions.0.session_salary')); // 600 * 2h
    }

    // ── GET teacher-rules returns override details ──

    public function test_get_teacher_rules(): void
    {
        $dir = $this->createDirector('dir-gtr@test.com', [1]);
        $tid = $this->createPartTimeTeacher(1, 'ptGTR@test.com', '查詢老師');

        PayrollBranchRule::create([
            'branch_id' => 1, 'base_rates' => ['high' => 400, 'junior' => 350, 'elementary' => 300, 'tutoring' => 200],
            'headcount_bonus' => 50, 'created_at' => now(),
        ]);

        $this->withHeaders($this->authHeaders($dir['token']))
            ->getJson("/api/v1/finance/parttime-payroll/teacher-rules?branch_id=1&teacher_id={$tid}")
            ->assertOk()
            ->assertJson(['has_override' => false]);

        PayrollTeacherBranchRule::create([
            'teacher_user_id' => $tid, 'branch_id' => 1,
            'base_rates' => ['high' => 550, 'junior' => 450, 'elementary' => 350, 'tutoring' => 250],
            'headcount_bonus' => 70, 'created_at' => now(),
        ]);

        $res = $this->withHeaders($this->authHeaders($dir['token']))
            ->getJson("/api/v1/finance/parttime-payroll/teacher-rules?branch_id=1&teacher_id={$tid}");

        $res->assertOk();
        $this->assertTrue($res->json('has_override'));
        $this->assertEquals(550, $res->json('base_rates.high'));
        $this->assertEquals(70, $res->json('headcount_bonus'));
    }

    // ── PUT teacher-rules creates new version + audit log ──

    public function test_put_teacher_rules_creates_version_and_audit(): void
    {
        $dir = $this->createDirector('dir-putv@test.com', [1]);
        $tid = $this->createPartTimeTeacher(1, 'ptPUT@test.com', '版本老師');

        $res = $this->withHeaders($this->authHeaders($dir['token']))
            ->putJson('/api/v1/finance/parttime-payroll/teacher-rules', [
                'branch_id' => 1, 'teacher_id' => $tid,
                'base_rates' => ['high' => 500, 'junior' => 400, 'elementary' => 350, 'tutoring' => 250],
                'headcount_bonus' => 60,
            ]);

        $res->assertOk();
        $this->assertTrue($res->json('ok'));
        $this->assertEquals(500, $res->json('base_rates.high'));

        $this->assertDatabaseHas('payroll_audit_log', ['branch_id' => 1, 'action' => 'teacher_rule_update']);
    }

    // ── TC-REG-01: No override = same as before ──

    public function test_no_override_identical_to_baseline(): void
    {
        $dir = $this->createDirector('dir-reg1@test.com', [1]);
        $tid = $this->createPartTimeTeacher(1, 'ptREG@test.com', '基線老師');
        $stu = $this->createStudent(1, '學生REG');

        PayrollBranchRule::create([
            'branch_id' => 1, 'base_rates' => ['high' => 400, 'junior' => 350, 'elementary' => 300, 'tutoring' => 200],
            'headcount_bonus' => 50, 'created_at' => now(),
        ]);

        $sc = $this->makeStudentClass($stu, $tid, 10, 'one_on_one');
        $this->makeApprovedLR($sc, $tid, '2026-04-01', '14:00', '16:00');

        $res = $this->withHeaders($this->authHeaders($dir['token']))
            ->getJson('/api/v1/finance/parttime-payroll?month=2026-04&branch_id=1');

        $res->assertOk();
        $this->assertEquals(800, $res->json('teachers.0.total_salary'));
        $this->assertEquals('branch_default', $res->json('teachers.0.rule_source'));
    }

    // ── Helpers ──

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
            'SessionDeducted' => true,
            'ApprovedBy' => $teacherId, 'ApprovedAt' => now(),
        ]);
    }

    private function authHeaders(string $token): array
    {
        return ['Authorization' => "Bearer {$token}", 'Accept' => 'application/json'];
    }
}
