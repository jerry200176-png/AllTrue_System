<?php

namespace Tests\Feature;

use App\Models\AuthToken;
use App\Models\User;
use App\Models\UserEngagement;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class UserEngagementMeApiTest extends TestCase
{
    use RefreshDatabase;

    public function test_super_admin_me_returns_fixed_five_star_rank_without_xp(): void
    {
        $admin = User::create([
            'LoginName' => 'super-engagement@example.com',
            'Name' => 'Super',
            'PSW' => password_hash('secret-123', PASSWORD_DEFAULT),
            'type' => 'S',
            'phone' => '0900000001',
        ]);
        $token = $this->issueToken($admin->id);

        $res = $this->withHeaders([
            'Authorization' => "Bearer {$token}",
            'Accept' => 'application/json',
        ])->getJson('/api/v1/me');

        $res->assertOk()
            ->assertJsonPath('engagement.rank_key', 'general_five_star')
            ->assertJsonPath('engagement.rank_label', '五星上將')
            ->assertJsonPath('engagement.role_track', 'staff');
        $eng = $res->json('engagement');
        $this->assertIsArray($eng);
        $this->assertArrayNotHasKey('xp_total', $eng);
    }

    public function test_teacher_without_engagement_row_gets_default_rank_and_zero_xp(): void
    {
        $teacher = User::create([
            'LoginName' => 'teacher-engagement@example.com',
            'Name' => 'Teacher Eng',
            'PSW' => password_hash('secret-123', PASSWORD_DEFAULT),
            'type' => 'T',
            'phone' => '0900000002',
        ]);
        $token = $this->issueToken($teacher->id);

        $this->withHeaders([
            'Authorization' => "Bearer {$token}",
            'Accept' => 'application/json',
        ])->getJson('/api/v1/me')
            ->assertOk()
            ->assertJsonPath('engagement.rank_key', 'private_second')
            ->assertJsonPath('engagement.rank_label', '二兵')
            ->assertJsonPath('engagement.role_track', 'teacher')
            ->assertJsonPath('engagement.xp_total', 0);
    }

    public function test_teacher_with_engagement_row_gets_stored_rank_and_xp(): void
    {
        $teacher = User::create([
            'LoginName' => 'teacher-engagement-row@example.com',
            'Name' => 'Teacher Row',
            'PSW' => password_hash('secret-123', PASSWORD_DEFAULT),
            'type' => 'T',
            'phone' => '0900000003',
        ]);
        UserEngagement::factory()->create([
            'user_id' => $teacher->id,
            'role_track' => 'teacher',
            'rank_key' => 'corporal',
            'xp_total' => 120,
        ]);
        $token = $this->issueToken($teacher->id);

        $this->withHeaders([
            'Authorization' => "Bearer {$token}",
            'Accept' => 'application/json',
        ])->getJson('/api/v1/me')
            ->assertOk()
            ->assertJsonPath('engagement.rank_key', 'corporal')
            ->assertJsonPath('engagement.rank_label', '下士')
            ->assertJsonPath('engagement.xp_total', 120);
    }

    public function test_non_staff_pending_role_has_no_engagement_block(): void
    {
        $pending = User::create([
            'LoginName' => 'pending-engagement@example.com',
            'Name' => 'Pending',
            'PSW' => password_hash('secret-123', PASSWORD_DEFAULT),
            'type' => 'U',
            'phone' => '0900000004',
        ]);
        $token = $this->issueToken($pending->id);

        $me = $this->withHeaders([
            'Authorization' => "Bearer {$token}",
            'Accept' => 'application/json',
        ])->getJson('/api/v1/me');

        $me->assertOk();
        $this->assertArrayNotHasKey('engagement', $me->json());
    }

    private function issueToken(int $userId): string
    {
        $token = bin2hex(random_bytes(16));
        AuthToken::create([
            'user_id' => $userId,
            'token' => $token,
            'expires_at' => now()->addDay(),
        ]);

        return $token;
    }
}
