<?php

namespace Tests\Feature;

use App\Models\ParentSession;
use App\Models\Student;
use App\Models\StudentLineBinding;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * PRD-B: Parent portal login isolation & dashboard privacy.
 *
 * Regression guards:
 *   - Two different Student rows sharing the same phone must NOT see each
 *     other when one of them logs in. (Information Disclosure, STRIDE.)
 *   - Name + Phone pair must match exactly ONE Student row; partial match
 *     (wrong name, right phone) must 404.
 *   - Dashboard must not leak siblings via same-phone coupling; only an
 *     explicit StudentLineBinding may attach siblings.
 *   - Switch-student must reject cross-family IDs (sharing only phone).
 */
class ParentPortalLoginIsolationTest extends TestCase
{
    use RefreshDatabase;

    public function test_login_requires_both_name_and_phone_without_student_id(): void
    {
        $this->createStudent(1, '王小明', '0912345678');

        $res = $this->postJson('/api/v1/parent/login', [
            'Phone' => '0912345678',
        ]);

        $res->assertStatus(422);
    }

    public function test_login_matches_exact_single_student_and_excludes_same_phone_sibling(): void
    {
        $studentA = $this->createStudent(1, '王小明', '0912345678');
        // Another family that happens to record the same phone number.
        $studentB = $this->createStudent(1, '李小美', '0912345678');

        $res = $this->postJson('/api/v1/parent/login', [
            'Name' => '王小明',
            'Phone' => '0912345678',
        ]);

        $res->assertOk();
        $body = $res->json();
        $this->assertSame($studentA->id, $body['student']['id']);
        // No `students` array attached when only same-phone match exists; sibling
        // resolution only via LINE binding per PRD-B FR-B-001.
        $this->assertTrue(
            !isset($body['students']) || $body['students'] === null,
            'Login payload must not surface same-phone siblings'
        );
    }

    public function test_login_rejects_wrong_name_but_matching_phone_with_404(): void
    {
        $this->createStudent(1, '王小明', '0912345678');

        $res = $this->postJson('/api/v1/parent/login', [
            'Name' => '王小華', // Wrong name but matching phone
            'Phone' => '0912345678',
        ]);

        $res->assertStatus(404);
    }

    public function test_dashboard_does_not_leak_same_phone_siblings(): void
    {
        $studentA = $this->createStudent(1, '王小明', '0912345678');
        $studentB = $this->createStudent(1, '李小美', '0912345678');

        $token = $this->parentLogin('王小明', '0912345678');
        $res = $this->getJson('/api/v1/parent/dashboard', [
            'Authorization' => 'Bearer ' . $token,
        ]);

        $res->assertOk();
        $body = $res->json();
        $this->assertSame($studentA->id, $body['student']['id']);
        $this->assertTrue(
            !isset($body['students']) || $body['students'] === null,
            'Dashboard must not expose sibling student IDs to cross-family callers'
        );
    }

    public function test_switch_student_rejects_cross_family_via_same_phone_only(): void
    {
        $studentA = $this->createStudent(1, '王小明', '0912345678');
        $studentB = $this->createStudent(1, '李小美', '0912345678');

        $token = $this->parentLogin('王小明', '0912345678');

        $res = $this->postJson('/api/v1/parent/switch-student', [
            'student_id' => $studentB->id,
        ], [
            'Authorization' => 'Bearer ' . $token,
        ]);

        $res->assertStatus(403);
    }

    public function test_siblings_via_line_binding_are_allowed(): void
    {
        $studentA = $this->createStudent(1, '王大毛', '0911000001');
        $studentB = $this->createStudent(1, '王二毛', '0911000002');

        // Explicit LINE binding for both under a single parent line_user_id
        $lineUserId = 'Utest_line_parent_001';
        StudentLineBinding::create(['student_id' => $studentA->id, 'line_user_id' => $lineUserId]);
        StudentLineBinding::create(['student_id' => $studentB->id, 'line_user_id' => $lineUserId]);

        $token = $this->parentLogin('王大毛', '0911000001');
        $res = $this->getJson('/api/v1/parent/dashboard', [
            'Authorization' => 'Bearer ' . $token,
        ]);

        $res->assertOk();
        $body = $res->json();
        $this->assertNotNull($body['students'] ?? null);
        $ids = array_map(fn ($s) => $s['id'], $body['students']);
        $this->assertContains($studentA->id, $ids);
        $this->assertContains($studentB->id, $ids);
    }

    public function test_dashboard_attendance_payload_includes_fr_b_003_fields(): void
    {
        $student = $this->createStudent(1, '王小明', '0912345678');
        $token = $this->parentLogin('王小明', '0912345678');

        $res = $this->getJson('/api/v1/parent/dashboard', [
            'Authorization' => 'Bearer ' . $token,
        ]);

        $res->assertOk();
        $res->assertJsonStructure([
            'attendance_history',
            'remaining_sessions_total',
            'classes',
        ]);
        // attendance_history may be empty for a fresh student; the schema must still
        // be an array (never object/null) so the frontend empty-state renders.
        $this->assertIsArray($res->json('attendance_history'));
    }

    // ── Helpers ───────────────────────────────────────────────────────

    private function createStudent(int $campusId, string $name, ?string $phone = null): Student
    {
        return Student::create([
            'name' => $name,
            'CampusID' => $campusId,
            'ClassID' => 1,
            'enable' => 1,
            'MDT' => now(),
            'Notify_Token' => '',
            'Phone' => $phone,
        ]);
    }

    private function parentLogin(string $name, string $phone): string
    {
        $res = $this->postJson('/api/v1/parent/login', [
            'Name' => $name,
            'Phone' => $phone,
        ]);
        $res->assertOk();
        return $res->json('token');
    }
}
