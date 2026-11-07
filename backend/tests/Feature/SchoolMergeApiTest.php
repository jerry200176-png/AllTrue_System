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

    public function test_whitespace_variants_alias_periods_and_many_variants(): void
    {
        $a = (int) CampusFactory::new()->create()->id;
        $dir = $this->staff('A', $a);
        $sp = $this->student($a, ' 乙測國中');
        $this->student($a, '乙測國中');
        // Period stored under the display (trimmed) spelling of a grade-suffix variant.
        $p = $this->period($a, '乙測國二', 1);
        $this->student($a, '乙測國二');
        $this->as($dir)->postJson('/api/v1/school-merge', ['campus_id' => $a, 'canonical' => '乙測國中',
            'variants' => array_merge([' 乙測國中', '乙測國二'], array_map(fn ($i) => "不存在{$i}國中", range(1, 40)))])
            ->assertCreated()->assertJsonPath('data.students_updated', 2)->assertJsonPath('data.periods_renamed', 1);
        $this->assertSame('乙測國中', $sp->fresh()->SchoolName);
        $this->assertSame('乙測國中', $p->fresh()->school_name);
    }

    public function test_undo_leaves_a_merged_student_edited_afterwards(): void
    {
        $a = (int) CampusFactory::new()->create()->id;
        $dir = $this->staff('A', $a);
        $s = $this->student($a, '在興國中');
        $id = $this->as($dir)->postJson('/api/v1/school-merge', ['campus_id' => $a, 'canonical' => '再興國中', 'variants' => ['在興國中']])->json('data.request_id');
        $s->update(['SchoolName' => '丁測國中']);
        $this->as($dir)->postJson("/api/v1/school-merge/$id/undo", ['campus_id' => $a])->assertStatus(409);
        $this->as($dir)->getJson("/api/v1/school-merge/history?campus_id=$a")->assertJsonCount(1, 'data'); // not burned
        $this->assertSame('丁測國中', $s->fresh()->SchoolName);
    }

    public function test_merge_and_undo_never_touch_another_campus(): void
    {
        $a = (int) CampusFactory::new()->create()->id;
        $b = (int) CampusFactory::new()->create()->id;
        $dir = $this->staff('A', $a);
        $sa = $this->student($a, '在興國中');
        $sb = $this->student($b, '在興國中');
        $pb = $this->period($b, '在興國中', 1);
        $id = $this->as($dir)->postJson('/api/v1/school-merge', ['campus_id' => $a, 'canonical' => '再興國中', 'variants' => ['在興國中']])
            ->assertCreated()->json('data.request_id');
        $this->assertSame('在興國中', $sb->fresh()->SchoolName);
        $this->assertSame('在興國中', $pb->fresh()->school_name);

        // Same canonical name now sits in campus B; undo for campus A must not "restore" it.
        $sb->update(['SchoolName' => '再興國中']);
        $pb->update(['school_name' => '再興國中']);
        DB::table('Student')->where('id', $sa->id)->update(['CampusID' => $b]); // forged/moved row in the payload
        $this->as($dir)->postJson("/api/v1/school-merge/$id/undo", ['campus_id' => $a])->assertStatus(409);
        $this->assertSame('再興國中', $sa->fresh()->SchoolName);
        $this->assertSame('再興國中', $sb->fresh()->SchoolName);
        $this->assertSame('再興國中', $pb->fresh()->school_name);
    }

    public function test_oversized_restore_payload_is_refused_and_nothing_is_written(): void
    {
        config(['audit.restore_payload_max' => 20]);
        $a = (int) CampusFactory::new()->create()->id;
        $dir = $this->staff('A', $a);
        $s = $this->student($a, '在興國中');
        $this->student($a, '在興國中');
        $this->as($dir)->postJson('/api/v1/school-merge', ['campus_id' => $a, 'canonical' => '再興國中', 'variants' => ['在興國中']])->assertStatus(422);
        $this->assertSame('在興國中', $s->fresh()->SchoolName);
        $this->assertSame(0, DB::table('security_audit_events')->where('event_type', 'school.merge.applied')->count());
    }

    public function test_shared_period_stays_when_a_student_outside_the_merge_uses_it(): void
    {
        $a = (int) CampusFactory::new()->create()->id;
        $dir = $this->staff('A', $a);
        // Two directory aliases of one school share a period (stored under the directory's full name).
        $this->student($a, '台北市大安國中');
        $this->student($a, '大安國中'); // not selected: still needs the period
        $p = $this->period($a, '臺北市立大安國民中學', 1);
        $this->student($a, '甲測國中');
        $res = $this->as($dir)->postJson('/api/v1/school-merge', ['campus_id' => $a, 'canonical' => '甲測國中', 'variants' => ['台北市大安國中']])->assertCreated();
        $this->assertSame(1, $res->json('data.periods_skipped'));
        $this->assertSame(0, $res->json('data.periods_renamed'));
        $this->assertSame('臺北市立大安國民中學', $p->fresh()->school_name);
    }

    public function test_shared_period_stays_for_ideographic_space_spelling(): void
    {
        $a = (int) CampusFactory::new()->create()->id;
        $dir = $this->staff('A', $a);
        $this->student($a, '台北市大安國中');
        $this->student($a, "\u{3000}大安國中"); // U+3000 prefix: SQL folds it with a plain space, but it is not selected
        $p = $this->period($a, '臺北市立大安國民中學', 1);
        $this->student($a, '甲測國中');
        $res = $this->as($dir)->postJson('/api/v1/school-merge', ['campus_id' => $a, 'canonical' => '甲測國中', 'variants' => ['台北市大安國中']])->assertCreated();
        $this->assertSame(1, $res->json('data.periods_skipped'));
        $this->assertSame('臺北市立大安國民中學', $p->fresh()->school_name);
    }

    public function test_undo_must_go_newest_first_when_merges_overlap(): void
    {
        $a = (int) CampusFactory::new()->create()->id;
        $dir = $this->staff('A', $a);
        $s = $this->student($a, '甲測國中');
        $first = $this->as($dir)->postJson('/api/v1/school-merge', ['campus_id' => $a, 'canonical' => '乙測國中', 'variants' => ['甲測國中']])->json('data.request_id');
        $second = $this->as($dir)->postJson('/api/v1/school-merge', ['campus_id' => $a, 'canonical' => '丙測國中', 'variants' => ['乙測國中']])->json('data.request_id');

        $this->as($dir)->postJson("/api/v1/school-merge/$first/undo", ['campus_id' => $a])
            ->assertStatus(409)->assertJsonPath('message', '請先復原較新的合併');
        $this->assertSame('丙測國中', $s->fresh()->SchoolName);
        $this->as($dir)->getJson("/api/v1/school-merge/history?campus_id=$a")->assertJsonCount(2, 'data'); // first not consumed

        $this->as($dir)->postJson("/api/v1/school-merge/$second/undo", ['campus_id' => $a])->assertOk();
        $this->as($dir)->postJson("/api/v1/school-merge/$first/undo", ['campus_id' => $a])->assertOk()->assertJsonPath('data.students_restored', 1);
        $this->assertSame('甲測國中', $s->fresh()->SchoolName);
    }

    public function test_merge_and_undo_lock_the_campus_row_before_reading_anything(): void
    {
        $a = (int) CampusFactory::new()->create()->id;
        $dir = $this->staff('A', $a);
        $this->student($a, '甲測國中');
        $first = null;
        $log = [];
        DB::listen(function ($q) use (&$log) {
            $log[] = $q->sql;
        });
        $id = $this->as($dir)->postJson('/api/v1/school-merge', ['campus_id' => $a, 'canonical' => '乙測國中', 'variants' => ['甲測國中']])->json('data.request_id');
        $this->assertLockFirst($log);
        $log = [];
        $this->as($dir)->postJson("/api/v1/school-merge/$id/undo", ['campus_id' => $a])->assertOk();
        $this->assertLockFirst($log);
    }

    /** After BEGIN, the first data read must be the campus row FOR UPDATE. */
    private function assertLockFirst(array $log): void
    {
        $reads = array_values(array_filter($log, fn ($q) => stripos($q, 'select') === 0 && stripos($q, 'AuthToken') === false
            && stripos($q, 'auth_tokens') === false && stripos($q, 'User') === false));
        $i = null;
        foreach ($reads as $k => $q) {
            if (stripos($q, 'for update') !== false) {
                $i = $k;
                $this->assertStringContainsString('Campus', $q);
                $this->assertStringNotContainsString('security_audit_events', $q);
                break;
            }
        }
        $this->assertNotNull($i, 'no FOR UPDATE read');
        $this->assertSame(0, $i, 'campus lock must be the first locking read: ' . implode(' | ', array_slice($reads, 0, 3)));
    }
}
