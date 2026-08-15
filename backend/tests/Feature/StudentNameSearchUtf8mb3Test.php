<?php

namespace Tests\Feature;

use App\Models\AuthToken;
use App\Models\Student;
use App\Models\User;
use App\Models\UserCampus;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * GitHub #1788 — Student.name is utf8mb3; LIKE '%蔡🏠%' threw SQLSTATE 1267.
 * Search must sanitize 4-byte chars and still match the CJK remainder.
 */
class StudentNameSearchUtf8mb3Test extends TestCase
{
    use RefreshDatabase;

    public function test_student_index_name_filter_accepts_emoji_suffix_without_500(): void
    {
        $token = $this->directorToken();
        Student::create([
            'name' => '蔡小明',
            'CampusID' => 11,
            'ClassID' => 1,
            'enable' => 1,
            'MDT' => now(),
            'Notify_Token' => '',
            'status' => 'active',
        ]);
        Student::create([
            'name' => '王小華',
            'CampusID' => 11,
            'ClassID' => 1,
            'enable' => 1,
            'MDT' => now(),
            'Notify_Token' => '',
            'status' => 'active',
        ]);

        $res = $this->withHeaders([
            'Authorization' => "Bearer {$token}",
            'Accept' => 'application/json',
        ])->getJson('/api/v1/students?campus_id=11&name=' . urlencode('蔡🏠') . '&status=active');

        $res->assertOk();
        $names = collect($res->json('data') ?? $res->json())->pluck('name')->all();
        $this->assertContains('蔡小明', $names);
        $this->assertNotContains('王小華', $names);
    }

    private function directorToken(): string
    {
        $user = User::create([
            'LoginName' => 'director-1788@example.com',
            'Name' => '主任測試',
            'PSW' => 'secret',
            'type' => 'A',
            'phone' => '0912345678',
            'MustChangePassword' => false,
        ]);
        UserCampus::create([
            'CampusID' => 11,
            'UserID' => $user->id,
            'Admin' => 1,
            'Approved' => 1,
        ]);
        $token = bin2hex(random_bytes(16));
        AuthToken::create([
            'user_id' => $user->id,
            'token' => $token,
            'expires_at' => now()->addDay(),
        ]);

        return $token;
    }
}
