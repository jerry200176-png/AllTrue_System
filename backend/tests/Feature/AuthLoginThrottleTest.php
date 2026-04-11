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
