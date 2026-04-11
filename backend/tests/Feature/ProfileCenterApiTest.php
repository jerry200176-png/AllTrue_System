<?php

namespace Tests\Feature;

use App\Models\AuthToken;
use App\Models\User;
use App\Models\UserLoginActivity;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class ProfileCenterApiTest extends TestCase
{
    use RefreshDatabase;

    public function test_teacher_can_read_me_with_enterprise_profile_fields(): void
    {
        $teacher = User::create([
            'LoginName' => 'profile-teacher@example.com',
            'Name' => 'Profile Teacher',
            'PSW' => password_hash('secret-123', PASSWORD_DEFAULT),
            'type' => 'T',
            'phone' => '0911222333',
            'AvatarUrl' => '/storage/avatars/1/avatar.jpg',
        ]);
        $token = $this->issueToken($teacher->id);

        $this->withHeaders([
            'Authorization' => "Bearer {$token}",
            'Accept' => 'application/json',
        ])->getJson('/api/v1/me')
            ->assertOk()
            ->assertJsonPath('id', $teacher->id)
            ->assertJsonPath('avatar_url', '/storage/avatars/1/avatar.jpg')
            ->assertJsonStructure([
                'notification_preferences' => [
                    'in_app_enabled',
                    'email_enabled',
                    'line_enabled',
                    'quiet_hours_start',
                    'quiet_hours_end',
                    'event_tuition',
                    'event_learning_review',
                    'event_attendance',
                    'event_system',
                ],
                'security_summary' => ['recent_logins', 'active_sessions'],
            ]);

        $this->assertDatabaseHas('user_notification_preferences', [
            'user_id' => $teacher->id,
        ]);
    }

    public function test_director_can_update_own_profile_through_me_endpoint(): void
    {
        $director = User::create([
            'LoginName' => 'profile-director@example.com',
            'Name' => 'Profile Director',
            'PSW' => password_hash('secret-123', PASSWORD_DEFAULT),
            'type' => 'D',
            'phone' => '0911999888',
        ]);
        $token = $this->issueToken($director->id);

        $this->withHeaders([
            'Authorization' => "Bearer {$token}",
            'Accept' => 'application/json',
        ])->putJson('/api/v1/me', [
            'name' => 'Director New',
            'email' => 'profile-director-new@example.com',
            'phone' => '0922333444',
        ])->assertOk()
            ->assertJsonPath('name', 'Director New')
            ->assertJsonPath('email', 'profile-director-new@example.com')
            ->assertJsonPath('phone', '0922333444');

        $this->assertDatabaseHas('User', [
            'id' => $director->id,
            'Name' => 'Director New',
            'LoginName' => 'profile-director-new@example.com',
            'phone' => '0922333444',
        ]);
    }

    public function test_avatar_upload_success_and_invalid_file_rejected(): void
    {
        Storage::fake('public');

        $teacher = User::create([
            'LoginName' => 'avatar-user@example.com',
            'Name' => 'Avatar User',
            'PSW' => password_hash('secret-123', PASSWORD_DEFAULT),
            'type' => 'T',
            'phone' => '0900000001',
        ]);
        $token = $this->issueToken($teacher->id);

        $this->withHeaders([
            'Authorization' => "Bearer {$token}",
            'Accept' => 'application/json',
        ])->post('/api/v1/me/avatar', [
            'avatar' => UploadedFile::fake()->image('avatar.jpg', 120, 120),
        ])->assertOk()
            ->assertJsonStructure(['avatar_url']);

        $teacher->refresh();
        $this->assertNotNull($teacher->AvatarUrl);
        Storage::disk('public')->assertExists("avatars/{$teacher->id}/avatar.jpg");

        $this->withHeaders([
            'Authorization' => "Bearer {$token}",
            'Accept' => 'application/json',
        ])->post('/api/v1/me/avatar', [
            'avatar' => UploadedFile::fake()->create('bad.txt', 10, 'text/plain'),
        ])->assertStatus(422);
    }

    public function test_avatar_second_upload_same_filename_succeeds(): void
    {
        Storage::fake('public');

        $teacher = User::create([
            'LoginName' => 'avatar-twice@example.com',
            'Name' => 'Avatar Twice',
            'PSW' => password_hash('secret-123', PASSWORD_DEFAULT),
            'type' => 'T',
            'phone' => '0900000009',
        ]);
        $token = $this->issueToken($teacher->id);

        $headers = [
            'Authorization' => "Bearer {$token}",
            'Accept' => 'application/json',
        ];

        $this->withHeaders($headers)->post('/api/v1/me/avatar', [
            'avatar' => UploadedFile::fake()->image('avatar.jpg', 80, 80),
        ])->assertOk();

        $this->withHeaders($headers)->post('/api/v1/me/avatar', [
            'avatar' => UploadedFile::fake()->image('avatar.jpg', 90, 90),
        ])->assertOk()
            ->assertJsonStructure(['avatar_url']);

        $teacher->refresh();
        $this->assertSame('avatars/'.$teacher->id.'/avatar.jpg', $teacher->AvatarUrl);
        Storage::disk('public')->assertExists("avatars/{$teacher->id}/avatar.jpg");
    }

    public function test_notification_preferences_can_be_read_and_updated(): void
    {
        $teacher = User::create([
            'LoginName' => 'prefs-user@example.com',
            'Name' => 'Prefs User',
            'PSW' => password_hash('secret-123', PASSWORD_DEFAULT),
            'type' => 'T',
            'phone' => '0900000002',
        ]);
        $token = $this->issueToken($teacher->id);

        $this->withHeaders([
            'Authorization' => "Bearer {$token}",
            'Accept' => 'application/json',
        ])->putJson('/api/v1/me/notification-preferences', [
            'in_app_enabled' => true,
            'email_enabled' => true,
            'line_enabled' => false,
            'quiet_hours_start' => '23:00',
            'quiet_hours_end' => '07:00',
            'event_tuition' => true,
            'event_learning_review' => false,
            'event_attendance' => true,
            'event_system' => false,
        ])->assertOk()
            ->assertJsonPath('data.email_enabled', true)
            ->assertJsonPath('data.event_learning_review', false);

        $this->withHeaders([
            'Authorization' => "Bearer {$token}",
            'Accept' => 'application/json',
        ])->getJson('/api/v1/me/notification-preferences')
            ->assertOk()
            ->assertJsonPath('data.quiet_hours_start', '23:00')
            ->assertJsonPath('data.event_system', false);
    }

    public function test_security_endpoint_is_isolated_to_current_user(): void
    {
        $userA = User::create([
            'LoginName' => 'security-a@example.com',
            'Name' => 'Security A',
            'PSW' => password_hash('secret-123', PASSWORD_DEFAULT),
            'type' => 'T',
            'phone' => '0900000003',
        ]);
        $userB = User::create([
            'LoginName' => 'security-b@example.com',
            'Name' => 'Security B',
            'PSW' => password_hash('secret-456', PASSWORD_DEFAULT),
            'type' => 'D',
            'phone' => '0900000004',
        ]);

        $tokenA = $this->issueToken($userA->id);
        $tokenB = $this->issueToken($userB->id);
        $tokenAId = AuthToken::where('token', $tokenA)->value('id');
        $tokenBId = AuthToken::where('token', $tokenB)->value('id');

        UserLoginActivity::create([
            'user_id' => $userA->id,
            'login_at' => now()->subMinutes(5),
            'ip_address' => '10.0.0.1',
            'user_agent' => 'test-agent-a',
            'device_label' => 'Device A',
            'success' => true,
            'auth_token_id' => $tokenAId,
        ]);
        UserLoginActivity::create([
            'user_id' => $userB->id,
            'login_at' => now()->subMinutes(3),
            'ip_address' => '10.0.0.2',
            'user_agent' => 'test-agent-b',
            'device_label' => 'Device B',
            'success' => true,
            'auth_token_id' => $tokenBId,
        ]);

        $res = $this->withHeaders([
            'Authorization' => "Bearer {$tokenA}",
            'Accept' => 'application/json',
        ])->getJson('/api/v1/me/security');

        $res->assertOk();
        $recent = $res->json('data.recent_logins');
        $this->assertNotEmpty($recent);
        foreach ($recent as $item) {
            $this->assertSame('10.0.0.1', $item['ip_address']);
        }

        $sessions = $res->json('data.active_sessions');
        $this->assertNotEmpty($sessions);
        foreach ($sessions as $session) {
            $tokenOwner = AuthToken::where('id', $session['token_id'])->value('user_id');
            $this->assertSame($userA->id, (int) $tokenOwner);
        }
    }

    public function test_logout_other_sessions_keeps_current_token_only(): void
    {
        $user = User::create([
            'LoginName' => 'security-logout-others@example.com',
            'Name' => 'Security Logout Others',
            'PSW' => password_hash('secret-123', PASSWORD_DEFAULT),
            'type' => 'T',
            'phone' => '0900000006',
        ]);

        $currentToken = $this->issueToken($user->id);
        $otherToken = $this->issueToken($user->id);

        $this->assertDatabaseHas('auth_tokens', ['token' => $currentToken]);
        $this->assertDatabaseHas('auth_tokens', ['token' => $otherToken]);

        $this->withHeaders([
            'Authorization' => "Bearer {$currentToken}",
            'Accept' => 'application/json',
        ])->postJson('/api/v1/me/security/logout-others')
            ->assertOk()
            ->assertJsonPath('message', '已登出其他裝置')
            ->assertJsonPath('revoked_count', 1);

        $this->assertDatabaseHas('auth_tokens', ['token' => $currentToken]);
        $this->assertDatabaseMissing('auth_tokens', ['token' => $otherToken]);
    }

    public function test_password_update_still_requires_current_password(): void
    {
        $teacher = User::create([
            'LoginName' => 'password-check@example.com',
            'Name' => 'Password Check',
            'PSW' => password_hash('old-pass-111', PASSWORD_DEFAULT),
            'type' => 'T',
            'phone' => '0900000005',
        ]);
        $token = $this->issueToken($teacher->id);

        $this->withHeaders([
            'Authorization' => "Bearer {$token}",
            'Accept' => 'application/json',
        ])->putJson('/api/v1/me', [
            'password' => 'new-pass-111',
            'password_confirmation' => 'new-pass-111',
        ])->assertStatus(422)
            ->assertJsonPath('message', '修改密碼需先輸入目前密碼');
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
