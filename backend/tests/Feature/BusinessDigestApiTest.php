<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
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

    public function test_super_admin_can_read_digest(): void
    {
        $admin = User::factory()->create(['type' => 'S']);

        $response = $this->actingAs($admin)->getJson('/api/v1/admin/business-digest');

        $response->assertOk();
        $response->assertJsonStructure(['generated_at', 'revenue', 'retention', 'data_quality']);
    }

    public function test_super_admin_can_scope_to_a_campus(): void
    {
        $admin = User::factory()->create(['type' => 'S']);

        $response = $this->actingAs($admin)->getJson('/api/v1/admin/business-digest?campus_id=1');

        $response->assertOk();
        $response->assertJson(['campus_id' => 1]);
    }

    public function test_non_super_admin_is_forbidden(): void
    {
        $director = User::factory()->create(['type' => 'D']);

        $response = $this->actingAs($director)->getJson('/api/v1/admin/business-digest');

        $response->assertForbidden();
    }
}
