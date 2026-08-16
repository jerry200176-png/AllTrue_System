<?php

namespace Tests\Feature;

use App\Models\AuthToken;
use App\Models\User;
use App\Models\UserCampus;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class FulltimeAdminAllowanceTest extends TestCase
{
    use RefreshDatabase;

    public function test_director_confirm_then_hq_approve_feeds_policy_path(): void
    {
        $director = $this->createDirector(1, 'aa-dir@test.com');
        $hq = $this->createSuperAdmin('aa-hq@test.com');
        $teacherId = $this->createTeacher(1, 'aa-teacher@test.com');

        $create = $this->withHeaders($this->auth($director['token']))->postJson('/api/v1/finance/teacher-eligibility/admin-allowances', [
            'teacher_id' => $teacherId,
            'branch_id' => 1,
            'role_key' => 'head_tutor',
            'rate' => 8,
            'reason' => '總導師職務',
            'starts_on' => '2026-08-01',
        ]);
        $create->assertCreated();
        $id = (int) $create->json('id');

        $this->withHeaders($this->auth($director['token']))
            ->postJson("/api/v1/finance/teacher-eligibility/admin-allowances/{$id}/approve")
            ->assertStatus(403);

        $this->withHeaders($this->auth($director['token']))
            ->postJson("/api/v1/finance/teacher-eligibility/admin-allowances/{$id}/confirm")
            ->assertOk();

        $this->withHeaders($this->auth($hq['token']))
            ->postJson("/api/v1/finance/teacher-eligibility/admin-allowances/{$id}/approve")
            ->assertOk();

        $this->assertDatabaseHas('teacher_payroll_admin_allowances', [
            'id' => $id,
            'status' => 'approved',
            'hq_approved_by' => $hq['user_id'],
        ]);
    }

    public function test_rate_above_ten_is_rejected(): void
    {
        $director = $this->createDirector(1, 'aa-dir2@test.com');
        $teacherId = $this->createTeacher(1, 'aa-teacher2@test.com');

        $this->withHeaders($this->auth($director['token']))->postJson('/api/v1/finance/teacher-eligibility/admin-allowances', [
            'teacher_id' => $teacherId,
            'branch_id' => 1,
            'role_key' => 'admin_assist',
            'rate' => 10.5,
            'reason' => '超標',
            'starts_on' => '2026-08-01',
        ])->assertStatus(422);
    }

    private function auth(string $token): array
    {
        return ['Authorization' => "Bearer {$token}", 'Accept' => 'application/json'];
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
