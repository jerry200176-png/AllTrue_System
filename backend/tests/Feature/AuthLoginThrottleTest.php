<?php

namespace Tests\Feature;

use App\Models\User;
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
}
