<?php

namespace Tests\Feature;

use App\Models\Student;
use App\Models\StudentLineBinding;
use App\Models\SecurityAuditEvent;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * Coverage for POST /api/v1/parent/login-line (#981). This is a primary
 * production login path for families and previously had zero tests (the
 * name+phone path is covered by ParentPortalLoginIsolationTest).
 *
 * Guards: the server derives userId from a LINE-validated access token; spoofed
 * client IDs and historical unverified bindings cannot issue parent sessions.
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

    public function test_requires_access_token(): void
    {
        $this->postJson('/api/v1/parent/login-line', [])->assertStatus(422);
    }

    public function test_invalid_line_access_token_returns_401(): void
    {
        Http::fake([
            'https://api.line.me/v2/profile' => Http::response(['message' => 'invalid token'], 401),
        ]);

        $res = $this->postJson('/api/v1/parent/login-line', [
            'access_token' => 'invalid-token',
        ]);
        $res->assertStatus(401);
        $this->assertDatabaseHas('security_audit_events', [
            'event_type' => 'parent.auth',
            'outcome' => 'failure',
        ]);
    }

    public function test_verified_bound_line_id_returns_session_and_student(): void
    {
        $student = $this->createStudent(1, '王小明');
        $lineId  = 'Uaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa';
        StudentLineBinding::create([
            'student_id' => $student->id,
            'line_user_id' => $lineId,
            'verified_at' => now(),
            'verification_method' => 'contact_phone',
        ]);
        Http::fake([
            'https://api.line.me/v2/profile' => Http::response(['userId' => $lineId], 200),
        ]);

        $res = $this->postJson('/api/v1/parent/login-line', ['access_token' => 'valid-token']);

        $res->assertOk();
        $ids = collect($res->json('students'))->pluck('id')->all();
        $this->assertContains($student->id, $ids);
        $this->assertDatabaseHas('security_audit_events', [
            'event_type' => 'parent.auth',
            'outcome' => 'success',
            'subject_ref' => SecurityAuditEvent::ref('student', $student->id),
        ]);
    }

    public function test_historical_unverified_binding_cannot_issue_session(): void
    {
        $student = $this->createStudent(1, '舊綁定學生');
        $lineId = 'Ucccccccccccccccccccccccccccccccc';
        StudentLineBinding::create([
            'student_id' => $student->id,
            'line_user_id' => $lineId,
        ]);
        Http::fake([
            'https://api.line.me/v2/profile' => Http::response(['userId' => $lineId], 200),
        ]);

        $this->postJson('/api/v1/parent/login-line', [
            'access_token' => 'valid-token',
            'line_user_id' => $lineId,
        ])->assertStatus(404);
    }

    public function test_campus_id_prioritises_matching_campus_student_first(): void
    {
        // Same child enrolled at two campuses, both bound to the same LINE id.
        $studentCampus1 = $this->createStudent(1, '陳小華');
        $studentCampus2 = $this->createStudent(2, '陳小華');
        $lineId = 'Ubbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbb';
        foreach ([$studentCampus1, $studentCampus2] as $student) {
            StudentLineBinding::create([
                'student_id' => $student->id,
                'line_user_id' => $lineId,
                'verified_at' => now(),
                'verification_method' => 'contact_phone',
            ]);
        }
        Http::fake([
            'https://api.line.me/v2/profile' => Http::response(['userId' => $lineId], 200),
        ]);

        $res = $this->postJson('/api/v1/parent/login-line', [
            'access_token' => 'valid-token',
            'campus_id'    => 2,
        ]);

        $res->assertOk();
        $first = $res->json('students.0.id');
        $this->assertSame($studentCampus2->id, $first, 'campus_id should order the matching-campus student first');
    }
}
