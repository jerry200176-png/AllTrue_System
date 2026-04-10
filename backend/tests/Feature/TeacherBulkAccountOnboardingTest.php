<?php

namespace Tests\Feature;

use App\Models\AuthToken;
use App\Models\User;
use App\Models\UserCampus;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class TeacherBulkAccountOnboardingTest extends TestCase
{
    use RefreshDatabase;

    public function test_director_can_bulk_create_teachers_with_partial_failures(): void
    {
        $campusId = $this->ensureCampusId();
        $subjectId = DB::table('Subject')->insertGetId([
            'School_id' => 1,
            'Grade_no' => 1,
            'Subject_Name' => 'Math',
        ]);

        $director = User::create([
            'LoginName' => 'director-bulk',
            'Name' => 'Bulk Director',
            'PSW' => password_hash('director-pass', PASSWORD_DEFAULT),
            'type' => 'A',
            'phone' => '0900000001',
        ]);

        UserCampus::create([
            'CampusID' => $campusId,
            'UserID' => $director->id,
            'Admin' => 1,
            'Approved' => 1,
        ]);

        User::create([
            'LoginName' => 'teacher-dup',
            'Name' => 'Duplicate Teacher',
            'PSW' => password_hash('secret-dup', PASSWORD_DEFAULT),
            'type' => 'T',
            'status' => 'active',
            'phone' => '0900999000',
        ]);

        $token = $this->issueToken($director->id);
        $response = $this->withHeaders([
            'Authorization' => "Bearer {$token}",
            'Accept' => 'application/json',
        ])->postJson('/api/v1/profiles/bulk-teachers', [
            'default_branch_id' => $campusId,
            'teachers' => [
                [
                    'name' => '王老師',
                    'account' => 'teacher-new-001',
                    'phone' => '0912-123-456',
                    'subject_ids' => [$subjectId],
                    'status' => 'active',
                ],
                [
                    'name' => '重複老師',
                    'account' => 'teacher-dup',
                    'status' => 'active',
                ],
            ],
        ]);

        $response->assertStatus(201)
            ->assertJsonPath('summary.total', 2)
            ->assertJsonPath('summary.created', 1)
            ->assertJsonPath('summary.failed', 1);

        $created = $response->json('created.0');
        $this->assertSame('teacher-new-001', $created['account']);
        $this->assertNotEmpty($created['initial_password']);
        $this->assertTrue((bool) $created['must_change_password']);

        $this->assertDatabaseHas('User', [
            'LoginName' => 'teacher-new-001',
            'Name' => '王老師',
            'type' => 'T',
            'MustChangePassword' => 1,
        ]);

        $teacherId = (int) DB::table('User')->where('LoginName', 'teacher-new-001')->value('id');
        $this->assertDatabaseHas('Teacher', [
            'id' => $teacherId,
            'CampusID' => $campusId,
        ]);
        $this->assertDatabaseHas('teacher_subjects', [
            'teacher_id' => $teacherId,
            'subject_id' => $subjectId,
        ]);

        $failed = $response->json('failed.0');
        $this->assertSame('teacher-dup', $failed['account']);
    }

    public function test_must_change_password_user_is_locked_until_password_updated(): void
    {
        $campusId = $this->ensureCampusId();
        $oldPassword = 'old-pass-123';

        $teacher = User::create([
            'LoginName' => 'teacher-lock-001',
            'Name' => 'Lock Teacher',
            'PSW' => password_hash($oldPassword, PASSWORD_DEFAULT),
            'type' => 'T',
            'status' => 'active',
            'phone' => '0900000002',
            'MustChangePassword' => true,
            'PasswordChangedAt' => null,
        ]);

        UserCampus::create([
            'CampusID' => $campusId,
            'UserID' => $teacher->id,
            'Admin' => 0,
            'Approved' => 1,
        ]);

        $token = $this->issueToken($teacher->id);

        $this->withHeaders([
            'Authorization' => "Bearer {$token}",
            'Accept' => 'application/json',
        ])->postJson('/api/v1/auth/login', [
            'account' => 'teacher-lock-001',
            'password' => $oldPassword,
            'role' => 'teacher',
        ])->assertOk()
            ->assertJsonPath('data.session.user.must_change_password', true);

        $this->withHeaders([
            'Authorization' => "Bearer {$token}",
            'Accept' => 'application/json',
        ])->getJson('/api/v1/subjects')
            ->assertStatus(428)
            ->assertJsonPath('code', 'PASSWORD_CHANGE_REQUIRED');

        $this->withHeaders([
            'Authorization' => "Bearer {$token}",
            'Accept' => 'application/json',
        ])->putJson('/api/v1/me', [
            'current_password' => $oldPassword,
            'password' => 'new-pass-456',
            'password_confirmation' => 'new-pass-456',
        ])->assertOk()
            ->assertJsonPath('must_change_password', false);

        $this->assertDatabaseHas('User', [
            'id' => $teacher->id,
            'MustChangePassword' => 0,
        ]);
        $this->assertNotNull(DB::table('User')->where('id', $teacher->id)->value('PasswordChangedAt'));

        $this->withHeaders([
            'Authorization' => "Bearer {$token}",
            'Accept' => 'application/json',
        ])->getJson('/api/v1/subjects')
            ->assertOk();
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

    private function ensureCampusId(): int
    {
        $existing = DB::table('Campus')->value('id');
        if ($existing) {
            return (int) $existing;
        }

        $row = ['name' => '測試分校'];
        $defaults = [
            'Current' => 0,
            'LineNotifyID' => '',
            'Client_ID' => '',
            'Client_Secret' => '',
            'LIFFID' => '',
            'LIFF_URL' => '',
            'URL' => '',
            'Token' => null,
            'TelegramToken' => null,
            'TelegramChatID' => null,
            'TelegramURL' => '',
            'TeachLIFFID' => '',
            'TeachLIFF_URL' => '',
            'code' => 'test-campus',
            'SwipeWindowMinutes' => 10,
        ];

        foreach ($defaults as $column => $value) {
            if (Schema::hasColumn('Campus', $column)) {
                $row[$column] = $value;
            }
        }

        return (int) DB::table('Campus')->insertGetId($row);
    }
}
