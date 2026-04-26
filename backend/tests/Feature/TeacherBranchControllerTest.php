<?php

namespace Tests\Feature;

use App\Models\AuthToken;
use App\Models\Campus;
use App\Models\User;
use App\Models\UserCampus;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class TeacherBranchControllerTest extends TestCase
{
    use RefreshDatabase;

    private function directorToken(int $campusId): string
    {
        $user = User::create([
            'LoginName' => 'tb-dir-' . uniqid() . '@test.com',
            'Name'      => 'TB Director',
            'PSW'       => 'secret',
            'type'      => 'A',
            'phone'     => 912000003,
        ]);
        UserCampus::create(['CampusID' => $campusId, 'UserID' => $user->id, 'Admin' => 1, 'Approved' => 1]);
        $raw = bin2hex(random_bytes(16));
        AuthToken::create(['user_id' => $user->id, 'token' => $raw, 'expires_at' => now()->addDay()]);
        return $raw;
    }

    private function makeTeacher(int $campusId): int
    {
        $user = User::create([
            'LoginName' => 'tb-teacher-' . uniqid() . '@test.com',
            'Name'      => 'TB Teacher',
            'PSW'       => 'secret',
            'type'      => 'T',
            'phone'     => 912000004,
        ]);
        DB::table('Teacher')->insert(['id' => $user->id, 'CampusID' => $campusId, 'T_Name' => 'TB Teacher']);
        return $user->id;
    }

    public function test_index_returns_user_campus_list(): void
    {
        $campus  = Campus::factory()->create();
        $token   = $this->directorToken($campus->id);

        $res = $this->withToken($token)->getJson("/api/v1/teacher_branches?branch_id={$campus->id}");
        $res->assertOk();
        $this->assertIsArray($res->json());
    }

    public function test_store_assigns_teacher_to_branch(): void
    {
        $campus    = Campus::factory()->create();
        $campus2   = Campus::factory()->create();
        $token     = $this->directorToken($campus->id);
        $teacherId = $this->makeTeacher($campus->id);

        $res = $this->withToken($token)->postJson('/api/v1/teacher_branches', [
            ['teacher_id' => $teacherId, 'branch_id' => $campus2->id],
        ]);
        $res->assertOk();
        $res->assertJson(['success' => true]);
        $this->assertDatabaseHas('UserCampus', ['UserID' => $teacherId, 'CampusID' => $campus2->id]);
    }

    public function test_store_empty_body_returns_success(): void
    {
        $campus = Campus::factory()->create();
        $token  = $this->directorToken($campus->id);

        $res = $this->withToken($token)->postJson('/api/v1/teacher_branches', []);
        $res->assertOk();
        $res->assertJson(['success' => true]);
    }

    public function test_store_updates_existing_assignment(): void
    {
        $campus    = Campus::factory()->create();
        $campus2   = Campus::factory()->create();
        $token     = $this->directorToken($campus->id);
        $teacherId = $this->makeTeacher($campus->id);

        // First assign
        DB::table('UserCampus')->insert(['UserID' => $teacherId, 'CampusID' => $campus2->id, 'Admin' => 1, 'Approved' => 0]);

        // Re-assign via API should update (not duplicate)
        $res = $this->withToken($token)->postJson('/api/v1/teacher_branches', [
            ['teacher_id' => $teacherId, 'branch_id' => $campus2->id],
        ]);
        $res->assertOk();
        $count = DB::table('UserCampus')->where('UserID', $teacherId)->where('CampusID', $campus2->id)->count();
        $this->assertEquals(1, $count);
    }

    public function test_destroy_removes_teacher_branches(): void
    {
        $campus    = Campus::factory()->create();
        $campus2   = Campus::factory()->create();
        $token     = $this->directorToken($campus->id);
        $teacherId = $this->makeTeacher($campus->id);
        DB::table('UserCampus')->insert(['UserID' => $teacherId, 'CampusID' => $campus2->id, 'Admin' => 0, 'Approved' => 1]);

        $res = $this->withToken($token)->deleteJson("/api/v1/teacher_branches?teacher_id={$teacherId}&branch_id={$campus->id}");
        $res->assertOk();
        $this->assertDatabaseMissing('UserCampus', ['UserID' => $teacherId, 'CampusID' => $campus2->id]);
    }
}
