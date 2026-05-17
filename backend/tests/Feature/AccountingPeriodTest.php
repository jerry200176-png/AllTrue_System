<?php

namespace Tests\Feature;

use App\Models\AccountingPeriod;
use App\Models\AuthToken;
use App\Models\Campus;
use App\Models\User;
use Database\Factories\CampusFactory;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AccountingPeriodTest extends TestCase
{
    use RefreshDatabase;

    public function test_director_can_close_period(): void
    {
        [$token, $campus] = $this->seedDirector();

        $r = $this->postJson('/api/v1/finance/periods/close', [
            'branch_id' => $campus->id,
            'year' => 2026,
            'month' => 4,
        ], $this->bearer($token));
        $r->assertOk();
        $this->assertDatabaseHas('accounting_periods', [
            'branch_id' => $campus->id,
            'period_year' => 2026,
            'period_month' => 4,
        ]);
    }

    public function test_cannot_close_future_period(): void
    {
        [$token, $campus] = $this->seedDirector();

        $r = $this->postJson('/api/v1/finance/periods/close', [
            'branch_id' => $campus->id,
            'year' => 2027,
            'month' => 12,
        ], $this->bearer($token));
        $r->assertStatus(422);
    }

    public function test_super_admin_can_reopen_period(): void
    {
        [$token, $campus] = $this->seedSuperAdmin();

        AccountingPeriod::create([
            'branch_id' => $campus->id,
            'period_year' => 2026,
            'period_month' => 3,
            'closed_at' => now()->subDay(),
            'closed_by' => 1,
        ]);

        $r = $this->postJson('/api/v1/finance/periods/reopen', [
            'branch_id' => $campus->id,
            'year' => 2026,
            'month' => 3,
            'reason' => '需補登 3 月漏帳',
        ], $this->bearer($token));
        $r->assertOk();
    }

    public function test_list_periods(): void
    {
        [$token, $campus] = $this->seedDirector();

        AccountingPeriod::create([
            'branch_id' => $campus->id,
            'period_year' => 2026,
            'period_month' => 2,
            'closed_at' => now()->subWeek(),
            'closed_by' => 1,
        ]);

        $r = $this->getJson('/api/v1/finance/periods?branch_id=' . $campus->id, $this->bearer($token));
        $r->assertOk();
        $this->assertCount(1, $r->json('periods'));
        $this->assertTrue($r->json('periods.0.is_closed'));
    }

    private function seedDirector(): array
    {
        $campus = CampusFactory::new()->create();
        $user = User::create([
            'LoginName' => 'dir_period_' . uniqid() . '@example.com',
            'Name' => 'Director', 'PSW' => password_hash('s', PASSWORD_DEFAULT),
            'type' => 'A', 'phone' => '0900000000',
        ]);
        \App\Models\UserCampus::create([
            'UserID' => $user->id, 'CampusID' => $campus->id, 'Approved' => 1,
        ]);
        $tok = bin2hex(random_bytes(16));
        AuthToken::create(['user_id' => $user->id, 'token' => $tok, 'expires_at' => now()->addDay()]);
        return [$tok, $campus];
    }

    private function seedSuperAdmin(): array
    {
        $campus = CampusFactory::new()->create();
        $user = User::create([
            'LoginName' => 'super_period_' . uniqid() . '@example.com',
            'Name' => 'Super', 'PSW' => password_hash('s', PASSWORD_DEFAULT),
            'type' => 'S', 'phone' => '0900000000',
        ]);
        $tok = bin2hex(random_bytes(16));
        AuthToken::create(['user_id' => $user->id, 'token' => $tok, 'expires_at' => now()->addDay()]);
        return [$tok, $campus];
    }

    private function bearer(string $token): array
    {
        return ['Authorization' => "Bearer {$token}", 'Accept' => 'application/json'];
    }
}
