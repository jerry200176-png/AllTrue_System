<?php

namespace Tests\Feature;

use App\Models\Campus;
use App\Models\User;
use App\Models\AuthToken;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class BranchHealthApiTest extends TestCase
{
    use RefreshDatabase;

    public function test_super_admin_can_read_board_and_contract_is_explainable(): void
    {
        $campus = Campus::factory()->create(['active' => true]);
        $response = $this->withToken($this->tokenFor('S'))->getJson('/api/v1/admin/branch-health');

        $response->assertOk()
            ->assertJsonPath('meta.version', 'branch-health-v1')
            ->assertJsonPath('meta.score', false)
            ->assertJsonStructure([
                'data' => [['branch_id', 'branch_name', 'status', 'dimensions' => [
                    'students', 'teaching', 'parents', 'teachers', 'operations',
                ]]],
                'meta' => ['generated_at', 'periods', 'ranking', 'score'],
            ]);
        $branchIds = array_column($response->json('data'), 'branch_id');
        $this->assertContains($campus->id, $branchIds);
        $first = collect($response->json('data'))->firstWhere('branch_id', $campus->id);
        $this->assertNotEmpty($first['dimensions']['students']['source']);
    }

    public function test_super_admin_can_read_one_active_branch_but_not_inactive_branch(): void
    {
        $active = Campus::factory()->create(['active' => true]);
        $inactive = Campus::factory()->create(['active' => false]);
        $token = $this->tokenFor('S');

        $this->withToken($token)->getJson('/api/v1/admin/branch-health?branch_id=' . $active->id)
            ->assertOk()->assertJsonPath('data.0.branch_id', $active->id);
        $this->withToken($token)->getJson('/api/v1/admin/branch-health?branch_id=' . $inactive->id)
            ->assertNotFound();
    }

    public function test_non_super_admin_cannot_read_branch_health(): void
    {
        $this->withToken($this->tokenFor('D'))->getJson('/api/v1/admin/branch-health')->assertForbidden();
    }

    private function tokenFor(string $type): string
    {
        $user = User::create([
            'LoginName' => strtolower($type) . '-' . uniqid() . '@test.com',
            'Name' => 'Branch Health User',
            'PSW' => bcrypt('pw'),
            'type' => $type,
            'status' => 'active',
        ]);
        $raw = bin2hex(random_bytes(16));
        AuthToken::create(['user_id' => $user->id, 'token' => $raw, 'expires_at' => now()->addDay()]);

        return $raw;
    }
}
