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
            ['teacher_id' => $teacherId, 'branch_id' => null, 'base_salary' => 30000, 'effective_from' => '2026-08-01', 'created_at' => now(), 'updated_at' => now()],
            ['teacher_id' => $teacherId, 'branch_id' => null, 'base_salary' => 35000, 'effective_from' => '2026-08-01', 'created_at' => now(), 'updated_at' => now()],
        ]);

        $controller = new TeacherEligibilityController(
            new TeacherEligibilityPolicy(require __DIR__ . '/../../config/teacher_salary.php')
        );
        $reflection = new ReflectionMethod($controller, 'salaryProfilesByTeacher');
        $reflection->setAccessible(true);
        $result = $reflection->invoke($controller, [$teacherId], null, '2026-08-31');

        $this->assertSame(35000.0, $result[$teacherId], 'same-day correction must win over the first-inserted row, not an arbitrary DB row order');
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
