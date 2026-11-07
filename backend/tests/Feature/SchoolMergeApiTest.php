<?php

namespace Tests\Feature;

use App\Models\AuthToken;
use App\Models\SchoolExamPeriod;
use App\Models\Student;
use App\Models\User;
use App\Models\UserCampus;
use Database\Factories\CampusFactory;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/** 學校整理 merge + undo (#517). Fake school names only. */
class SchoolMergeApiTest extends TestCase
{
    use RefreshDatabase;

    private function staff(string $type, int $campus): string
    {
        $u = User::create([
            'LoginName' => $type . random_int(1, 999999) . '@example.com', 'Name' => 'Staff',
            'PSW' => 'secret', 'type' => $type, 'phone' => (string) random_int(900000000, 999999999),
            'MustChangePassword' => false,
        ]);
        UserCampus::create(['CampusID' => $campus, 'UserID' => $u->id, 'Admin' => $type === 'A' ? 1 : 0, 'Approved' => 1]);
        $t = bin2hex(random_bytes(16));
        AuthToken::create(['user_id' => $u->id, 'token' => $t, 'expires_at' => now()->addDay()]);

        return $t;
    }

    private function as(string $t)
    {
        return $this->withHeaders(['Authorization' => "Bearer $t", 'Accept' => 'application/json']);
    }

    private function student(int $campus, string $school): Student
    {
        return Student::create(['name' => 'S', 'CampusID' => $campus, 'ClassID' => 8, 'SchoolName' => $school, 'enable' => 1, 'MDT' => now()]);
    }

    private function period(int $campus, string $school, int $seq = 1): SchoolExamPeriod
    {
        return SchoolExamPeriod::create(['campus_id' => $campus, 'school_name' => $school, 'academic_year' => 115, 'semester' => 1,
            'seq' => $seq, 'label' => 'x', 'start_date' => '2026-10-20', 'end_date' => '2026-10-22', 'grade_key' => 'all']);
    }

    public function test_merge_renames_students_and_periods_then_undo_restores(): void
    {
        $a = (int) CampusFactory::new()->create()->id;
        $dir = $this->staff('A', $a);
        $s1 = $this->student($a, '在興國中');
        $s2 = $this->student($a, '再興國中');
        $keep = $this->student($a, '乙測國中');
        $p1 = $this->period($a, '在興國中', 1);
        $p2 = $this->period($a, '再興國中', 2);

        $res = $this->as($dir)->postJson('/api/v1/school-merge', ['campus_id' => $a, 'canonical' => '再興國中', 'variants' => ['在興國中', '再興國中']])
            ->assertCreated()->assertJsonPath('data.students_updated', 1)->assertJsonPath('data.periods_renamed', 1);
        $id = $res->json('data.request_id');
        $this->assertSame('再興國中', $s1->fresh()->SchoolName);
        $this->assertSame('乙測國中', $keep->fresh()->SchoolName);
        $this->assertSame('再興國中', $p1->fresh()->school_name);
        $this->as($dir)->getJson("/api/v1/school-merge/history?campus_id=$a")->assertOk()->assertJsonPath('data.0.request_id', $id);

        // Audit holds ids and school names only.
        $meta = (string) DB::table('security_audit_events')->where('correlation_id', $id)->value('metadata');
        $this->assertStringNotContainsString('"name"', $meta);

        $s2->update(['SchoolName' => '丙測國中']); // edited after merge: undo must not touch it
        $this->as($dir)->postJson("/api/v1/school-merge/$id/undo", ['campus_id' => $a])
            ->assertOk()->assertJsonPath('data.students_restored', 1)->assertJsonPath('data.periods_restored', 1);
        $this->assertSame('在興國中', $s1->fresh()->SchoolName);
        $this->assertSame('在興國中', $p1->fresh()->school_name);
        $this->assertSame('再興國中', $p2->fresh()->school_name);
        $this->as($dir)->postJson("/api/v1/school-merge/$id/undo", ['campus_id' => $a])->assertStatus(422);
        $this->as($dir)->getJson("/api/v1/school-merge/history?campus_id=$a")->assertJsonCount(0, 'data');
    }

    public function test_period_collision_is_skipped_not_overwritten(): void
    {
        $a = (int) CampusFactory::new()->create()->id;
        $dir = $this->staff('A', $a);
        $this->student($a, '在興國中');
        $p1 = $this->period($a, '在興國中', 1);
        $this->period($a, '再興國中', 1);
        $this->as($dir)->postJson('/api/v1/school-merge', ['campus_id' => $a, 'canonical' => '再興國中', 'variants' => ['在興國中']])
            ->assertCreated()->assertJsonPath('data.periods_skipped', 1);
        $this->assertSame('在興國中', $p1->fresh()->school_name);
    }

    public function test_director_only_and_campus_isolation(): void
    {
        $a = (int) CampusFactory::new()->create()->id;
        $b = (int) CampusFactory::new()->create()->id;
        $dir = $this->staff('A', $a);
        $teacher = $this->staff('T', $a);
        $otherDir = $this->staff('A', $b);
        $s = $this->student($a, '在興國中');
        $body = ['campus_id' => $a, 'canonical' => '再興國中', 'variants' => ['在興國中']];

        $this->as($teacher)->postJson('/api/v1/school-merge', $body)->assertForbidden();
        $this->as($otherDir)->postJson('/api/v1/school-merge', $body)->assertForbidden();
        $this->assertSame('在興國中', $s->fresh()->SchoolName);

        $id = $this->as($dir)->postJson('/api/v1/school-merge', $body)->assertCreated()->json('data.request_id');
        $this->as($teacher)->postJson("/api/v1/school-merge/$id/undo", ['campus_id' => $a])->assertForbidden();
        $this->as($otherDir)->postJson("/api/v1/school-merge/$id/undo", ['campus_id' => $b])->assertNotFound();
        $this->as($dir)->postJson('/api/v1/school-merge', ['campus_id' => $a, 'canonical' => '再興國中', 'variants' => ['不存在國中']])->assertStatus(422);
        $this->assertSame('再興國中', $s->fresh()->SchoolName);
    }

    public function test_undo_after_retention_is_refused(): void
    {
        $a = (int) CampusFactory::new()->create()->id;
        $dir = $this->staff('A', $a);
        $this->student($a, '在興國中');
        $id = $this->as($dir)->postJson('/api/v1/school-merge', ['campus_id' => $a, 'canonical' => '再興國中', 'variants' => ['在興國中']])->json('data.request_id');
        DB::table('security_audit_events')->where('correlation_id', $id)->update(['retention_until' => now()->subDay()]);
        $this->as($dir)->postJson("/api/v1/school-merge/$id/undo", ['campus_id' => $a])->assertStatus(422);
    }
}
