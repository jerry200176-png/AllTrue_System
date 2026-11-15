<?php

namespace Tests\Feature;

use App\Models\AuthToken;
use App\Models\User;
use App\Models\UserCampus;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class FulltimePayrollLockTest extends TestCase
{
    use RefreshDatabase;

    public function test_empty_month_can_lock_and_blocks_salary_writes(): void
    {
        $director = $this->createDirector(1, 'ft-lock-dir@test.com');
        $headers = ['Authorization' => "Bearer {$director['token']}", 'Accept' => 'application/json'];

        $this->withHeaders($headers)->postJson('/api/v1/finance/teacher-eligibility/lock', [
            'month' => '2026-08',
            'branch_id' => 1,
        ])->assertOk()->assertJsonPath('status', 'locked');

        $this->withHeaders($headers)->getJson('/api/v1/finance/teacher-eligibility?period=month&start=2026-08-01&end=2026-08-31&branch_id=1')
            ->assertOk()
            ->assertJsonPath('lock.status', 'locked');

        $teacherId = $this->createTeacher(1, 'ft-lock-teacher@test.com');
        // A locked month takes no new input that would change its numbers.
        $this->withHeaders($headers)->postJson('/api/v1/finance/teacher-eligibility/admin-allowances', [
            'teacher_id' => $teacherId, 'branch_id' => 1, 'role_key' => 'head_tutor', 'rate' => 5,
            'reason' => '鎖定後補登', 'starts_on' => '2026-08-01',
        ])->assertStatus(422);
        $this->assertDatabaseMissing('teacher_payroll_admin_allowances', ['teacher_id' => $teacherId]);
    }

    public function test_only_super_admin_can_reopen(): void
    {
        $director = $this->createDirector(1, 'ft-reopen-dir@test.com');
        $admin = $this->createSuperAdmin('ft-reopen-admin@test.com');
        $dirHeaders = ['Authorization' => "Bearer {$director['token']}", 'Accept' => 'application/json'];

        $this->withHeaders($dirHeaders)->postJson('/api/v1/finance/teacher-eligibility/lock', [
            'month' => '2026-08',
            'branch_id' => 1,
        ])->assertOk();

        $this->withHeaders($dirHeaders)->postJson('/api/v1/finance/teacher-eligibility/reopen', [
            'month' => '2026-08',
            'branch_id' => 1,
            'reason' => '更正科目數',
        ])->assertStatus(403);

        $this->withHeaders([
            'Authorization' => "Bearer {$admin['token']}",
            'Accept' => 'application/json',
        ])->postJson('/api/v1/finance/teacher-eligibility/reopen', [
            'month' => '2026-08',
            'branch_id' => 1,
            'reason' => '更正科目數',
        ])->assertOk()->assertJsonPath('status', 'reopened');
    }

    public function test_cannot_lock_when_any_teacher_is_still_review(): void
    {
        $director = $this->createDirector(1, 'ft-review-dir@test.com');
        $teacherId = $this->createTeacher(1, 'ft-review-teacher@test.com');
        // An achievement still waiting for verification leaves the teacher in review (base salary no longer does).
        \Illuminate\Support\Facades\DB::table('teacher_payroll_achievements')->insert([
            'teacher_id' => $teacherId, 'branch_id' => 1, 'outcome_key' => 'k', 'status' => 'pending', 'created_at' => now(), 'updated_at' => now(),
        ]);
        $headers = ['Authorization' => "Bearer {$director['token']}", 'Accept' => 'application/json'];

        $report = $this->withHeaders($headers)->getJson('/api/v1/finance/teacher-eligibility?period=month&start=2026-08-01&end=2026-08-31&branch_id=1');
        $report->assertOk();
        $this->assertTrue(collect($report->json('teachers'))->contains(fn ($row) => !empty($row['review_required'])));

        $this->withHeaders($headers)->postJson('/api/v1/finance/teacher-eligibility/lock', [
            'month' => '2026-08',
            'branch_id' => 1,
        ])->assertStatus(422);
    }

    /** A month locked while base salary was in the total reads back as the bonus total, on screen and in the export. */
    public function test_snapshot_locked_with_base_salary_reads_back_as_bonus_total(): void
    {
        $director = $this->createDirector(1, 'ft-old-snapshot-dir@test.com');
        $headers = ['Authorization' => "Bearer {$director['token']}", 'Accept' => 'application/json'];
        \App\Support\FulltimePayrollLockStore::lock(1, '2026-08', [[
            'teacher_id' => 1, 'teacher_name' => 'T', 'calculated_payout' => 38700,
            'settlement' => ['base_salary' => 33000, 'total_payout' => 38700, 'calculated_payout' => 38700, 'regular_subject_count' => 150],
        ]], $director['user_id'], 'old');

        $this->withHeaders($headers)->getJson('/api/v1/finance/teacher-eligibility?period=month&start=2026-08-01&end=2026-08-31&branch_id=1')
            ->assertOk()->assertJsonPath('teachers.0.settlement.total_payout', 5700)
            ->assertJsonPath('teachers.0.calculated_payout', 5700)->assertJsonMissingPath('teachers.0.settlement.base_salary');
        $csv = $this->withHeaders($headers)->get('/api/v1/finance/teacher-eligibility/export?month=2026-08&branch_id=1')->assertOk()->streamedContent();
        $this->assertStringNotContainsString('底薪', $csv);
        $this->assertStringContainsString('T,18.75,', $csv); // 正課 150 raw is exported on the 核薪總科目數 scale
        $this->assertStringContainsString(',5700,已鎖定', $csv);
    }

    /** A locked month keeps its numbers; compare=1 adds what today's rules give next to each one and stores nothing. */
    public function test_locked_month_can_be_compared_with_todays_rules(): void
    {
        $director = $this->createDirector(1, 'ft-compare-dir@test.com');
        $headers = ['Authorization' => "Bearer {$director['token']}", 'Accept' => 'application/json'];
        $lockedTeacher = $this->createTeacher(1, 'ft-compare-locked@test.com');
        // A teacher who joined the campus after the lock: computed now, absent from the snapshot.
        $later = User::create(['LoginName' => 'ft-compare-later@test.com', 'Name' => '後來的老師', 'PSW' => 'secret', 'type' => 'T', 'phone' => '0922111112', 'MustChangePassword' => false]);
        UserCampus::create(['CampusID' => 1, 'UserID' => $later->id, 'Admin' => 0, 'Approved' => 1]);
        $laterTeacher = (int) $later->id;
        \App\Support\FulltimePayrollLockStore::lock(1, '2026-08', [[
            'teacher_id' => $lockedTeacher, 'teacher_name' => 'T', 'calculated_payout' => 5700,
            'settlement' => ['total_payout' => 5700, 'calculated_payout' => 5700],
        ]], $director['user_id'], 'old');
        $url = '/api/v1/finance/teacher-eligibility?period=month&start=2026-08-01&end=2026-08-31&branch_id=1';

        $this->withHeaders($headers)->getJson($url)->assertOk()
            ->assertJsonPath('lock.status', 'locked')->assertJsonMissingPath('teachers.0.recalculated');

        // Neither teacher has lessons, so today's rules give 0: 5700 less than the locked figure.
        $compared = $this->withHeaders($headers)->getJson($url . '&compare=1')->assertOk()
            ->assertJsonPath('lock.status', 'locked')
            ->assertJsonPath('teachers.0.calculated_payout', 5700)
            ->assertJsonCount(1, 'teachers')->json();
        $this->assertEquals(0, $compared['teachers'][0]['recalculated']['calculated_payout']);
        $this->assertEquals(-5700, $compared['teachers'][0]['recalculated']['difference']);
        $this->assertSame([$laterTeacher], $compared['recalculated_only_teacher_ids']);
        $this->assertSame(1, \Illuminate\Support\Facades\DB::table('fulltime_payroll_run_teachers')->count());
    }

    private function createDirector(int $campusId, string $email): array
    {
        $user = User::create([
            'LoginName' => $email, 'Name' => '主任', 'PSW' => 'secret',
            'type' => 'D', 'phone' => '0933888888', 'MustChangePassword' => false,
        ]);
        UserCampus::create(['CampusID' => $campusId, 'UserID' => $user->id, 'Admin' => 1, 'Approved' => 1]);
        $token = bin2hex(random_bytes(16));
        AuthToken::create(['user_id' => $user->id, 'token' => $token, 'expires_at' => now()->addDay()]);
        return ['token' => $token, 'user_id' => (int) $user->id];
    }

    private function createSuperAdmin(string $email): array
    {
        $user = User::create([
            'LoginName' => $email, 'Name' => 'SuperAdmin', 'PSW' => 'secret',
            'type' => 'S', 'phone' => '0911999999', 'MustChangePassword' => false,
        ]);
        $token = bin2hex(random_bytes(16));
        AuthToken::create(['user_id' => $user->id, 'token' => $token, 'expires_at' => now()->addDay()]);
        return ['token' => $token, 'user_id' => (int) $user->id];
    }

    private function createTeacher(int $campusId, string $email): int
    {
        $user = User::create([
            'LoginName' => $email, 'Name' => '正職老師', 'PSW' => 'secret',
            'type' => 'T', 'phone' => '0922111111', 'MustChangePassword' => false,
        ]);
        UserCampus::create(['CampusID' => $campusId, 'UserID' => $user->id, 'Admin' => 0, 'Approved' => 1]);
        return (int) $user->id;
    }
}
