<?php

namespace Tests\Feature;

use App\Http\Controllers\TeacherEligibilityController;
use App\Models\AuthToken;
use App\Models\User;
use App\Models\UserCampus;
use App\Services\TeacherEligibilityPolicy;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use ReflectionMethod;
use Tests\TestCase;

/**
 * Regression coverage for two Cursor Bugbot findings on PR #1773:
 * - storeSalaryProfile() 500s for super_admin without branch_id because
 *   fulltime_salary_profiles.branch_id was NOT NULL (fixed: now nullable).
 * - salaryProfilesByTeacher() picked an arbitrary row among same-day
 *   effective_from ties because it only sorted by effective_from
 *   (fixed: secondary orderBy('id') so the latest insert wins).
 */
class FulltimeSalaryProfileTest extends TestCase
{
    use RefreshDatabase;

    public function test_super_admin_can_set_salary_profile_without_branch_id(): void
    {
        $admin = $this->createSuperAdmin('fs-super@test.com');
        $teacherId = $this->createTeacher(1, 'fs-teacher-1@test.com');

        $response = $this->withHeaders([
            'Authorization' => "Bearer {$admin['token']}",
            'Accept' => 'application/json',
        ])->postJson('/api/v1/finance/teacher-eligibility/salary-profiles', [
            'teacher_id' => $teacherId,
            'base_salary' => 33000,
            'effective_from' => '2026-07-01',
        ]);

        $response->assertCreated();
        $this->assertDatabaseHas('fulltime_salary_profiles', [
            'teacher_id' => $teacherId,
            'branch_id' => null,
            'base_salary' => '33000.00',
        ]);
    }

    public function test_same_day_correction_uses_most_recently_created_profile(): void
    {
        $teacherId = $this->createTeacher(1, 'fs-teacher-2@test.com');

        DB::table('fulltime_salary_profiles')->insert([
            ['teacher_id' => $teacherId, 'branch_id' => null, 'base_salary' => 30000, 'effective_from' => '2026-08-01', 'status' => 'approved', 'created_at' => now(), 'updated_at' => now()],
            ['teacher_id' => $teacherId, 'branch_id' => null, 'base_salary' => 35000, 'effective_from' => '2026-08-01', 'status' => 'approved', 'created_at' => now(), 'updated_at' => now()],
        ]);

        $controller = new TeacherEligibilityController(
            new TeacherEligibilityPolicy(require __DIR__ . '/../../config/teacher_salary.php')
        );
        $reflection = new ReflectionMethod($controller, 'salaryProfilesByTeacher');
        $reflection->setAccessible(true);
        $result = $reflection->invoke($controller, [$teacherId], null, '2026-08-31');

        $this->assertSame(35000.0, $result[$teacherId], 'same-day correction must win over the first-inserted row, not an arbitrary DB row order');
    }

    // TD-078: director writes stay pending and never feed payroll until HQ approves.
    public function test_new_salary_profile_defaults_to_pending_and_is_excluded_from_payroll(): void
    {
        $director = $this->createDirector(1, 'fs-td078-dir-pending@test.com');
        $teacherId = $this->createTeacher(1, 'fs-td078-teacher1@test.com');

        $response = $this->withHeaders([
            'Authorization' => "Bearer {$director['token']}",
            'Accept' => 'application/json',
        ])->postJson('/api/v1/finance/teacher-eligibility/salary-profiles', [
            'teacher_id' => $teacherId,
            'branch_id' => 1,
            'base_salary' => 40000,
            'effective_from' => '2026-08-01',
        ]);

        $response->assertCreated();
        $this->assertDatabaseHas('fulltime_salary_profiles', [
            'teacher_id' => $teacherId,
            'base_salary' => '40000.00',
            'status' => 'pending',
        ]);

        $controller = new TeacherEligibilityController(
            new TeacherEligibilityPolicy(require __DIR__ . '/../../config/teacher_salary.php')
        );
        $reflection = new ReflectionMethod($controller, 'salaryProfilesByTeacher');
        $reflection->setAccessible(true);
        $result = $reflection->invoke($controller, [$teacherId], null, '2026-08-31');

        $this->assertArrayNotHasKey($teacherId, $result, '未核准的底薪不可計入發放總額');
    }

    public function test_super_admin_salary_write_is_approved_immediately(): void
    {
        $admin = $this->createSuperAdmin('fs-td078-super-direct@test.com');
        $teacherId = $this->createTeacher(1, 'fs-td078-teacher-direct@test.com');

        $this->withHeaders([
            'Authorization' => "Bearer {$admin['token']}",
            'Accept' => 'application/json',
        ])->postJson('/api/v1/finance/teacher-eligibility/salary-profiles', [
            'teacher_id' => $teacherId,
            'base_salary' => 41000,
            'effective_from' => '2026-08-01',
        ])->assertCreated();

        $this->assertDatabaseHas('fulltime_salary_profiles', [
            'teacher_id' => $teacherId,
            'base_salary' => '41000.00',
            'status' => 'approved',
            'approved_by' => $admin['user_id'],
        ]);

        $controller = new TeacherEligibilityController(
            new TeacherEligibilityPolicy(require __DIR__ . '/../../config/teacher_salary.php')
        );
        $reflection = new ReflectionMethod($controller, 'salaryProfilesByTeacher');
        $reflection->setAccessible(true);
        $result = $reflection->invoke($controller, [$teacherId], null, '2026-08-31');
        $this->assertSame(41000.0, $result[$teacherId]);
    }

