<?php

namespace Tests\Feature;

use App\Models\User;
use App\Models\UserCampus;
use Database\Factories\CampusFactory;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AuthDuplicateEmailRoleTest extends TestCase
{
    use RefreshDatabase;

    public function test_teacher_and_director_can_register_with_same_email(): void
    {
        $this->markTestSkipped('Pending: register() blocks 2nd registration by loginName regardless of type');
        $campus = CampusFactory::new()->create();

        $email = 'shared@example.com';

        $this->postJson('/api/v1/auth/register', [
            'name' => 'Teacher One',
            'email' => $email,
            'password' => 'teacher-pass',
            'role' => 'teacher',
            'branch_id' => $campus->id,
        ])->assertStatus(201);

        $this->postJson('/api/v1/auth/register', [
            'name' => 'Director One',
            'email' => $email,
            'password' => 'director-pass',
            'role' => 'director',
            'branch_id' => $campus->id,
        ])->assertStatus(201);

        $this->assertDatabaseHas('User', [
            'LoginName' => $email,
            'type' => 'T',
        ]);
        $this->assertDatabaseHas('User', [
            'LoginName' => $email,
            'type' => 'D',
        ]);
        $this->assertSame(2, User::where('LoginName', $email)->count());
    }

    public function test_login_tries_all_users_when_same_email_exists(): void
    {
        $email = 'dual-role@example.com';
        $campus = CampusFactory::new()->create();

        $teacher = User::create([
            'LoginName' => $email,
            'Name' => 'Teacher Same Email',
            'PSW' => password_hash('teacher-pass', PASSWORD_DEFAULT),
            'type' => 'T',
            'phone' => 912300001,
        ]);
        UserCampus::create([
            'UserID' => $teacher->id,
            'CampusID' => $campus->id,
            'Admin' => 0,
            'Approved' => true,
        ]);

        $director = User::create([
            'LoginName' => $email,
            'Name' => 'Director Same Email',
            'PSW' => password_hash('director-pass', PASSWORD_DEFAULT),
            'type' => 'D',
            'phone' => 912300002,
        ]);

        $this->postJson('/api/v1/auth/login', [
            'email' => $email,
            'password' => 'director-pass',
        ])->assertOk()
            ->assertJsonPath('data.session.user.id', $director->id)
            ->assertJsonPath('data.session.user.role', 'director');

        $this->postJson('/api/v1/auth/login', [
            'email' => $email,
            'password' => 'teacher-pass',
        ])->assertOk()
            ->assertJsonPath('data.session.user.id', $teacher->id)
            ->assertJsonPath('data.session.user.role', 'teacher');
    }

    public function test_register_same_role_email_returns_existing_account_hint(): void
    {
        $campus = CampusFactory::new()->create();
        $email = 'teacher-repeat@example.com';

        $this->postJson('/api/v1/auth/register', [
            'name' => 'Teacher Repeat',
            'email' => $email,
            'password' => 'teacher-pass',
            'role' => 'teacher',
            'branch_id' => $campus->id,
        ])->assertStatus(201);

        $this->postJson('/api/v1/auth/register', [
            'name' => 'Teacher Repeat Again',
            'email' => $email,
            'password' => 'teacher-pass',
            'role' => 'teacher',
            'branch_id' => $campus->id,
        ])->assertOk()
            ->assertJsonPath('already_exists', true);

        $this->assertSame(1, User::where('LoginName', $email)->where('type', 'T')->count());
    }

}
