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

    public function test_empty_month_can_lock_and_blocks_salary_and_adjustments(): void
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
        $this->withHeaders($headers)->postJson('/api/v1/finance/teacher-eligibility/salary-profiles', [
            'teacher_id' => $teacherId,
            'branch_id' => 1,
            'base_salary' => 33000,
            'effective_from' => '2026-08-01',
        ])->assertStatus(422);

        $this->withHeaders($headers)->postJson('/api/v1/finance/teacher-eligibility/adjustments', [
            'branch_id' => 1,
            'month' => '2026-08',
            'teacher_id' => $teacherId,
            'field' => 'cash',
            'delta' => 200,
            'label' => '臨時加給',
        ])->assertStatus(422);
    }

    public function test_only_super_admin_can_reopen_and_then_adjustments_apply(): void
    {
        $director = $this->createDirector(1, 'ft-reopen-dir@test.com');
        $admin = $this->createSuperAdmin('ft-reopen-admin@test.com');
        $dirHeaders = ['Authorization' => "Bearer {$director['token']}", 'Accept' => 'application/json'];
        $adminHeaders = ['Authorization' => "Bearer {$admin['token']}", 'Accept' => 'application/json'];

        $this->withHeaders($dirHeaders)->postJson('/api/v1/finance/teacher-eligibility/lock', [
            'month' => '2026-08',
            'branch_id' => 1,
        ])->assertOk();

        $this->withHeaders($dirHeaders)->postJson('/api/v1/finance/teacher-eligibility/reopen', [
            'month' => '2026-08',
            'branch_id' => 1,
            'reason' => '更正科目數',
        ])->assertStatus(403);

        $this->withHeaders($adminHeaders)->postJson('/api/v1/finance/teacher-eligibility/reopen', [
            'month' => '2026-08',
            'branch_id' => 1,
            'reason' => '更正科目數',
        ])->assertOk()->assertJsonPath('status', 'reopened');

        $teacherId = $this->createTeacher(1, 'ft-reopen-teacher@test.com');

        $this->withHeaders($dirHeaders)->postJson('/api/v1/finance/teacher-eligibility/adjustments', [
            'branch_id' => 1,
            'month' => '2026-08',
            'teacher_id' => $teacherId,
            'field' => 'cash',
            'delta' => 200,
            'label' => '臨時加給',
        ])->assertCreated();

        $report = $this->withHeaders($dirHeaders)->getJson('/api/v1/finance/teacher-eligibility?period=month&start=2026-08-01&end=2026-08-31&branch_id=1');
        $report->assertOk()->assertJsonPath('lock.status', 'draft');
        $row = collect($report->json('teachers'))->firstWhere('teacher_id', $teacherId);
        $this->assertNotNull($row);
        $this->assertSame(200.0, (float) collect($row['settlement']['adjustments'] ?? [])->firstWhere('label', '臨時加給')['amount']);
        $this->assertSame(200.0, (float) $row['settlement']['total_payout']);
    }

    public function test_cannot_lock_when_any_teacher_is_still_review(): void
    {
        $director = $this->createDirector(1, 'ft-review-dir@test.com');
        $this->createTeacher(1, 'ft-review-teacher@test.com');
        $headers = ['Authorization' => "Bearer {$director['token']}", 'Accept' => 'application/json'];

        $report = $this->withHeaders($headers)->getJson('/api/v1/finance/teacher-eligibility?period=month&start=2026-08-01&end=2026-08-31&branch_id=1');
        $report->assertOk();
        $this->assertTrue(collect($report->json('teachers'))->contains(fn ($row) => !empty($row['review_required'])));

        $this->withHeaders($headers)->postJson('/api/v1/finance/teacher-eligibility/lock', [
            'month' => '2026-08',
            'branch_id' => 1,
        ])->assertStatus(422);
    }

    public function test_admin_allowance_requires_director_then_hq(): void
    {
        $director = $this->createDirector(1, 'ft-admin-dir@test.com');
        $admin = $this->createSuperAdmin('ft-admin-hq@test.com');
        $teacherId = $this->createTeacher(1, 'ft-admin-teacher@test.com');
        $dirHeaders = ['Authorization' => "Bearer {$director['token']}", 'Accept' => 'application/json'];

        $created = $this->withHeaders($dirHeaders)->postJson('/api/v1/finance/teacher-eligibility/admin-allowances', [
            'teacher_id' => $teacherId,
            'branch_id' => 1,
            'rate' => 8,
            'role_label' => '總導師',
            'starts_on' => '2026-08-01',
        ]);
        $created->assertCreated();
        $id = (int) $created->json('id');

        $this->withHeaders($dirHeaders)->postJson("/api/v1/finance/teacher-eligibility/admin-allowances/{$id}/approve")
            ->assertStatus(403);

        $this->withHeaders($dirHeaders)->postJson("/api/v1/finance/teacher-eligibility/admin-allowances/{$id}/confirm")
            ->assertOk();

        $this->withHeaders([
            'Authorization' => "Bearer {$admin['token']}",
            'Accept' => 'application/json',
        ])->postJson("/api/v1/finance/teacher-eligibility/admin-allowances/{$id}/approve")
            ->assertOk()
            ->assertJsonPath('status', 'approved');
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
