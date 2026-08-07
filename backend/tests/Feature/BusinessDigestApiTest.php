<?php

namespace Tests\Feature;

use App\Models\AuthToken;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * GET /api/v1/admin/business-digest — #1147 Phase 1 read-only surface.
 * super_admin only; thin wrapper over BusinessDigestService (already covered
 * by OpsBusinessDigestTest), so this asserts the HTTP/authorization contract
 * rather than re-deriving the metrics themselves.
 */
class BusinessDigestApiTest extends TestCase
{
    use RefreshDatabase;

    private function tokenFor(string $type): string
    {
        $user = User::create([
            'LoginName' => strtolower($type) . '-' . uniqid() . '@test.com',
            'Name'      => 'Test User',
            'PSW'       => bcrypt('pw'),
            'type'      => $type,
            'status'    => 'active',
        ]);
        $raw = bin2hex(random_bytes(16));
        AuthToken::create(['user_id' => $user->id, 'token' => $raw, 'expires_at' => now()->addDay()]);

        return $raw;
    }

    public function test_super_admin_can_read_digest(): void
    {
        $response = $this->withToken($this->tokenFor('S'))->getJson('/api/v1/admin/business-digest');

        $response->assertOk();
        $response->assertJsonStructure(['generated_at', 'revenue', 'retention', 'data_quality']);
    }

    public function test_super_admin_can_scope_to_a_campus(): void
    {
        $response = $this->withToken($this->tokenFor('S'))->getJson('/api/v1/admin/business-digest?campus_id=1');

        $response->assertOk();
        $response->assertJson(['campus_id' => 1]);
    }

    public function test_non_super_admin_is_forbidden(): void
    {
        $response = $this->withToken($this->tokenFor('A'))->getJson('/api/v1/admin/business-digest');

        $response->assertForbidden();
    }
}
