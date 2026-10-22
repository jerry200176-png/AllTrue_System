<?php

namespace Tests\Feature;

use App\Models\AuthToken;
use App\Models\Campus;
use App\Models\User;
use App\Models\UserCampus;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
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
        UserCampus::create(['CampusID' => $campusId, 'UserID' => $user->id, 'Admin' => 0, 'Approved' => 1]);
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

    public function test_store_and_destroy_refuse_targets_the_director_does_not_manage(): void
    {
        $campus  = Campus::factory()->create();
        $campus2 = Campus::factory()->create();
        $director = User::create(['LoginName' => 'tb-dir1-' . uniqid() . '@test.com', 'Name' => 'D1', 'PSW' => 'secret', 'type' => 'A', 'phone' => 912000006]);
        UserCampus::create(['CampusID' => $campus->id, 'UserID' => $director->id, 'Admin' => 1, 'Approved' => 1]);
        $token = bin2hex(random_bytes(16));
        AuthToken::create(['user_id' => $director->id, 'token' => $token, 'expires_at' => now()->addDay()]);
        $directorId = (int) $director->id;
        $otherDirector = User::create(['LoginName' => 'tb-dir2-' . uniqid() . '@test.com', 'Name' => 'D2', 'PSW' => 'secret', 'type' => 'A', 'phone' => 912000005]);
        UserCampus::create(['CampusID' => $campus->id, 'UserID' => $otherDirector->id, 'Admin' => 1, 'Approved' => 1]);
        $foreignTeacher = $this->makeTeacher($campus2->id);

        foreach ([$directorId, (int) $otherDirector->id, $foreignTeacher] as $target) {
            $this->withToken($token)->postJson('/api/v1/teacher_branches', [['teacher_id' => $target, 'branch_id' => $campus2->id]])->assertForbidden();
            $this->withToken($token)->postJson('/api/v1/teacher_branches', [['teacher_id' => $target, 'branch_id' => $campus->id]])->assertForbidden();
        }
        $this->assertDatabaseMissing('UserCampus', ['UserID' => $directorId, 'CampusID' => $campus2->id]);
        $this->assertDatabaseMissing('UserCampus', ['UserID' => $foreignTeacher, 'CampusID' => $campus->id]);

        $this->withToken($token)->deleteJson("/api/v1/teacher_branches?teacher_id={$otherDirector->id}")->assertForbidden();
        $this->withToken($token)->deleteJson("/api/v1/teacher_branches?teacher_id={$foreignTeacher}")->assertForbidden();
        $this->assertDatabaseHas('UserCampus', ['UserID' => $otherDirector->id, 'CampusID' => $campus->id]);
        $this->assertDatabaseHas('UserCampus', ['UserID' => $foreignTeacher, 'CampusID' => $campus2->id]);

        $listed = collect($this->withToken($token)->getJson('/api/v1/teacher_branches')->assertOk()->json())->pluck('branch_id')->unique()->values()->all();
        $this->assertSame([(int) $campus->id], array_map('intval', $listed));
    }

    /** A batch mixing a managed teacher with one the director does not manage is refused as a whole: no row is written. */
    public function test_a_mixed_batch_is_refused_without_partial_writes(): void
    {
        $campus  = Campus::factory()->create();
        $campus2 = Campus::factory()->create();
        $token   = $this->directorToken($campus->id);
        $managed = $this->makeTeacher($campus->id);
        $foreign = $this->makeTeacher($campus2->id);
        $rows = fn () => DB::table('UserCampus')->whereIn('UserID', [$managed, $foreign])->orderBy('UserID')->orderBy('CampusID')
            ->get(['UserID', 'CampusID', 'Approved', 'Admin'])->toArray();
        $before = $rows();

        $this->withToken($token)->postJson('/api/v1/teacher_branches', [
            ['teacher_id' => $managed, 'branch_id' => $campus2->id],
            ['teacher_id' => $foreign, 'branch_id' => $campus->id],
        ])->assertForbidden();

        $this->assertEquals($before, $rows(), 'the managed teacher is not assigned either');
    }

    /** A teacher linked to the director's campus only through legacy teacher_branches is still managed (Codex r4347865989). */
    public function test_store_accepts_a_teacher_linked_only_through_teacher_branches(): void
    {
        // No migration creates the legacy table, so the CI schema lacks it (same as ChatEnhancementTest FR-001);
        // creating it here would commit the RefreshDatabase transaction on MySQL.
        if (!Schema::hasTable('teacher_branches')) {
            $this->markTestSkipped('legacy teacher_branches table is not in the test schema');
        }
        $campus = Campus::factory()->create();
        $token  = $this->directorToken($campus->id);
        $legacy = User::create(['LoginName' => 'tb-legacy-' . uniqid() . '@test.com', 'Name' => 'Legacy', 'PSW' => 'secret', 'type' => 'T', 'phone' => 912000007]);
        DB::table('teacher_branches')->insert(['teacher_id' => $legacy->id, 'branch_id' => $campus->id]);

        $this->withToken($token)->postJson('/api/v1/teacher_branches', [['teacher_id' => $legacy->id, 'branch_id' => $campus->id]])->assertOk();
        $this->assertDatabaseHas('UserCampus', ['UserID' => $legacy->id, 'CampusID' => $campus->id]);
    }

    /** teacher_id[]=<victim> made (int) check User 1 while the delete matched the victim (security review, 2026-10-22). */
    public function test_destroy_refuses_a_teacher_id_array_without_deleting_anything(): void
    {
        $campus  = Campus::factory()->create();
        $campus2 = Campus::factory()->create();
        // The bypass needed User 1 to be a teacher the caller manages; set that up before any other user exists.
        if (!User::whereKey(1)->exists()) {
            DB::table('User')->insert(['id' => 1, 'LoginName' => 'tb-decoy-' . uniqid() . '@test.com', 'Name' => 'Decoy', 'PSW' => 'secret', 'type' => 'T', 'phone' => 912000008]);
        }
        DB::table('User')->where('id', 1)->update(['type' => 'T']);
        UserCampus::firstOrCreate(['UserID' => 1, 'CampusID' => $campus->id], ['Admin' => 0, 'Approved' => 1]);
        $token  = $this->directorToken($campus->id);
        $victim = User::create(['LoginName' => 'tb-victim-' . uniqid() . '@test.com', 'Name' => 'Victim', 'PSW' => 'secret', 'type' => 'A', 'phone' => 912000009]);
        UserCampus::create(['CampusID' => $campus2->id, 'UserID' => $victim->id, 'Admin' => 1, 'Approved' => 1]);
        $rows = fn () => DB::table('UserCampus')->whereIn('UserID', [1, $victim->id])->orderBy('UserID')->orderBy('CampusID')
            ->get(['UserID', 'CampusID', 'Approved', 'Admin'])->toArray();
        $before = $rows();

        $this->withToken($token)->deleteJson("/api/v1/teacher_branches?teacher_id[]={$victim->id}")->assertStatus(422);
        $this->withToken($token)->deleteJson("/api/v1/teacher_branches?teacher_id={$victim->id}x")->assertStatus(422);

        $this->assertEquals($before, $rows(), 'neither the victim nor User 1 lost a campus link');
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
