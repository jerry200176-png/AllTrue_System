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
        $u = $this->makeUser('S', 'super-engagement@example.com');
        $r = $this->getJson('/api/v1/me', $this->bearer($u['tok']))->assertOk();
        $r->assertJsonPath('engagement.rank_key', 'general_five_star')
            ->assertJsonPath('engagement.rank_label', '五星上將')
            ->assertJsonPath('engagement.role_track', 'staff');
        $eng = $r->json('engagement');
        $this->assertIsArray($eng);
        $this->assertArrayNotHasKey('xp_total', $eng);
    }

    public function test_teacher_without_engagement_row_gets_default_rank_and_zero_xp(): void
    {
        $u = $this->makeUser('T', 'teacher-engagement@example.com');
        $this->getJson('/api/v1/me', $this->bearer($u['tok']))->assertOk()
            ->assertJsonPath('engagement.rank_key', 'private_second')
            ->assertJsonPath('engagement.rank_label', '二兵')
            ->assertJsonPath('engagement.role_track', 'teacher')
            ->assertJsonPath('engagement.xp_total', 0);
    }

    public function test_teacher_with_engagement_row_gets_stored_rank_and_xp(): void
    {
        $u = $this->makeUser('T', 'teacher-engagement-row@example.com');
        UserEngagement::factory()->create([
            // 長期養成曲線：corporal/下士 = 260–449（2026-06-14 重設）
            'user_id' => $u['id'], 'role_track' => 'teacher', 'rank_key' => 'corporal', 'xp_total' => 300,
        ]);
        $this->getJson('/api/v1/me', $this->bearer($u['tok']))->assertOk()
            ->assertJsonPath('engagement.rank_key', 'corporal')
            ->assertJsonPath('engagement.rank_label', '下士')
            ->assertJsonPath('engagement.xp_total', 300);
    }

    public function test_non_staff_pending_role_has_no_engagement_block(): void
    {
        $u = $this->makeUser('U', 'pending-engagement@example.com');
        $me = $this->getJson('/api/v1/me', $this->bearer($u['tok']));
        $me->assertOk();
        $this->assertArrayNotHasKey('engagement', $me->json());
    }

    /** @return array{id:int,tok:string} */
    private function makeUser(string $type, string $email): array
    {
        $row = User::create([
            'LoginName' => $email, 'Name' => 'T', 'PSW' => password_hash('secret-123', PASSWORD_DEFAULT),
            'type' => $type, 'phone' => '0900000000',
        ]);
        $tok = bin2hex(random_bytes(16));
        AuthToken::create(['user_id' => $row->id, 'token' => $tok, 'expires_at' => now()->addDay()]);

        return ['id' => (int) $row->id, 'tok' => $tok];
    }

    /** @return array<string, string> */
    private function bearer(string $token): array
    {
        return ['Authorization' => "Bearer {$token}", 'Accept' => 'application/json'];
    }
}
