<?php

namespace Tests\Feature;

use App\Models\AuthToken;
use App\Models\User;
use App\Models\UserCampus;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AuthLoginThrottleTest extends TestCase
{
    use RefreshDatabase;

    public function test_login_locks_after_five_failed_attempts_and_unlocks_after_cooldown(): void
    {
        $email = 'lock-test@example.com';
        $password = 'correct-pass';

        $user = User::create([
            'LoginName' => $email,
            'Name' => 'Lock Test Teacher',
            'PSW' => password_hash($password, PASSWORD_DEFAULT),
            'type' => 'T',
            'phone' => 900100001,
        ]);
        UserCampus::create([
            'UserID' => $user->id,
            'CampusID' => 1,
            'Admin' => 0,
            'Approved' => true,
        ]);

        for ($i = 1; $i <= 4; $i++) {
            $this->postJson('/api/v1/auth/login', [
                'email' => $email,
                'password' => 'wrong-pass',
            ])->assertStatus(401)
                ->assertJsonPath('message', '帳號或密碼錯誤');
        }

        $this->postJson('/api/v1/auth/login', [
            'email' => $email,
            'password' => 'wrong-pass',
        ])->assertStatus(429)
            ->assertJsonPath('message', '登入錯誤次數過多，請稍後再試')
            ->assertJsonStructure(['retry_after_seconds', 'locked_until']);

        $this->postJson('/api/v1/auth/login', [
            'email' => $email,
            'password' => $password,
        ])->assertStatus(429);

        $this->travel(16)->minutes();

        $this->postJson('/api/v1/auth/login', [
            'email' => $email,
            'password' => $password,
        ])->assertOk()
            ->assertJsonPath('data.session.user.role', 'teacher');

        $this->assertDatabaseHas('user_login_activities', [
            'user_id' => $user->id,
            'success' => 1,
        ]);
    }

    public function test_login_keeps_other_device_token_when_user_agent_differs(): void
    {
        // #3767: UI Smoke runs use a per-run User-Agent so parallel runs sharing
        // one smoke account do not revoke each other's sessions.
        $user = User::create([
            'LoginName' => 'other-device@example.com',
            'Name' => 'Other Device User',
            'PSW' => password_hash('correct-pass', PASSWORD_DEFAULT),
            'type' => 'T',
            'phone' => 900100003,
        ]);
        UserCampus::create(['UserID' => $user->id, 'CampusID' => 1, 'Admin' => 0, 'Approved' => true]);

        $tokens = [];
        foreach (['Chrome AllTrueUISmoke/1-1', 'Chrome AllTrueUISmoke/2-1'] as $agent) {
            $tokens[] = (string) $this
                ->withHeaders(['User-Agent' => $agent])
                ->postJson('/api/v1/auth/login', ['email' => 'other-device@example.com', 'password' => 'correct-pass'])
                ->assertOk()
                ->json('data.session.access_token');
        }

        foreach ($tokens as $token) {
            $this->assertDatabaseHas('auth_tokens', ['token' => $token, 'user_id' => $user->id]);
        }
    }

    public function test_login_revokes_previous_token_on_same_device_and_uses_seven_day_expiry(): void
    {
        $email = 'same-device@example.com';
        $password = 'correct-pass';

        $user = User::create([
            'LoginName' => $email,
            'Name' => 'Same Device User',
            'PSW' => password_hash($password, PASSWORD_DEFAULT),
            'type' => 'T',
            'phone' => 900100002,
        ]);
        UserCampus::create([
            'UserID' => $user->id,
            'CampusID' => 1,
            'Admin' => 0,
            'Approved' => true,
        ]);

        $agent = 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit Test/1.0';

        $first = $this
            ->withHeaders(['User-Agent' => $agent])
            ->postJson('/api/v1/auth/login', [
                'email' => $email,
                'password' => $password,
            ])
            ->assertOk();

        $firstToken = (string) $first->json('data.session.access_token');
        $this->assertNotSame('', $firstToken);
        $this->assertDatabaseHas('auth_tokens', ['token' => $firstToken, 'user_id' => $user->id]);

        $second = $this
            ->withHeaders(['User-Agent' => $agent])
            ->postJson('/api/v1/auth/login', [
                'email' => $email,
                'password' => $password,
            ])
            ->assertOk();

        $secondToken = (string) $second->json('data.session.access_token');
        $this->assertNotSame('', $secondToken);
        $this->assertNotSame($firstToken, $secondToken);

        $this->assertDatabaseMissing('auth_tokens', ['token' => $firstToken]);
        $this->assertDatabaseHas('auth_tokens', ['token' => $secondToken, 'user_id' => $user->id]);

        $activeTokens = AuthToken::where('user_id', $user->id)->get();
        $this->assertCount(1, $activeTokens);

        $expiresAt = Carbon::parse($activeTokens->first()->expires_at);
        $this->assertTrue($expiresAt->between(now()->addDays(6), now()->addDays(8)));
    }
}