    // TD-078: director who wrote it cannot also approve it — must be super_admin.
    public function test_director_cannot_approve_own_salary_profile(): void
    {
        $director = $this->createDirector(1, 'fs-td078-dir1@test.com');
        $teacherId = $this->createTeacher(1, 'fs-td078-teacher2@test.com');

        $profileId = DB::table('fulltime_salary_profiles')->insertGetId([
            'teacher_id' => $teacherId, 'branch_id' => 1, 'base_salary' => 32000,
            'effective_from' => '2026-08-01', 'status' => 'pending',
            'created_by' => $director['user_id'], 'created_at' => now(), 'updated_at' => now(),
        ]);

        $response = $this->withHeaders([
            'Authorization' => "Bearer {$director['token']}",
            'Accept' => 'application/json',
        ])->postJson("/api/v1/finance/teacher-eligibility/salary-profiles/{$profileId}/approve");

        $response->assertStatus(403);
        $this->assertDatabaseHas('fulltime_salary_profiles', ['id' => $profileId, 'status' => 'pending']);
    }

    // TD-078: super_admin approval activates the profile and it now feeds payroll.
    public function test_super_admin_approval_activates_salary_profile(): void
    {
        $admin = $this->createSuperAdmin('fs-td078-super2@test.com');
        $teacherId = $this->createTeacher(1, 'fs-td078-teacher3@test.com');

        $profileId = DB::table('fulltime_salary_profiles')->insertGetId([
            'teacher_id' => $teacherId, 'branch_id' => 1, 'base_salary' => 38000,
            'effective_from' => '2026-08-01', 'status' => 'pending',
            'created_at' => now(), 'updated_at' => now(),
        ]);

        $response = $this->withHeaders([
            'Authorization' => "Bearer {$admin['token']}",
            'Accept' => 'application/json',
        ])->postJson("/api/v1/finance/teacher-eligibility/salary-profiles/{$profileId}/approve");

        $response->assertOk();
        $this->assertDatabaseHas('fulltime_salary_profiles', [
            'id' => $profileId, 'status' => 'approved', 'approved_by' => $admin['user_id'],
        ]);

        // Approving twice must not be silently accepted.
        $again = $this->withHeaders([
            'Authorization' => "Bearer {$admin['token']}",
            'Accept' => 'application/json',
        ])->postJson("/api/v1/finance/teacher-eligibility/salary-profiles/{$profileId}/approve");
        $again->assertStatus(422);

        $controller = new TeacherEligibilityController(
            new TeacherEligibilityPolicy(require __DIR__ . '/../../config/teacher_salary.php')
        );
        $reflection = new ReflectionMethod($controller, 'salaryProfilesByTeacher');
        $reflection->setAccessible(true);
        $result = $reflection->invoke($controller, [$teacherId], null, '2026-08-31');

        $this->assertSame(38000.0, $result[$teacherId] ?? null, '核准後的底薪必須計入發放總額');
    }

    private function createDirector(int $campusId, string $email): array
    {
        $user = User::create([
            'LoginName' => $email, 'Name' => '主任', 'PSW' => 'secret',
            'type' => 'D', 'phone' => '0933888888', 'MustChangePassword' => false,
        ]);
        UserCampus::create(['CampusID' => $campusId, 'UserID' => $user->id, 'Admin' => 1, 'Approved' => 1]);
        $token = bin2hex(random_bytes(16));
        AuthToken::create(['user_id' => $user->id, 'token' => $token, 'expires_at' => now()->addDay()]);
        return ['token' => $token, 'user_id' => (int) $user->id];
    }

    private function createSuperAdmin(string $email): array
    {
        $user = User::create([
            'LoginName' => $email, 'Name' => 'SuperAdmin', 'PSW' => 'secret',
            'type' => 'S', 'phone' => '0911999999', 'MustChangePassword' => false,
        ]);
        $token = bin2hex(random_bytes(16));
        AuthToken::create(['user_id' => $user->id, 'token' => $token, 'expires_at' => now()->addDay()]);
        return ['token' => $token, 'user_id' => (int) $user->id];
    }

    private function createTeacher(int $campusId, string $email): int
    {
        $user = User::create([
            'LoginName' => $email, 'Name' => '正職老師', 'PSW' => 'secret',
            'type' => 'T', 'phone' => '0922111111', 'MustChangePassword' => false,
        ]);
        UserCampus::create(['CampusID' => $campusId, 'UserID' => $user->id, 'Admin' => 0, 'Approved' => 1]);
        return (int) $user->id;
    }
}
