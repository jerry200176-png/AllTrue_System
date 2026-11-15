<?php

namespace Tests\Feature;

use App\Models\AuthToken;
use App\Models\User;
use App\Models\UserCampus;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class FulltimeAdminAllowanceTest extends TestCase
{
    use RefreshDatabase;

    public function test_director_confirm_then_hq_approve_feeds_policy_path(): void
    {
        $director = $this->createDirector(1, 'aa-dir@test.com');
        $hq = $this->createSuperAdmin('aa-hq@test.com');
        $teacherId = $this->createTeacher(1, 'aa-teacher@test.com');

        $create = $this->withHeaders($this->auth($director['token']))->postJson('/api/v1/finance/teacher-eligibility/admin-allowances', [
            'teacher_id' => $teacherId,
            'branch_id' => 1,
            'role_key' => 'head_tutor',
            'rate' => 8,
            'reason' => '總導師職務',
            'starts_on' => '2026-08-01',
        ]);
        $create->assertCreated();
        $id = (int) $create->json('id');

        $this->withHeaders($this->auth($director['token']))
            ->postJson("/api/v1/finance/teacher-eligibility/admin-allowances/{$id}/approve")
            ->assertStatus(403);

        $this->withHeaders($this->auth($director['token']))
            ->postJson("/api/v1/finance/teacher-eligibility/admin-allowances/{$id}/confirm")
            ->assertOk();

        $this->withHeaders($this->auth($hq['token']))
            ->postJson("/api/v1/finance/teacher-eligibility/admin-allowances/{$id}/approve")
            ->assertOk();

        $this->assertDatabaseHas('teacher_payroll_admin_allowances', [
            'id' => $id,
            'status' => 'approved',
            'hq_approved_by' => $hq['user_id'],
        ]);
    }

    public function test_rate_above_ten_is_rejected(): void
    {
        $director = $this->createDirector(1, 'aa-dir2@test.com');
        $teacherId = $this->createTeacher(1, 'aa-teacher2@test.com');

        $this->withHeaders($this->auth($director['token']))->postJson('/api/v1/finance/teacher-eligibility/admin-allowances', [
            'teacher_id' => $teacherId,
            'branch_id' => 1,
            'role_key' => 'admin_assist',
            'rate' => 10.5,
            'reason' => '超標',
            'starts_on' => '2026-08-01',
        ])->assertStatus(422);
    }

    /** A rate adjustment needs a reason, both dates and a value inside the announced range (假日 10, 平日下午 5). */
    public function test_rate_adjustment_is_validated_and_shares_the_two_step_approval(): void
    {
        $director = $this->createDirector(1, 'aa-dir3@test.com');
        $hq = $this->createSuperAdmin('aa-hq3@test.com');
        $valid = [
            'teacher_id' => $this->createTeacher(1, 'aa-teacher3@test.com'), 'branch_id' => 1, 'role_key' => 'weekday_afternoon',
            'rate' => 4, 'reason' => '週三下午固定待命，課表上沒有課', 'starts_on' => '2026-08-01', 'ends_on' => '2026-08-31',
        ];
        $post = fn (array $override) => $this->withHeaders($this->auth($director['token']))
            ->postJson('/api/v1/finance/teacher-eligibility/admin-allowances', $override + $valid);

        $post(['rate' => 5.5])->assertStatus(422);
        $post(['reason' => ' '])->assertStatus(422);
        $post(['ends_on' => null])->assertStatus(422);
        $post(['role_key' => 'holiday_16h', 'rate' => 10.5])->assertStatus(422);
        $post(['role_key' => 'holiday_16h', 'rate' => 10])->assertCreated();

        $id = (int) $post([])->assertCreated()->json('id');
        $this->withHeaders($this->auth($director['token']))->postJson("/api/v1/finance/teacher-eligibility/admin-allowances/{$id}/confirm")->assertOk();
        $this->withHeaders($this->auth($hq['token']))->postJson("/api/v1/finance/teacher-eligibility/admin-allowances/{$id}/approve")->assertOk();
        $this->assertDatabaseHas('teacher_payroll_admin_allowances', ['id' => $id, 'role_key' => 'weekday_afternoon', 'status' => 'approved']);

        // Removing a confirmed or approved adjustment puts the system's rate back: headquarters only.
        $withdraw = fn (array $who, int $rowId) => $this->withHeaders($this->auth($who['token']))
            ->postJson("/api/v1/finance/teacher-eligibility/admin-allowances/{$rowId}/withdraw");
        $withdraw($director, $id)->assertStatus(403);
        $withdraw($hq, $id)->assertOk();
        $this->assertDatabaseHas('teacher_payroll_admin_allowances', ['id' => $id, 'status' => 'withdrawn']);

        $confirmedId = (int) $post([])->assertCreated()->json('id');
        $this->withHeaders($this->auth($director['token']))->postJson("/api/v1/finance/teacher-eligibility/admin-allowances/{$confirmedId}/confirm")->assertOk();
        $withdraw($director, $confirmedId)->assertStatus(403);
        $withdraw($hq, $confirmedId)->assertOk();
        // Withdrawn is final: the kept confirmation time must not let it be approved back into payroll.
        $this->withHeaders($this->auth($hq['token']))->postJson("/api/v1/finance/teacher-eligibility/admin-allowances/{$confirmedId}/approve")->assertStatus(422);
        $this->assertDatabaseHas('teacher_payroll_admin_allowances', ['id' => $confirmedId, 'status' => 'withdrawn']);

        // Nobody removes an adjustment on themselves, even before it is confirmed (it may be lowering their rate).
        $ownId = DB::table('teacher_payroll_admin_allowances')->insertGetId([
            'teacher_id' => $director['user_id'], 'branch_id' => 1, 'role_key' => 'holiday_16h', 'rate' => 0, 'reason' => 'r',
            'status' => 'pending', 'starts_on' => '2026-08-01', 'ends_on' => '2026-08-31', 'created_at' => now(), 'updated_at' => now(),
        ]);
        $withdraw($director, $ownId)->assertStatus(422)->assertJsonPath('code', 'self_approval_forbidden');
        $this->assertDatabaseHas('teacher_payroll_admin_allowances', ['id' => $ownId, 'status' => 'pending']);

        // An ordinary admin allowance keeps its rule: no withdrawal once confirmed.
        $roleId = (int) $post(['role_key' => 'head_tutor', 'ends_on' => null])->assertCreated()->json('id');
        $this->withHeaders($this->auth($director['token']))->postJson("/api/v1/finance/teacher-eligibility/admin-allowances/{$roleId}/confirm")->assertOk();
        $withdraw($hq, $roleId)->assertStatus(422);
    }

    private function auth(string $token): array
    {
        return ['Authorization' => "Bearer {$token}", 'Accept' => 'application/json'];
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
