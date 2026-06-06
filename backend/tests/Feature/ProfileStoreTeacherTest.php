<?php

namespace Tests\Feature;

use App\Models\AuthToken;
use App\Models\User;
use App\Models\UserCampus;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class ProfileStoreTeacherTest extends TestCase
{
    use RefreshDatabase;

    public function test_director_can_create_teacher_with_account_and_subjects(): void
    {
        $director = User::create([
            'LoginName' => 'director-account',
            'Name' => 'Director',
            'PSW' => password_hash('secret-123', PASSWORD_DEFAULT),
            'type' => 'A',
            'phone' => '0900000000',
        ]);

        UserCampus::create([
            'CampusID' => 1,
            'UserID' => $director->id,
            'Admin' => 1,
            'Approved' => 1,
        ]);

        $subjectId = DB::table('Subject')->insertGetId([
            'School_id' => 1,
            'Grade_no' => 1,
            'Subject_Name' => 'Math',
        ]);

        $token = $this->issueToken($director->id);

        $response = $this->withHeaders([
            'Authorization' => "Bearer {$token}",
            'Accept' => 'application/json',
        ])->postJson('/api/v1/profiles', [
            'name' => '王老師',
            'account' => 'teacher001',
            'password' => 'teacher123',
            'role' => 'teacher',
            'campus_id' => 1,
            'branch_id' => 1,
            'subject_ids' => [$subjectId],
            'status' => 'active',
        ]);

        $response->assertStatus(201)
            ->assertJsonPath('username', '王老師');

        $teacherId = (int) $response->json('id');

        $this->assertDatabaseHas('User', [
            'id' => $teacherId,
            'LoginName' => 'teacher001',
            'Name' => '王老師',
            'type' => 'T',
        ]);

        $this->assertDatabaseHas('UserCampus', [
            'UserID' => $teacherId,
            'CampusID' => 1,
        ]);

        $this->assertDatabaseHas('teacher_subjects', [
            'teacher_id' => $teacherId,
            'subject_id' => $subjectId,
        ]);
    }

    public function test_subjects_endpoint_bootstraps_default_subjects_when_empty(): void
    {
        DB::table('Subject')->delete();

        $director = User::create([
            'LoginName' => 'director-subject-bootstrap',
            'Name' => 'Director',
            'PSW' => password_hash('secret-123', PASSWORD_DEFAULT),
            'type' => 'A',
            'phone' => '0900000001',
        ]);

        UserCampus::create([
            'CampusID' => 1,
            'UserID' => $director->id,
            'Admin' => 1,
            'Approved' => 1,
        ]);

        $this->assertSame(0, DB::table('Subject')->count());
        $token = $this->issueToken($director->id);

        $response = $this->withHeaders([
            'Authorization' => "Bearer {$token}",
            'Accept' => 'application/json',
        ])->getJson('/api/v1/subjects');

        $response->assertOk();
        // SubjectController::CANONICAL_SUBJECTS 有 8 個預設科目（國文、英文、數學、社會、理化、物理、化學、生物）
        $this->assertCount(8, $response->json());
        $this->assertSame(8, DB::table('Subject')->count());
    }

    public function test_director_can_update_teacher_account_via_profiles_api(): void
    {
        $director = User::create([
            'LoginName' => 'director-update-account',
            'Name' => 'Director',
            'PSW' => password_hash('secret-123', PASSWORD_DEFAULT),
            'type' => 'A',
            'phone' => '0900000002',
        ]);

        UserCampus::create([
            'CampusID' => 1,
            'UserID' => $director->id,
            'Admin' => 1,
            'Approved' => 1,
        ]);

        $teacher = User::create([
            'LoginName' => 'teacher-old-account',
            'Name' => '老師舊帳號',
            'PSW' => password_hash('teacher123', PASSWORD_DEFAULT),
            'type' => 'T',
            'status' => 'active',
            'phone' => '0900111222',
        ]);

        UserCampus::create([
            'CampusID' => 1,
            'UserID' => $teacher->id,
            'Admin' => 0,
            'Approved' => 1,
        ]);

        $token = $this->issueToken($director->id);

        $this->withHeaders([
            'Authorization' => "Bearer {$token}",
            'Accept' => 'application/json',
        ])->putJson("/api/v1/profiles/{$teacher->id}", [
            'account' => 'teacher-new-account',
            'username' => '老師新帳號',
        ])->assertOk()
            ->assertJsonPath('account', 'teacher-new-account')
            ->assertJsonPath('username', '老師新帳號');

        $this->assertDatabaseHas('User', [
            'id' => $teacher->id,
            'LoginName' => 'teacher-new-account',
            'Name' => '老師新帳號',
        ]);
    }

    public function test_director_can_reset_teacher_password_and_receive_temporary_password(): void
    {
        $director = User::create([
            'LoginName' => 'director-reset-pass',
            'Name' => 'Director',
            'PSW' => password_hash('secret-123', PASSWORD_DEFAULT),
            'type' => 'A',
            'phone' => '0900000003',
        ]);

        UserCampus::create([
            'CampusID' => 1,
            'UserID' => $director->id,
            'Admin' => 1,
            'Approved' => 1,
        ]);

        $teacher = User::create([
            'LoginName' => 'teacher-reset-target',
            'Name' => '重設測試老師',
            'PSW' => password_hash('old-pass', PASSWORD_DEFAULT),
            'type' => 'T',
            'status' => 'active',
            'phone' => '0900123000',
        ]);

        UserCampus::create([
            'CampusID' => 1,
            'UserID' => $teacher->id,
            'Admin' => 0,
            'Approved' => 1,
        ]);

        $token = $this->issueToken($director->id);
        $response = $this->withHeaders([
            'Authorization' => "Bearer {$token}",
            'Accept' => 'application/json',
        ])->postJson("/api/v1/profiles/{$teacher->id}/reset-password");

        $response->assertOk()
            ->assertJsonPath('id', $teacher->id)
            ->assertJsonPath('account', 'teacher-reset-target')
            ->assertJsonPath('must_change_password', true);

        $temporaryPassword = (string) $response->json('temporary_password');
        $this->assertNotSame('', $temporaryPassword);

        $teacher->refresh();
        $this->assertTrue(password_verify($temporaryPassword, (string) $teacher->PSW));

        if (Schema::hasColumn('User', 'MustChangePassword')) {
            $this->assertDatabaseHas('User', [
                'id' => $teacher->id,
                'MustChangePassword' => 1,
            ]);
        }
    }

    public function test_director_can_delete_teacher_without_dependency_records(): void
    {
        $director = User::create([
            'LoginName' => 'director-delete-teacher',
            'Name' => 'Director',
            'PSW' => password_hash('secret-123', PASSWORD_DEFAULT),
            'type' => 'A',
            'phone' => '0900000004',
        ]);
        UserCampus::create([
            'CampusID' => 1,
            'UserID' => $director->id,
            'Admin' => 1,
            'Approved' => 1,
        ]);

        $teacher = User::create([
            'LoginName' => 'teacher-delete-target',
            'Name' => '可刪除老師',
            'PSW' => password_hash('pass-1234', PASSWORD_DEFAULT),
            'type' => 'T',
            'status' => 'active',
            'phone' => '0900123001',
        ]);
        UserCampus::create([
            'CampusID' => 1,
            'UserID' => $teacher->id,
            'Admin' => 0,
            'Approved' => 1,
        ]);
        $token = $this->issueToken($director->id);
        $this->withHeaders([
            'Authorization' => "Bearer {$token}",
            'Accept' => 'application/json',
        ])->deleteJson("/api/v1/profiles/{$teacher->id}")
            ->assertOk()
            ->assertJsonPath('id', $teacher->id);

        $this->assertDatabaseMissing('User', ['id' => $teacher->id]);
        $this->assertDatabaseMissing('UserCampus', ['UserID' => $teacher->id]);
    }

    public function test_director_cannot_delete_teacher_with_attendance_dependency(): void
    {
        $director = User::create([
            'LoginName' => 'director-delete-blocked',
            'Name' => 'Director',
            'PSW' => password_hash('secret-123', PASSWORD_DEFAULT),
            'type' => 'A',
            'phone' => '0900000005',
        ]);
        UserCampus::create([
            'CampusID' => 1,
            'UserID' => $director->id,
            'Admin' => 1,
            'Approved' => 1,
        ]);

        $teacher = User::create([
            'LoginName' => 'teacher-delete-blocked',
            'Name' => '不可刪除老師',
            'PSW' => password_hash('pass-1234', PASSWORD_DEFAULT),
            'type' => 'T',
            'status' => 'active',
            'phone' => '0900123002',
        ]);
        UserCampus::create([
            'CampusID' => 1,
            'UserID' => $teacher->id,
            'Admin' => 0,
            'Approved' => 1,
        ]);
        if (Schema::hasTable('TeacherSingIn') && Schema::hasColumn('TeacherSingIn', 'TeacherID')) {
            DB::table('TeacherSingIn')->insert([
                'TeacherID' => $teacher->id,
                'CampusID' => 1,
                'SignInDT' => now(),
            ]);
        }

        $token = $this->issueToken($director->id);
        $this->withHeaders([
            'Authorization' => "Bearer {$token}",
            'Accept' => 'application/json',
        ])->deleteJson("/api/v1/profiles/{$teacher->id}")
            ->assertStatus(409)
            ->assertJsonStructure(['message', 'dependency_counts']);
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
