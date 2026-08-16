<?php

namespace Tests\Feature;

use App\Models\AuthToken;
use App\Models\User;
use App\Models\UserCampus;
use App\Support\FulltimeSettlementComposer;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class FulltimeCashAdjustmentTest extends TestCase
{
    use RefreshDatabase;

    public function test_director_confirm_then_hq_approve(): void
    {
        $director = $this->createDirector(1, 'ca-dir@test.com');
        $hq = $this->createSuperAdmin('ca-hq@test.com');
        $teacherId = $this->createTeacher(1, 'ca-teacher@test.com');

        $create = $this->withHeaders($this->auth($director['token']))->postJson('/api/v1/finance/teacher-eligibility/cash-adjustments', [
            'teacher_id' => $teacherId,
            'branch_id' => 1,
            'amount' => 1500,
            'reason' => '誤餐補貼',
            'starts_on' => '2026-08-01',
        ]);
        $create->assertCreated();
        $id = (int) $create->json('id');

        $this->withHeaders($this->auth($director['token']))
            ->postJson("/api/v1/finance/teacher-eligibility/cash-adjustments/{$id}/approve")
            ->assertStatus(403);

        $this->withHeaders($this->auth($director['token']))
            ->postJson("/api/v1/finance/teacher-eligibility/cash-adjustments/{$id}/confirm")
            ->assertOk();

        $this->withHeaders($this->auth($hq['token']))
            ->postJson("/api/v1/finance/teacher-eligibility/cash-adjustments/{$id}/approve")
            ->assertOk();

        $this->assertDatabaseHas('teacher_payroll_cash_adjustments', [
            'id' => $id,
            'status' => 'approved',
            'hq_approved_by' => $hq['user_id'],
        ]);
    }

    public function test_zero_amount_rejected_and_approved_amount_enters_payout(): void
    {
        $director = $this->createDirector(1, 'ca-dir2@test.com');
        $this->withHeaders($this->auth($director['token']))->postJson('/api/v1/finance/teacher-eligibility/cash-adjustments', [
            'teacher_id' => $this->createTeacher(1, 'ca-teacher2@test.com'),
            'branch_id' => 1,
            'amount' => 0,
            'reason' => '空白',
            'starts_on' => '2026-08-01',
        ])->assertStatus(422);

        $result = FulltimeSettlementComposer::compose([
            'weekly_16_segments' => ['status' => 'qualifies', 'amount' => 0, 'rate' => 0],
            'cash_adjustments' => ['status' => 'qualifies', 'amount' => 1500, 'rate' => 0],
        ], 33000.0);
        $this->assertSame(1500.0, $result['adjustments'][0]['amount']);
        $this->assertSame(34500.0, $result['total_payout']);
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
            'LoginName' => $email, 'Name' => '總部', 'PSW' => 'secret',
            'type' => 'S', 'phone' => '0911111111', 'MustChangePassword' => false,
        ]);
        $token = bin2hex(random_bytes(16));
        AuthToken::create(['user_id' => $user->id, 'token' => $token, 'expires_at' => now()->addDay()]);
        return ['token' => $token, 'user_id' => (int) $user->id];
    }

    private function createTeacher(int $campusId, string $email): int
    {
        $user = User::create([
            'LoginName' => $email, 'Name' => '老師', 'PSW' => 'secret',
            'type' => 'T', 'phone' => '0922222222', 'MustChangePassword' => false,
        ]);
        UserCampus::create(['CampusID' => $campusId, 'UserID' => $user->id, 'Admin' => 0, 'Approved' => 1]);
        return (int) $user->id;
    }
}
