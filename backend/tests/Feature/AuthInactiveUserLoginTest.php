<?php

namespace Tests\Feature;

use App\Models\User;
use App\Models\UserCampus;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AuthInactiveUserLoginTest extends TestCase
{
    use RefreshDatabase;

    public function test_inactive_user_cannot_log_in_even_when_login_name_matches(): void
    {
        $password = 'same-password';
        $hash = password_hash($password, PASSWORD_DEFAULT);

        User::create([
            'LoginName' => 'OLD_COCO',
            'Name' => 'Coco',
            'PSW' => $hash,
            'type' => 'T',
            'phone' => 900200001,
            'status' => 'inactive',
        ]);

        $active = User::create([
            'LoginName' => 'cocofeng0122',
            'Name' => 'Coco',
            'PSW' => $hash,
            'type' => 'T',
            'phone' => 900200002,
            'status' => 'active',
        ]);
        UserCampus::create([
            'UserID' => $active->id,
            'CampusID' => 1,
            'Admin' => 0,
            'Approved' => true,
        ]);

        // Same display name as active user; login matches Name case-insensitively — must pick active only.
        $this->postJson('/api/v1/auth/login', [
            'email' => 'coco',
            'password' => $password,
        ])
            ->assertOk()
            ->assertJsonPath('data.session.user.id', $active->id)
            ->assertJsonPath('data.session.user.account', 'cocofeng0122');
    }

    public function test_suspended_user_cannot_log_in(): void
    {
        $password = 'secret';
        User::create([
            'LoginName' => 'suspended@example.com',
            'Name' => 'Suspended',
            'PSW' => password_hash($password, PASSWORD_DEFAULT),
            'type' => 'T',
            'phone' => 900200003,
            'status' => 'suspended',
        ]);

        $this->postJson('/api/v1/auth/login', [
            'email' => 'suspended@example.com',
            'password' => $password,
        ])->assertStatus(401);
    }
}
