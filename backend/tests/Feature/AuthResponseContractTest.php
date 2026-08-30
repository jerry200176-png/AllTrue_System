<?php

namespace Tests\Feature;

use App\Models\Campus;
use App\Models\TeacherSignIn;
use App\Models\User;
use App\Models\UserCampus;
use Database\Factories\CampusFactory;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AuthResponseContractTest extends TestCase
{
    use RefreshDatabase;

    public function test_login_returns_stable_session_and_user_contract(): void
    {
        $campus = CampusFactory::new()->create();
        $user = User::create([
            'LoginName' => 'auth-contract@example.com',
            'Name' => '登入契約測試老師',
            'PSW' => password_hash('correct-password', PASSWORD_DEFAULT),
            'type' => 'T',
            'status' => 'active',
            'phone' => '0912345678',
            'MustChangePassword' => false,
        ]);
        UserCampus::create([
            'CampusID' => $campus->id,
            'UserID' => $user->id,
            'Admin' => 0,
            'Approved' => true,
        ]);

        $response = $this->postJson('/api/v1/auth/login', [
            'email' => 'auth-contract@example.com',
            'password' => 'correct-password',
        ]);

        $response->assertOk()->assertJsonStructure([
            'data' => [
                'session' => [
                    'access_token',
                    'token_type',
                    'user' => [
                        'id', 'name', 'account', 'email', 'role', 'campuses',
                        'must_change_password',
                    ],
                ],
                'user' => [
                    'id', 'name', 'account', 'email', 'role', 'campuses',
                    'must_change_password',
                ],
            ],
        ]);

        $session = $response->json('data.session');
        $sessionUser = $session['user'];
        $userPayload = $response->json('data.user');

        $this->assertIsString($session['access_token']);
        $this->assertSame(64, strlen($session['access_token']));
        $this->assertSame('Bearer', $session['token_type']);
        $this->assertIsInt($sessionUser['id']);
        $this->assertSame($user->id, $sessionUser['id']);
        $this->assertSame('teacher', $sessionUser['role']);
        $this->assertSame([$campus->id], $sessionUser['campuses']);
        $this->assertIsBool($sessionUser['must_change_password']);
        $this->assertSame($sessionUser, $userPayload);
    }

    public function test_swipe_rfid_teacher_success_has_stable_attendance_contract(): void
    {
        $campus = CampusFactory::new()->create(['Token' => 'contract-campus-token']);
        $teacher = User::create([
            'LoginName' => 'rfid-contract@example.com',
            'Name' => '刷卡契約老師',
            'PSW' => password_hash('password', PASSWORD_DEFAULT),
            'type' => 'T',
            'status' => 'active',
        ]);
        UserCampus::create([
            'CampusID' => $campus->id,
            'UserID' => $teacher->id,
            'Admin' => 0,
            'Approved' => true,
            'RFID' => 'CONTRACT-RFID-001',
        ]);

        $response = $this->postJson('/api/v1/swipe-rfid', [
            'branch_code' => (string) $campus->id,
            'rfid' => 'CONTRACT-RFID-001',
        ], [
            'Authorization' => 'Bearer contract-campus-token',
        ]);

        $response->assertCreated()->assertJsonStructure([
            'ok', 'type', 'action',
            'record' => ['id', 'TeacherID', 'CampusID', 'SignInDT', 'Source', 'Status'],
            'teacher' => ['id', 'name'],
            'campus' => ['TelegramChatID', 'TelegramToken'],
        ])->assertJsonPath('ok', true)
            ->assertJsonPath('type', 'teacher')
            ->assertJsonPath('action', 'sign_in')
            ->assertJsonPath('teacher.id', $teacher->id)
            ->assertJsonPath('teacher.name', '刷卡契約老師');

        $record = $response->json('record');
        $this->assertIsInt($record['id']);
        $this->assertSame($teacher->id, $record['TeacherID']);
        $this->assertSame($campus->id, $record['CampusID']);
        $this->assertIsString($record['SignInDT']);
        $this->assertSame('rfid', $record['Source']);
        $this->assertIsString($record['Status']);
        $this->assertDatabaseHas('TeacherSingIn', ['id' => $record['id'], 'TeacherID' => $teacher->id]);
        $this->assertSame(1, TeacherSignIn::where('TeacherID', $teacher->id)->count());
    }

    public function test_swipe_rfid_unknown_card_returns_stable_error_contract(): void
    {
        $campus = CampusFactory::new()->create(['Token' => 'error-campus-token']);

        $response = $this->postJson('/api/v1/swipe-rfid', [
            'branch_code' => (string) $campus->id,
            'rfid' => 'UNKNOWN-CONTRACT-RFID',
        ], [
            'Authorization' => 'Bearer error-campus-token',
        ]);

        $response->assertNotFound()->assertJsonStructure([
            'ok', 'error', 'message',
            'campus' => ['TelegramToken'],
        ])->assertJsonPath('ok', false)
            ->assertJsonPath('error', 'rfid_not_found');

        $payload = $response->json();
        $this->assertIsBool($payload['ok']);
        $this->assertIsString($payload['error']);
        $this->assertIsString($payload['message']);
        $this->assertArrayHasKey('TelegramToken', $payload['campus']);
    }
}
