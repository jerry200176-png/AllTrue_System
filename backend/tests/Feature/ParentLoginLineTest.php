<?php

namespace Tests\Feature;

use App\Models\Student;
use App\Models\StudentLineBinding;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Coverage for POST /api/v1/parent/login-line (#981). This is a primary
 * production login path for families and previously had zero tests (the
 * name+phone path is covered by ParentPortalLoginIsolationTest).
 *
 * Guards: unbound LINE id 404s; a bound LINE id returns a session + the bound
 * students; campus_id prioritises the matching-campus student first; validation
 * requires line_user_id.
 */
class ParentLoginLineTest extends TestCase
{
    use RefreshDatabase;

    private function createStudent(int $campusId, string $name): Student
    {
        return Student::create([
            'name'         => $name,
            'CampusID'     => $campusId,
            'ClassID'      => 1,
            'enable'       => 1,
            'MDT'          => now(),
            'Notify_Token' => '',
        ]);
    }

    public function test_requires_line_user_id(): void
    {
        $this->postJson('/api/v1/parent/login-line', [])->assertStatus(422);
    }

    public function test_unbound_line_id_returns_404(): void
    {
        $res = $this->postJson('/api/v1/parent/login-line', [
            'line_user_id' => 'U0000000000000000000000000000000',
        ]);
        $res->assertStatus(404);
    }

    public function test_bound_line_id_returns_session_and_student(): void
    {
        $student = $this->createStudent(1, '王小明');
        $lineId  = 'Uaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa';
        StudentLineBinding::create(['student_id' => $student->id, 'line_user_id' => $lineId]);

        $res = $this->postJson('/api/v1/parent/login-line', ['line_user_id' => $lineId]);

        $res->assertOk();
        $ids = collect($res->json('students'))->pluck('id')->all();
        $this->assertContains($student->id, $ids);
    }

    public function test_campus_id_prioritises_matching_campus_student_first(): void
    {
        // Same child enrolled at two campuses, both bound to the same LINE id.
        $studentCampus1 = $this->createStudent(1, '陳小華');
        $studentCampus2 = $this->createStudent(2, '陳小華');
        $lineId = 'Ubbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbb';
        StudentLineBinding::create(['student_id' => $studentCampus1->id, 'line_user_id' => $lineId]);
        StudentLineBinding::create(['student_id' => $studentCampus2->id, 'line_user_id' => $lineId]);

        $res = $this->postJson('/api/v1/parent/login-line', [
            'line_user_id' => $lineId,
            'campus_id'    => 2,
        ]);

        $res->assertOk();
        $first = $res->json('students.0.id');
        $this->assertSame($studentCampus2->id, $first, 'campus_id should order the matching-campus student first');
    }
}
