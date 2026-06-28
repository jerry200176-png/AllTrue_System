<?php

namespace Tests\Feature;

use App\Models\AuthToken;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * SEC-AUDIT-007/008 (#974): engagement routes must require an authenticated
 * workbench role. Previously they had no role gate and AttachAuthUser does not
 * fail closed, so unauthenticated requests reached the controller.
 */
class EngagementRouteAuthzTest extends TestCase
{
    use RefreshDatabase;

    public function test_engagement_routes_reject_unauthenticated(): void
    {
        // No Bearer token / no identity headers → no auth_role → RequireRole returns 401.
        $this->getJson('/api/v1/engagement/badges')->assertStatus(401);
        $this->getJson('/api/v1/engagement/my-progress')->assertStatus(401);
        $this->postJson('/api/v1/engagement/award-xp', ['event' => 'some_event'])->assertStatus(401);
        $this->postJson('/api/v1/engagement/badges/foo/toggle-visibility')->assertStatus(401);
    }

    public function test_engagement_route_allows_authenticated_teacher(): void
    {
        $teacher = User::create([
            'LoginName' => 'eng-teacher-' . uniqid() . '@test.com',
            'Name'      => 'Eng Teacher',
            'PSW'       => 'secret',
            'type'      => 'T',
            'phone'     => 912000777,
        ]);
        $raw = bin2hex(random_bytes(16));
        AuthToken::create(['user_id' => $teacher->id, 'token' => $raw, 'expires_at' => now()->addDay()]);

        // role 'teacher' is in the allowed set → must not be blocked by the gate.
        $res = $this->withToken($raw)->getJson('/api/v1/engagement/badges');
        $this->assertNotContains($res->status(), [401, 403]);
    }
}
