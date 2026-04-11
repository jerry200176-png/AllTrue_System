<?php

namespace Tests\Feature;

use App\Models\AuthToken;
use App\Models\User;
use App\Models\UserCampus;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class SubjectApiTest extends TestCase
{
    use RefreshDatabase;

    private function makeDirector(int $campusId, string $suffix = ''): array
    {
        $user = User::create([
            'LoginName' => "director{$suffix}@test",
            'Name' => "Director{$suffix}",
            'PSW' => password_hash('secret', PASSWORD_DEFAULT),
            'type' => 'A',
            'phone' => '0900' . str_pad($campusId, 6, '0'),
        ]);
        UserCampus::create(['CampusID' => $campusId, 'UserID' => $user->id, 'Admin' => 1, 'Approved' => 1]);
        $token = bin2hex(random_bytes(16));
        AuthToken::create(['user_id' => $user->id, 'token' => $token, 'expires_at' => now()->addDay()]);
        return [$user, $token];
    }

    private function makeTeacher(int $campusId): array
    {
        $user = User::create([
            'LoginName' => 'teacher@test',
            'Name' => 'Teacher',
            'PSW' => password_hash('secret', PASSWORD_DEFAULT),
            'type' => 'T',
            'phone' => '0911000000',
        ]);
        UserCampus::create(['CampusID' => $campusId, 'UserID' => $user->id, 'Admin' => 0, 'Approved' => 1]);
        $token = bin2hex(random_bytes(16));
        AuthToken::create(['user_id' => $user->id, 'token' => $token, 'expires_at' => now()->addDay()]);
        return [$user, $token];
    }

    private function makeSuperAdmin(): array
    {
        $user = User::create([
            'LoginName' => 'super@test',
            'Name' => 'Super',
            'PSW' => password_hash('secret', PASSWORD_DEFAULT),
            'type' => 'S',
            'phone' => '0922000000',
        ]);
        $token = bin2hex(random_bytes(16));
        AuthToken::create(['user_id' => $user->id, 'token' => $token, 'expires_at' => now()->addDay()]);
        return [$user, $token];
    }

    private function auth(string $token): array
    {
        return ['Authorization' => "Bearer {$token}", 'Accept' => 'application/json'];
    }

    public function test_bootstrap_seeds_canonical_subjects(): void
    {
        DB::table('Subject')->delete();

        [$dir, $tok] = $this->makeDirector(1);
        $this->assertSame(0, DB::table('Subject')->count());

        $res = $this->withHeaders($this->auth($tok))->getJson('/api/v1/subjects');
        $res->assertOk();
        $names = collect($res->json())->pluck('name')->sort()->values()->all();
        $this->assertContains('國文', $names);
        $this->assertContains('英文', $names);
        $this->assertContains('數學', $names);
        $this->assertTrue(DB::table('Subject')->count() >= 7);
    }

    public function test_director_can_create_subject(): void
    {
        [$dir, $tok] = $this->makeDirector(1);

        $res = $this->withHeaders($this->auth($tok))
            ->postJson('/api/v1/subjects', ['name' => '程式設計', 'branch_id' => 1]);

        $res->assertStatus(201)
            ->assertJsonPath('name', '程式設計')
            ->assertJsonPath('campus_id', 1);

        $this->assertDatabaseHas('Subject', ['Subject_Name' => '程式設計', 'CampusID' => 1]);
    }

    public function test_teacher_cannot_create_subject(): void
    {
        [$t, $tok] = $this->makeTeacher(1);

        $res = $this->withHeaders($this->auth($tok))
            ->postJson('/api/v1/subjects', ['name' => '新科目']);

        $this->assertContains($res->status(), [401, 403]);
    }

    public function test_teacher_cannot_delete_subject(): void
    {
        [$t, $tok] = $this->makeTeacher(1);
        $id = DB::table('Subject')->insertGetId([
            'School_id' => 0, 'Grade_no' => 0, 'Subject_Name' => '臨時科目', 'CampusID' => 1,
        ]);

        $res = $this->withHeaders($this->auth($tok))
            ->deleteJson("/api/v1/subjects/{$id}");

        $this->assertContains($res->status(), [401, 403]);
        $this->assertDatabaseHas('Subject', ['id' => $id]);
    }

    public function test_director_can_rename_subject(): void
    {
        [$dir, $tok] = $this->makeDirector(1);
        $id = DB::table('Subject')->insertGetId([
            'School_id' => 0, 'Grade_no' => 0, 'Subject_Name' => '舊名稱', 'CampusID' => 1,
        ]);

        $res = $this->withHeaders($this->auth($tok))
            ->putJson("/api/v1/subjects/{$id}", ['name' => '新名稱']);

        $res->assertOk()->assertJsonPath('name', '新名稱');
        $this->assertDatabaseHas('Subject', ['id' => $id, 'Subject_Name' => '新名稱']);
    }

    public function test_rename_rejects_duplicate_name(): void
    {
        [$dir, $tok] = $this->makeDirector(1);
        DB::table('Subject')->insert([
            ['School_id' => 0, 'Grade_no' => 0, 'Subject_Name' => '科目A', 'CampusID' => 1],
            ['School_id' => 0, 'Grade_no' => 0, 'Subject_Name' => '科目B', 'CampusID' => 1],
        ]);
        $idB = DB::table('Subject')->where('Subject_Name', '科目B')->value('id');

        $res = $this->withHeaders($this->auth($tok))
            ->putJson("/api/v1/subjects/{$idB}", ['name' => '科目A']);

        $res->assertStatus(409);
    }

    public function test_delete_prevents_in_use_subject(): void
    {
        [$dir, $tok] = $this->makeDirector(1);
        $subId = DB::table('Subject')->insertGetId([
            'School_id' => 0, 'Grade_no' => 0, 'Subject_Name' => '使用中', 'CampusID' => 1,
        ]);
        DB::table('StudentClass')->insert([
            'StudentID' => 1, 'SubjectID' => $subId, 'GradeID' => 1, 'TeacherID' => 1,
            'by1' => 1, 'StartDate' => now(), 'TotalHours' => 0, 'RoomID' => '',
        ]);

        $res = $this->withHeaders($this->auth($tok))
            ->deleteJson("/api/v1/subjects/{$subId}");

        $res->assertStatus(409);
        $this->assertDatabaseHas('Subject', ['id' => $subId]);
    }

    public function test_director_cannot_delete_shared_subject(): void
    {
        [$dir, $tok] = $this->makeDirector(1);
        $id = DB::table('Subject')->insertGetId([
            'School_id' => 0, 'Grade_no' => 0, 'Subject_Name' => '共用科目', 'CampusID' => null,
        ]);

        $res = $this->withHeaders($this->auth($tok))
            ->deleteJson("/api/v1/subjects/{$id}");

        $res->assertStatus(403);
        $this->assertDatabaseHas('Subject', ['id' => $id]);
    }

    public function test_super_admin_can_delete_shared_subject(): void
    {
        [$sa, $tok] = $this->makeSuperAdmin();
        $id = DB::table('Subject')->insertGetId([
            'School_id' => 0, 'Grade_no' => 0, 'Subject_Name' => '可刪共用', 'CampusID' => null,
        ]);

        $res = $this->withHeaders($this->auth($tok))
            ->deleteJson("/api/v1/subjects/{$id}");

        $res->assertStatus(204);
        $this->assertDatabaseMissing('Subject', ['id' => $id]);
    }

    public function test_campus_isolation_subjects_not_visible_across_branches(): void
    {
        [$dir1, $tok1] = $this->makeDirector(1, '-c1');
        [$dir2, $tok2] = $this->makeDirector(2, '-c2');

        DB::table('Subject')->insert([
            ['School_id' => 0, 'Grade_no' => 0, 'Subject_Name' => '共用科', 'CampusID' => null],
            ['School_id' => 0, 'Grade_no' => 0, 'Subject_Name' => '分校1專屬', 'CampusID' => 1],
            ['School_id' => 0, 'Grade_no' => 0, 'Subject_Name' => '分校2專屬', 'CampusID' => 2],
        ]);

        $res1 = $this->withHeaders($this->auth($tok1))->getJson('/api/v1/subjects?branch_id=1');
        $res1->assertOk();
        $names1 = collect($res1->json())->pluck('name')->all();
        $this->assertContains('共用科', $names1);
        $this->assertContains('分校1專屬', $names1);
        $this->assertNotContains('分校2專屬', $names1);

        $res2 = $this->withHeaders($this->auth($tok2))->getJson('/api/v1/subjects?branch_id=2');
        $res2->assertOk();
        $names2 = collect($res2->json())->pluck('name')->all();
        $this->assertContains('共用科', $names2);
        $this->assertContains('分校2專屬', $names2);
        $this->assertNotContains('分校1專屬', $names2);
    }

    public function test_public_endpoint_returns_shared_subjects(): void
    {
        DB::table('Subject')->insert([
            ['School_id' => 0, 'Grade_no' => 0, 'Subject_Name' => '公開科', 'CampusID' => null],
            ['School_id' => 0, 'Grade_no' => 0, 'Subject_Name' => '私有科', 'CampusID' => 1],
        ]);

        $res = $this->getJson('/api/v1/subjects-public');
        $res->assertOk();
        $names = collect($res->json())->pluck('name')->all();
        $this->assertContains('公開科', $names);
    }

    public function test_director_can_delete_campus_subject(): void
    {
        [$dir, $tok] = $this->makeDirector(1);
        $id = DB::table('Subject')->insertGetId([
            'School_id' => 0, 'Grade_no' => 0, 'Subject_Name' => '可刪分校科', 'CampusID' => 1,
        ]);

        $res = $this->withHeaders($this->auth($tok))
            ->deleteJson("/api/v1/subjects/{$id}");

        $res->assertStatus(204);
        $this->assertDatabaseMissing('Subject', ['id' => $id]);
    }
}
