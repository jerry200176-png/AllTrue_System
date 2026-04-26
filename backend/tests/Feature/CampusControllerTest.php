<?php

namespace Tests\Feature;

use App\Models\AuthToken;
use App\Models\Campus;
use App\Models\User;
use App\Models\UserCampus;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class CampusControllerTest extends TestCase
{
    use RefreshDatabase;

    private function directorToken(int $campusId, string $role = 'A'): array
    {
        $user = User::create([
            'LoginName' => 'campus-dir-' . uniqid() . '@test.com',
            'Name'      => 'Campus Director',
            'PSW'       => 'secret',
            'type'      => $role,
            'phone'     => 912000002,
        ]);
        UserCampus::create(['CampusID' => $campusId, 'UserID' => $user->id, 'Admin' => 1, 'Approved' => 1]);
        $raw = bin2hex(random_bytes(16));
        AuthToken::create(['user_id' => $user->id, 'token' => $raw, 'expires_at' => now()->addDay()]);
        return ['token' => $raw, 'user_id' => $user->id];
    }

    public function test_list_public_returns_campuses(): void
    {
        Campus::factory()->create(['name' => '大安分校']);

        $res = $this->getJson('/api/v1/branches');
        $res->assertOk();
        $this->assertIsArray($res->json());
    }

    public function test_authenticated_campuses_endpoint_requires_auth(): void
    {
        $res = $this->getJson('/api/v1/campuses');
        $res->assertStatus(401);
    }

    public function test_director_sees_own_campus(): void
    {
        $campus = Campus::factory()->create(['name' => '木柵分校']);
        $dir    = $this->directorToken($campus->id);

        $res = $this->withToken($dir['token'])->getJson("/api/v1/campuses?branch_id={$campus->id}");
        $res->assertOk();
        $ids = collect($res->json())->pluck('id')->all();
        $this->assertContains($campus->id, $ids);
    }
}
