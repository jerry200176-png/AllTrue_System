<?php

namespace Tests\Feature;

use App\Models\AuthToken;
use App\Models\Student;
use App\Models\User;
use App\Models\UserCampus;
use Database\Factories\CampusFactory;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/** 各校段考日期 API (#4970 step 1). Fake school names only. */
class SchoolExamPeriodApiTest extends TestCase
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

    private function student(int $campus, ?string $school, int $grade = 3, int $enable = 1): Student
    {
        return Student::create(['name' => 'S', 'CampusID' => $campus, 'ClassID' => $grade, 'SchoolName' => $school, 'enable' => $enable, 'MDT' => now()]);
    }

    private function payload(int $campus, array $o = []): array
    {
        return $o + ['campus_id' => $campus, 'school_name' => '甲測試國中', 'academic_year' => 115, 'semester' => 1,
            'seq' => 1, 'label' => '第一次段考', 'start_date' => '2026-10-20', 'end_date' => '2026-10-22'];
    }

    public function test_director_crud_teacher_read_only_and_campus_isolation(): void
    {
        $a = (int) CampusFactory::new()->create()->id;
        $b = (int) CampusFactory::new()->create()->id;
        $dir = $this->staff('A', $a);
        $teacher = $this->staff('T', $a);
        $otherDir = $this->staff('A', $b);

        $id = $this->as($dir)->postJson('/api/v1/exam-periods', $this->payload($a, ['grade_ids' => [3, 1]]))
            ->assertCreated()->assertJsonPath('data.grade_key', '1,3')->json('data.id');
        $this->as($dir)->postJson('/api/v1/exam-periods', $this->payload($a, ['grade_ids' => [1, 3]]))->assertStatus(422);
        $this->as($dir)->postJson('/api/v1/exam-periods', $this->payload($a))->assertCreated(); // all-grades scope differs

        $this->as($teacher)->postJson('/api/v1/exam-periods', $this->payload($a))->assertForbidden();
        $this->as($teacher)->patchJson("/api/v1/exam-periods/$id", ['label' => 'x'])->assertForbidden();
        $this->as($teacher)->deleteJson("/api/v1/exam-periods/$id")->assertForbidden();
        $this->as($teacher)->getJson("/api/v1/exam-periods?campus_id=$a&academic_year=115&semester=1")
            ->assertOk()->assertJsonCount(2, 'data');

        $this->as($otherDir)->getJson("/api/v1/exam-periods?campus_id=$a")->assertForbidden();
        $this->as($otherDir)->postJson('/api/v1/exam-periods', $this->payload($a))->assertForbidden();
        $this->as($otherDir)->patchJson("/api/v1/exam-periods/$id", ['label' => 'x'])->assertForbidden();
        $this->as($otherDir)->deleteJson("/api/v1/exam-periods/$id")->assertForbidden();

        $this->as($dir)->patchJson("/api/v1/exam-periods/$id", ['end_date' => '2026-10-25'])
            ->assertOk()->assertJsonPath('data.end_date', '2026-10-25');
        $this->as($dir)->patchJson("/api/v1/exam-periods/$id", ['campus_id' => $b])->assertStatus(422);
        $this->assertDatabaseHas('school_exam_periods', ['id' => $id, 'campus_id' => $a]);
        $this->as($dir)->deleteJson("/api/v1/exam-periods/$id")->assertOk();
        $this->assertDatabaseMissing('school_exam_periods', ['id' => $id]);
    }

    public function test_validation(): void
    {
        $a = (int) CampusFactory::new()->create()->id;
        $dir = $this->staff('A', $a);
        $this->as($dir)->postJson('/api/v1/exam-periods', $this->payload($a, ['end_date' => '2026-10-01']))->assertStatus(422);
        $this->as($dir)->postJson('/api/v1/exam-periods', $this->payload($a, ['seq' => 4]))->assertStatus(422);
        $this->as($dir)->postJson('/api/v1/exam-periods', $this->payload($a, ['semester' => 3]))->assertStatus(422);
        $id = $this->as($dir)->postJson('/api/v1/exam-periods', $this->payload($a))->json('data.id');
        $this->as($dir)->patchJson("/api/v1/exam-periods/$id", ['start_date' => '2026-12-01'])->assertStatus(422);
    }

    public function test_schools_groups_spelling_variants_without_student_names(): void
    {
        $a = (int) CampusFactory::new()->create()->id;
        $b = (int) CampusFactory::new()->create()->id;
        $dir = $this->staff('A', $a);
        $this->student($a, '甲測試國中');
        $this->student($a, '甲測試國民中學');
        $this->student($a, '乙測試 國中');
        $this->student($a, '甲測試國中', 3, 0); // inactive: not counted
        $this->student($a, null);
        $this->student($b, '丙測試國中');

        $res = $this->as($dir)->getJson("/api/v1/exam-periods/schools?campus_id=$a")->assertOk();
        $rows = collect($res->json('data'))->keyBy('school_name');
        $this->assertCount(2, $rows);
        $this->assertSame(2, $rows['甲測試國中']['student_count']);
        $this->assertEqualsCanonicalizing(['甲測試國中', '甲測試國民中學'], $rows['甲測試國中']['variants']);
        $this->assertStringNotContainsString('"name"', $res->getContent());
        $this->as($dir)->getJson("/api/v1/exam-periods/schools?campus_id=$b")->assertForbidden();
    }

    public function test_student_resolves_periods_for_current_term_school_and_grade(): void
    {
        $this->travelTo(now()->setDate(2026, 10, 1));
        $a = (int) CampusFactory::new()->create()->id;
        $dir = $this->staff('A', $a);
        $teacher = $this->staff('T', $a);
        $this->as($dir)->postJson('/api/v1/exam-periods', $this->payload($a))->assertCreated();
        $this->as($dir)->postJson('/api/v1/exam-periods', $this->payload($a, ['seq' => 2, 'label' => '第二次段考', 'grade_ids' => [9]]))->assertCreated();
        $this->as($dir)->postJson('/api/v1/exam-periods', $this->payload($a, ['semester' => 2]))->assertCreated();

        $s = $this->student($a, '甲測試國民中學', 3);
        $this->as($teacher)->getJson("/api/v1/students/{$s->id}/exam-periods")
            ->assertOk()->assertJsonPath('academic_year', 115)->assertJsonPath('semester', 1)
            ->assertJsonCount(1, 'data')->assertJsonPath('data.0.seq', 1);
        $none = $this->student($a, '無此學校', 3);
        $this->as($teacher)->getJson("/api/v1/students/{$none->id}/exam-periods")->assertOk()->assertJsonCount(0, 'data');
        $other = $this->student((int) CampusFactory::new()->create()->id, '甲測試國中');
        $this->as($teacher)->getJson("/api/v1/students/{$other->id}/exam-periods")->assertForbidden();
    }
}
