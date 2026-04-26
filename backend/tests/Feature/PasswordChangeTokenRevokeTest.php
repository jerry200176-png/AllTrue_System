<?php

namespace Tests\Feature;

use App\Models\AuthToken;
use App\Models\Campus;
use App\Models\User;
use App\Models\UserCampus;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * SEC: changing password must revoke all existing tokens and return a fresh one.
 */
class PasswordChangeTokenRevokeTest extends TestCase
{
    use RefreshDatabase;

    private function makeUserWithTokens(int $count = 3): array
    {
        $campus = Campus::factory()->create();
        $user = User::create([
            'LoginName' => 'sec-revoke-' . uniqid() . '@test.com',
            'Name'      => 'RevokeTester',
            'PSW'       => password_hash('OldPass1!', PASSWORD_DEFAULT),
            'type'      => 'A',
            'phone'     => 912345678,
        ]);
        UserCampus::create(['CampusID' => $campus->id, 'UserID' => $user->id, 'Admin' => 1, 'Approved' => 1]);

        $tokens = [];
        for ($i = 0; $i < $count; $i++) {
            $raw = bin2hex(random_bytes(16));
            AuthToken::create(['user_id' => $user->id, 'token' => $raw, 'expires_at' => now()->addDays(7)]);
            $tokens[] = $raw;
        }

        return ['user' => $user, 'tokens' => $tokens, 'campus_id' => $campus->id];
    }

    public function test_password_change_revokes_all_old_tokens(): void
    {
        ['user' => $user, 'tokens' => $tokens] = $this->makeUserWithTokens(3);
        $activeToken = $tokens[0];

        $res = $this->withToken($activeToken)->putJson('/api/v1/me', [
            'current_password'      => 'OldPass1!',
            'password'              => 'NewPass2@',
            'password_confirmation' => 'NewPass2@',
        ]);

        $res->assertOk();
        $this->assertNotNull($res->json('new_token'), 'Response must include new_token');

        // All 3 original tokens must be gone
        foreach ($tokens as $tok) {
            $this->assertDatabaseMissing('auth_tokens', ['token' => $tok]);
        }
    }

    public function test_password_change_returns_usable_new_token(): void
    {
        ['user' => $user, 'tokens' => $tokens] = $this->makeUserWithTokens(1);

        $res = $this->withToken($tokens[0])->putJson('/api/v1/me', [
            'current_password'      => 'OldPass1!',
            'password'              => 'NewPass3#',
            'password_confirmation' => 'NewPass3#',
        ]);

        $newToken = $res->json('new_token');
        $this->assertNotNull($newToken);

        // New token must work for subsequent requests
        $meRes = $this->withToken($newToken)->getJson('/api/v1/me');
        $meRes->assertOk();
        $this->assertEquals($user->id, $meRes->json('id'));
    }

    public function test_old_token_is_rejected_after_password_change(): void
    {
        ['tokens' => $tokens] = $this->makeUserWithTokens(1);
        $oldToken = $tokens[0];

        $this->withToken($oldToken)->putJson('/api/v1/me', [
            'current_password'      => 'OldPass1!',
            'password'              => 'NewPass4$',
            'password_confirmation' => 'NewPass4$',
        ])->assertOk();

        // Old token must now be rejected
        $this->withToken($oldToken)->getJson('/api/v1/me')->assertStatus(401);
    }

    public function test_profile_update_without_password_does_not_issue_new_token(): void
    {
        ['tokens' => $tokens] = $this->makeUserWithTokens(1);

        $res = $this->withToken($tokens[0])->putJson('/api/v1/me', [
            'name' => '新名字',
        ]);

        $res->assertOk();
        $this->assertNull($res->json('new_token'));

        // Original token still exists
        $this->assertDatabaseHas('auth_tokens', ['token' => $tokens[0]]);
    }
}
