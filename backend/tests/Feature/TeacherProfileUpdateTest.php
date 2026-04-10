<?php

namespace Tests\Feature;

use App\Models\AuthToken;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class TeacherProfileUpdateTest extends TestCase
{
    use RefreshDatabase;

    public function test_teacher_can_update_basic_profile_fields(): void
    {
        $teacher = User::create([
            'LoginName' => 'teacher-profile@example.com',
            'Name' => 'Old Teacher',
            'PSW' => password_hash('old-pass-123', PASSWORD_DEFAULT),
            'type' => 'T',
            'phone' => '0900000000',
        ]);

        $token = $this->issueToken($teacher->id);

        $this->withHeaders([
            'Authorization' => "Bearer {$token}",
            'Accept' => 'application/json',
        ])->putJson('/api/v1/me', [
            'name' => 'New Teacher Name',
            'email' => 'teacher-new@example.com',
            'phone' => '0912345678',
        ])->assertOk()
            ->assertJsonPath('name', 'New Teacher Name')
            ->assertJsonPath('email', 'teacher-new@example.com')
            ->assertJsonPath('phone', '0912345678');

        $this->assertDatabaseHas('User', [
            'id' => $teacher->id,
            'Name' => 'New Teacher Name',
            'LoginName' => 'teacher-new@example.com',
            'phone' => '0912345678',
        ]);
    }

    public function test_update_password_requires_current_password(): void
    {
        $teacher = User::create([
            'LoginName' => 'teacher-no-current@example.com',
            'Name' => 'No Current',
            'PSW' => password_hash('old-pass-123', PASSWORD_DEFAULT),
            'type' => 'T',
            'phone' => '0900000001',
        ]);
        $token = $this->issueToken($teacher->id);

        $this->withHeaders([
            'Authorization' => "Bearer {$token}",
            'Accept' => 'application/json',
        ])->putJson('/api/v1/me', [
            'password' => 'new-pass-123',
            'password_confirmation' => 'new-pass-123',
        ])->assertStatus(422)
            ->assertJsonPath('message', '修改密碼需先輸入目前密碼');
    }

    public function test_update_password_rejects_wrong_current_password(): void
    {
        $teacher = User::create([
            'LoginName' => 'teacher-wrong-current@example.com',
            'Name' => 'Wrong Current',
            'PSW' => password_hash('old-pass-123', PASSWORD_DEFAULT),
            'type' => 'T',
            'phone' => '0900000002',
        ]);
        $token = $this->issueToken($teacher->id);

        $this->withHeaders([
            'Authorization' => "Bearer {$token}",
            'Accept' => 'application/json',
        ])->putJson('/api/v1/me', [
            'current_password' => 'not-correct',
            'password' => 'new-pass-123',
            'password_confirmation' => 'new-pass-123',
        ])->assertStatus(422)
            ->assertJsonPath('message', '目前密碼錯誤');
    }

    public function test_update_password_with_correct_current_password_succeeds(): void
    {
        $teacher = User::create([
            'LoginName' => 'teacher-correct-current@example.com',
            'Name' => 'Correct Current',
            'PSW' => password_hash('old-pass-123', PASSWORD_DEFAULT),
            'type' => 'T',
            'phone' => '0900000003',
        ]);
        $token = $this->issueToken($teacher->id);

        $this->withHeaders([
            'Authorization' => "Bearer {$token}",
            'Accept' => 'application/json',
        ])->putJson('/api/v1/me', [
            'current_password' => 'old-pass-123',
            'password' => 'new-pass-123',
            'password_confirmation' => 'new-pass-123',
        ])->assertOk()
            ->assertJsonPath('message', '已更新');

        $teacher->refresh();
        $this->assertTrue(password_verify('new-pass-123', $teacher->PSW));
    }

    public function test_update_email_returns_conflict_when_login_name_is_taken(): void
    {
        $teacher = User::create([
            'LoginName' => 'teacher-conflict@example.com',
            'Name' => 'Conflict Teacher',
            'PSW' => password_hash('old-pass-123', PASSWORD_DEFAULT),
            'type' => 'T',
            'phone' => '0900000004',
        ]);
        User::create([
            'LoginName' => 'taken@example.com',
            'Name' => 'Super Admin',
            'PSW' => password_hash('admin-pass-123', PASSWORD_DEFAULT),
            'type' => 'S',
            'phone' => '0900000005',
        ]);
        $token = $this->issueToken($teacher->id);

        $this->withHeaders([
            'Authorization' => "Bearer {$token}",
            'Accept' => 'application/json',
        ])->putJson('/api/v1/me', [
            'email' => 'taken@example.com',
        ])->assertStatus(409)
            ->assertJsonPath('message', '此帳號已存在');
    }

    public function test_director_can_still_update_password_when_current_password_is_provided(): void
    {
        $director = User::create([
            'LoginName' => 'director-profile@example.com',
            'Name' => 'Director User',
            'PSW' => password_hash('director-old-pass', PASSWORD_DEFAULT),
            'type' => 'D',
            'phone' => '0900000010',
        ]);
        $token = $this->issueToken($director->id);

        $this->withHeaders([
            'Authorization' => "Bearer {$token}",
            'Accept' => 'application/json',
        ])->putJson('/api/v1/me', [
            'current_password' => 'director-old-pass',
            'password' => 'director-new-pass',
            'password_confirmation' => 'director-new-pass',
        ])->assertOk()
            ->assertJsonPath('message', '已更新');

        $director->refresh();
        $this->assertTrue(password_verify('director-new-pass', $director->PSW));
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
