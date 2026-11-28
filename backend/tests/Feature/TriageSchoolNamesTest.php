<?php

namespace Tests\Feature;

use App\Models\SchoolExamPeriod;
use App\Models\Student;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/** Founder GO 2026-11-27: a read-only triage token can read the school names and head counts of one campus. CI only. */
final class TriageSchoolNamesTest extends TestCase
{
    use RefreshDatabase;

    private const URL = '/api/v1/triage/school-names?campus_id=1';

    private User $admin;

    private string $token;

    protected function setUp(): void
    {
        parent::setUp();
        $this->admin = User::create(['LoginName' => 'school-triage-admin@test.com', 'Name' => 'Triage Admin', 'PSW' => 'secret', 'type' => 'S', 'phone' => '0910000902']);
        $this->token = (string) $this->postJson('/api/v1/admin/triage-tokens', ['name' => 'ci'], ['X-User-Id' => (string) $this->admin->id, 'Accept' => 'application/json'])->assertCreated()->json('token');
    }

    public function test_school_names_and_counts_leave_and_anything_else_is_only_counted(): void
    {
        $student = fn (string $name, ?string $school, int $campus = 1, int $enable = 1) => Student::create([
            'name' => $name, 'CampusID' => $campus, 'ClassID' => 1, 'enable' => $enable, 'MDT' => now(), 'Notify_Token' => '', 'SchoolName' => $school,
        ]);
        $student('學生甲', '測試國中');
        $student('學生乙', '測試國中');
        $student('學生寅', ' 測試國中 '); // same school with stray spaces
        $student('學生丙', '另一國小');
        $student('學生壬', '另一國小');
        $student('學生癸', '王小明國中'); // reads like a school, but only one student typed it
        $student('學生子', '很長很長很長很長很長的名字國中');
        $student('學生丑', '很長很長很長很長很長的名字國中');
        $student('學生丁', '王小明的媽媽 0912345678'); // typed text that is not a school
        $student('學生戊', 'ABC School');
        $student('學生己', '');
        $student('學生庚', '停用國中', 1, 0);
        $student('學生辛', '別校國中', 2);
        SchoolExamPeriod::create(['campus_id' => 1, 'school_name' => '測試國中', 'academic_year' => 115, 'semester' => 1, 'seq' => 2,
            'label' => '打字的備註', 'start_date' => '2026-11-25', 'end_date' => '2026-11-26', 'grade_ids' => [7], 'grade_key' => '7']);
        SchoolExamPeriod::create(['campus_id' => 2, 'school_name' => '別校國中', 'academic_year' => 115, 'semester' => 1, 'seq' => 1,
            'label' => 'x', 'start_date' => '2026-10-01', 'end_date' => '2026-10-02', 'grade_key' => '']);

        $json = $this->getJson(self::URL, $this->bearer())->assertOk()->json();

        $this->assertSame(['campus_id', 'schools', 'withheld_student_count', 'periods'], array_keys($json));
        $this->assertSame([['school_name' => '測試國中', 'student_count' => 3], ['school_name' => '另一國小', 'student_count' => 2]], $json['schools']);
        $this->assertSame(5, $json['withheld_student_count']);
        $this->assertCount(1, $json['periods']);
        $this->assertSame(['school_name' => '測試國中', 'academic_year' => 115, 'semester' => 1, 'seq' => 2,
            'start_date' => '2026-11-25', 'end_date' => '2026-11-26', 'grade_ids' => [7]], array_diff_key($json['periods'][0], ['id' => 0]));
        $body = (string) json_encode($json, JSON_UNESCAPED_UNICODE);
        foreach (['學生', '王小明', '0912345678', 'ABC', '停用', '別校', '備註', '很長'] as $forbidden) {
            $this->assertStringNotContainsString($forbidden, $body);
        }
    }

    public function test_needs_a_triage_token_and_a_campus_takes_no_writes_and_has_a_switch(): void
    {
        $this->getJson(self::URL)->assertUnauthorized();
        $this->getJson(self::URL, ['X-User-Id' => (string) $this->admin->id, 'Accept' => 'application/json'])->assertUnauthorized();
        $this->getJson('/api/v1/triage/school-names', $this->bearer())->assertStatus(422);
        $this->getJson('/api/v1/triage/school-names?campus_id=abc', $this->bearer())->assertStatus(422);
        foreach (['POST', 'PUT', 'PATCH', 'DELETE'] as $method) {
            $this->json($method, self::URL, [], $this->bearer())->assertStatus(405);
        }
        // The token still reaches nothing of the staff exam-period pages.
        $this->getJson('/api/v1/exam-periods?campus_id=1', $this->bearer())->assertForbidden();
        config(['triage_tokens.school_names_enabled' => false]);
        $this->getJson(self::URL, $this->bearer())->assertNotFound();
    }

    private function bearer(): array
    {
        return ['Authorization' => "Bearer {$this->token}", 'Accept' => 'application/json'];
    }
}
