<?php

namespace Tests\Feature;

use App\Models\AuthToken;
use App\Models\ClassSession;
use App\Models\LearningRecord;
use App\Models\PayrollBranchRule;
use App\Models\PayrollMonthStatus;
use App\Models\Student;
use App\Models\StudentClass;
use App\Models\User;
use App\Models\UserCampus;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class PayrollRulesTest extends TestCase
{
    use RefreshDatabase;

    public function test_get_rules_returns_current_branch_rule(): void
    {
        $dir = $this->createDirector('dir-r1@test.com', [1]);
        PayrollBranchRule::create([
            'branch_id' => 1, 'base_rates' => ['high' => 500, 'junior' => 400, 'elementary' => 350, 'tutoring' => 250],
            'headcount_bonus' => 60, 'created_by' => $dir['user_id'], 'created_at' => now(),
        ]);

        $res = $this->withHeaders($this->authHeaders($dir['token']))
            ->getJson('/api/v1/finance/parttime-payroll/rules?branch_id=1');

        $res->assertOk();
        $this->assertEquals(500, $res->json('base_rates.high'));
        $this->assertEquals(60, $res->json('headcount_bonus'));
        $this->assertNotNull($res->json('defaults'));
    }

    public function test_get_rules_cross_campus_403(): void
    {
        $dir = $this->createDirector('dir-r2@test.com', [1]);

        $res = $this->withHeaders($this->authHeaders($dir['token']))
            ->getJson('/api/v1/finance/parttime-payroll/rules?branch_id=2');

        $res->assertStatus(403);
    }

    public function test_put_rules_creates_new_version(): void
    {
        $dir = $this->createDirector('dir-r3@test.com', [1]);
        PayrollBranchRule::create([
            'branch_id' => 1, 'base_rates' => ['high' => 400, 'junior' => 350, 'elementary' => 300, 'tutoring' => 200],
            'headcount_bonus' => 50, 'created_at' => now(),
        ]);

        $res = $this->withHeaders($this->authHeaders($dir['token']))
            ->putJson('/api/v1/finance/parttime-payroll/rules', [
                'branch_id' => 1,
                'base_rates' => ['high' => 500, 'junior' => 450, 'elementary' => 350, 'tutoring' => 250],
                'headcount_bonus' => 75,
            ]);

        $res->assertOk();
        $this->assertTrue($res->json('ok'));
        $this->assertEquals(500, $res->json('base_rates.high'));
        $this->assertEquals(75, $res->json('headcount_bonus'));

        $rules = PayrollBranchRule::where('branch_id', 1)->orderByDesc('id')->get();
        $this->assertGreaterThanOrEqual(2, $rules->count());
        $this->assertEquals(500, $rules->first()->base_rates['high']);
    }

    public function test_put_rules_cross_campus_403(): void
    {
        $dir = $this->createDirector('dir-r4@test.com', [1]);

        $res = $this->withHeaders($this->authHeaders($dir['token']))
            ->putJson('/api/v1/finance/parttime-payroll/rules', [
                'branch_id' => 2,
                'base_rates' => ['high' => 500, 'junior' => 400, 'elementary' => 300, 'tutoring' => 200],
                'headcount_bonus' => 50,
            ]);

        $res->assertStatus(403);
    }

    public function test_put_rules_validation_422(): void
    {
        $dir = $this->createDirector('dir-r5@test.com', [1]);

        $res = $this->withHeaders($this->authHeaders($dir['token']))
            ->putJson('/api/v1/finance/parttime-payroll/rules', [
                'branch_id' => 1,
                'base_rates' => ['high' => 50, 'junior' => 350, 'elementary' => 300, 'tutoring' => 200],
                'headcount_bonus' => 50,
            ]);

        $res->assertStatus(422);
        $this->assertStringContainsString('high', $res->json('error'));
    }

    public function test_put_rules_writes_audit_log(): void
    {
        $dir = $this->createDirector('dir-r6@test.com', [1]);

        $this->withHeaders($this->authHeaders($dir['token']))
            ->putJson('/api/v1/finance/parttime-payroll/rules', [
                'branch_id' => 1,
                'base_rates' => ['high' => 450, 'junior' => 400, 'elementary' => 350, 'tutoring' => 250],
                'headcount_bonus' => 60,
            ])
            ->assertOk();

        $log = DB::table('payroll_audit_log')
            ->where('branch_id', 1)
            ->where('action', 'rule_update')
            ->orderByDesc('id')
            ->first();

        $this->assertNotNull($log);
        $this->assertEquals($dir['user_id'], $log->user_id);
    }

    public function test_payroll_uses_db_rules_after_update(): void
    {
        $dir = $this->createDirector('dir-r7@test.com', [1]);
        $tid = $this->createPartTimeTeacher(1, 'pt-r7@test.com', '兼職A');
        $stu = $this->createStudent(1, '學生A');
        $sc  = $this->makeStudentClass($stu, $tid, 10, 'one_on_one');
        $this->makeApprovedLR($sc, $tid, '2026-04-01', '14:00', '16:00');

        PayrollBranchRule::create([
            'branch_id' => 1, 'base_rates' => ['high' => 600, 'junior' => 500, 'elementary' => 400, 'tutoring' => 300],
            'headcount_bonus' => 100, 'created_at' => now(),
        ]);

        $res = $this->withHeaders($this->authHeaders($dir['token']))
            ->getJson('/api/v1/finance/parttime-payroll?month=2026-04&branch_id=1');

        $res->assertOk();
        $teacher = $res->json('teachers.0');
        $this->assertEquals(1200, $teacher['total_salary']); // 600/h * 2h
    }

    public function test_locked_month_uses_snapshot_rule(): void
    {
        $dir = $this->createDirector('dir-r8@test.com', [1]);
        $tid = $this->createPartTimeTeacher(1, 'pt-r8@test.com', '兼職B');
        $stu = $this->createStudent(1, '學生B');
        $sc  = $this->makeStudentClass($stu, $tid, 10, 'one_on_one');
        $this->makeApprovedLR($sc, $tid, '2026-04-01', '14:00', '16:00');

        $oldRule = PayrollBranchRule::create([
            'branch_id' => 1, 'base_rates' => ['high' => 400, 'junior' => 350, 'elementary' => 300, 'tutoring' => 200],
            'headcount_bonus' => 50, 'created_at' => now(),
        ]);

        $this->withHeaders($this->authHeaders($dir['token']))
            ->postJson('/api/v1/finance/parttime-payroll/lock', ['month' => '2026-04', 'branch_id' => 1])
            ->assertOk();

        $lockRow = PayrollMonthStatus::where('branch_id', 1)->where('month', '2026-04')->first();
        $this->assertEquals($oldRule->id, $lockRow->rule_version_id);

        PayrollBranchRule::create([
            'branch_id' => 1, 'base_rates' => ['high' => 800, 'junior' => 700, 'elementary' => 600, 'tutoring' => 500],
            'headcount_bonus' => 200, 'created_at' => now(),
        ]);

        $res = $this->withHeaders($this->authHeaders($dir['token']))
            ->getJson('/api/v1/finance/parttime-payroll?month=2026-04&branch_id=1');

        $res->assertOk();
        $teacher = $res->json('teachers.0');
        $this->assertEquals(800, $teacher['total_salary']); // Still old rule: 400/h * 2h
    }

    public function test_reopen_clears_snapshot_uses_latest(): void
    {
        $sa = $this->createSuperAdmin('sa-r9@test.com', [1]);
        $tid = $this->createPartTimeTeacher(1, 'pt-r9@test.com', '兼職C');
        $stu = $this->createStudent(1, '學生C');
        $sc  = $this->makeStudentClass($stu, $tid, 10, 'one_on_one');
        $this->makeApprovedLR($sc, $tid, '2026-04-01', '14:00', '16:00');

        PayrollBranchRule::create([
            'branch_id' => 1, 'base_rates' => ['high' => 400, 'junior' => 350, 'elementary' => 300, 'tutoring' => 200],
            'headcount_bonus' => 50, 'created_at' => now(),
        ]);

        $this->withHeaders($this->authHeaders($sa['token']))
            ->postJson('/api/v1/finance/parttime-payroll/lock', ['month' => '2026-04', 'branch_id' => 1])
            ->assertOk();

        PayrollBranchRule::create([
            'branch_id' => 1, 'base_rates' => ['high' => 600, 'junior' => 500, 'elementary' => 400, 'tutoring' => 300],
            'headcount_bonus' => 100, 'created_at' => now(),
        ]);

        $this->withHeaders($this->authHeaders($sa['token']))
            ->postJson('/api/v1/finance/parttime-payroll/reopen', ['month' => '2026-04', 'branch_id' => 1, 'reason' => 'test'])
            ->assertOk();

        $lockRow = PayrollMonthStatus::where('branch_id', 1)->where('month', '2026-04')->first();
        $this->assertNull($lockRow->rule_version_id);

        $res = $this->withHeaders($this->authHeaders($sa['token']))
            ->getJson('/api/v1/finance/parttime-payroll?month=2026-04&branch_id=1');

        $res->assertOk();
        $teacher = $res->json('teachers.0');
        $this->assertEquals(1200, $teacher['total_salary']); // New rule: 600/h * 2h
    }

    public function test_rules_append_only_old_version_preserved(): void
    {
        $dir = $this->createDirector('dir-r10@test.com', [1]);

        $this->withHeaders($this->authHeaders($dir['token']))
            ->putJson('/api/v1/finance/parttime-payroll/rules', [
                'branch_id' => 1,
                'base_rates' => ['high' => 450, 'junior' => 400, 'elementary' => 350, 'tutoring' => 250],
                'headcount_bonus' => 60,
            ])->assertOk();

        $v1Id = PayrollBranchRule::where('branch_id', 1)->orderByDesc('id')->skip(1)->first()?->id
            ?? PayrollBranchRule::where('branch_id', 1)->orderBy('id')->first()?->id;

        $this->withHeaders($this->authHeaders($dir['token']))
            ->putJson('/api/v1/finance/parttime-payroll/rules', [
                'branch_id' => 1,
                'base_rates' => ['high' => 500, 'junior' => 450, 'elementary' => 400, 'tutoring' => 300],
                'headcount_bonus' => 80,
            ])->assertOk();

        if ($v1Id) {
            $old = PayrollBranchRule::find($v1Id);
            $this->assertNotNull($old);
        }

        $all = PayrollBranchRule::where('branch_id', 1)->get();
        $this->assertGreaterThanOrEqual(2, $all->count());
    }

    // ── Helpers ──────────────────────────────────

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
            'SessionDeducted' => true,
            'ApprovedBy' => $teacherId, 'ApprovedAt' => now(),
        ]);
    }

    private function authHeaders(string $token): array
    {
        return ['Authorization' => "Bearer {$token}", 'Accept' => 'application/json'];
    }
}
