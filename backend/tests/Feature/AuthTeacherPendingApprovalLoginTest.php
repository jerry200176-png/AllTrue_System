<?php

namespace Tests\Feature;

use App\Models\User;
use App\Models\UserCampus;
use Database\Factories\CampusFactory;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AuthTeacherPendingApprovalLoginTest extends TestCase
{
    use RefreshDatabase;

    public function test_self_registered_teacher_cannot_login_until_director_releases_campus(): void
    {
        $campus = CampusFactory::new()->create();

        $this->postJson('/api/v1/auth/register', [
            'name' => 'Pending Teacher',
            'email' => 'pending-teacher-login@example.com',
            'password' => 'secret123',
            'role' => 'teacher',
            'branch_id' => $campus->id,
        ])->assertStatus(201);

        $this->postJson('/api/v1/auth/login', [
            'email' => 'pending-teacher-login@example.com',
            'password' => 'secret123',
        ])
            ->assertStatus(403)
            ->assertJsonPath('code', 'teacher_pending_approval')
            ->assertJsonFragment(['message' => '此帳號尚未通過主任審核，請聯繫分校主任完成審核後再行登入。']);
    }

    public function test_teacher_can_login_after_campus_marked_approved(): void
    {
        $password = 'ok-pass-99';
        $campus = CampusFactory::new()->create();
        $teacher = User::create([
            'LoginName' => 'approved-teacher@example.com',
            'Name' => 'Approved Teacher',
            'PSW' => password_hash($password, PASSWORD_DEFAULT),
            'type' => 'T',
            'phone' => 900300001,
            'status' => 'pending',
        ]);
        UserCampus::create([
            'UserID' => $teacher->id,
            'CampusID' => $campus->id,
            'Admin' => 0,
            'Approved' => true,
        ]);

        $this->postJson('/api/v1/auth/login', [
            'email' => 'approved-teacher@example.com',
            'password' => $password,
        ])
            ->assertOk()
            ->assertJsonPath('data.session.user.id', $teacher->id);
    }
}
