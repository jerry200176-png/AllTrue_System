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

        // Explicit LINE binding for both under a single parent line_user_id (valid U+32hex format)
        $lineUserId = 'Ua1b2c3d4e5f6a1b2c3d4e5f6a1b2c3d4';
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

    // ── parent_phone regression（BUG: login only checked Phone, not parent_phone）────

    public function test_login_succeeds_with_parent_phone_when_phone_is_empty(): void
    {
        // 模擬「家長手機」從 UI 填入 parent_phone，Phone 欄位空白的情況（大安商安禮 場景）
        $this->createStudentWithParentPhone(15, '商安禮', null, '0933111222');

        $res = $this->postJson('/api/v1/parent/login', [
            'Name'  => '商安禮',
            'Phone' => '0933111222',
        ]);

        $res->assertOk();
    }

    public function test_login_prefers_parent_phone_over_phone_when_both_set(): void
    {
        $this->createStudentWithParentPhone(15, '測試學生', '0911000000', '0933999888');

        // parent_phone 優先，Phone 不符合也能登入
        $res = $this->postJson('/api/v1/parent/login', [
            'Name'  => '測試學生',
            'Phone' => '0933999888',
        ]);
        $res->assertOk();

        // 用舊 Phone 登入應失敗（parent_phone 已覆蓋）
        $res2 = $this->postJson('/api/v1/parent/login', [
            'Name'  => '測試學生',
            'Phone' => '0911000000',
        ]);
        $res2->assertStatus(404);
    }

    public function test_login_falls_back_to_phone_when_parent_phone_empty(): void
    {
        // 舊資料：只有 Phone，沒有 parent_phone → 向下相容
        $this->createStudent(15, '舊資料學生', '0922333444');

        $res = $this->postJson('/api/v1/parent/login', [
            'Name'  => '舊資料學生',
            'Phone' => '0922333444',
        ]);
        $res->assertOk();
    }

    public function test_empty_contact_phone_returns_401_with_hint(): void
    {
        // 兩個欄位都空 → 應該提示分校補登
        $this->createStudentWithParentPhone(15, '無手機學生', null, null);

        $res = $this->postJson('/api/v1/parent/login', [
            'Name'  => '無手機學生',
            'Phone' => '0900000000',
        ]);
        $res->assertStatus(401);
        $res->assertJsonFragment(['message' => '此學生尚未設定聯絡手機，請聯繫分校補登後再登入']);
    }

    // ── Helpers ───────────────────────────────────────────────────────

    /**
     * Regression: invalid LINE user IDs (not U+32hex) must not create false sibling groups.
     * Root cause: backfill migration copied Student.LineID values that were never real LINE IDs.
     */
    public function test_invalid_line_user_id_does_not_create_sibling_group(): void
    {
        // Two unrelated students share the same *invalid* LINE user ID (not U+32hex format)
        $studentA = $this->createStudent(1, '黃品皓', '0911000001');
        $studentB = $this->createStudent(2, '許瀠升', '0911000002'); // different campus

        $invalidLineId = 'INVALID_NOT_LINE_FORMAT'; // not U+32hex
        StudentLineBinding::create(['student_id' => $studentA->id, 'line_user_id' => $invalidLineId]);
        StudentLineBinding::create(['student_id' => $studentB->id, 'line_user_id' => $invalidLineId]);

        $token = $this->parentLogin('黃品皓', '0911000001');
        $res = $this->getJson('/api/v1/parent/dashboard', [
            'Authorization' => 'Bearer ' . $token,
        ]);

        $res->assertOk();
        $students = $res->json('students');
        // Must NOT show other student — invalid LINE IDs should be ignored
        $this->assertNull($students, 'Invalid LINE user ID must not produce sibling list');
    }

    public function test_valid_line_user_id_still_creates_sibling_group(): void
    {
        // Valid LINE user ID: U + 32 lowercase hex chars
        $validLineId = 'U' . str_repeat('a1', 16); // U + 32 hex chars
        $studentA = $this->createStudent(1, '王大毛', '0911000003');
        $studentB = $this->createStudent(1, '王二毛', '0911000004');

        StudentLineBinding::create(['student_id' => $studentA->id, 'line_user_id' => $validLineId]);
        StudentLineBinding::create(['student_id' => $studentB->id, 'line_user_id' => $validLineId]);

        $token = $this->parentLogin('王大毛', '0911000003');
        $res = $this->getJson('/api/v1/parent/dashboard', [
            'Authorization' => 'Bearer ' . $token,
        ]);

        $res->assertOk();
        $students = $res->json('students');
        $this->assertNotNull($students, 'Valid LINE user ID should produce sibling list');
        $ids = array_column($students, 'id');
        $this->assertContains($studentA->id, $ids);
        $this->assertContains($studentB->id, $ids);
    }

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

    private function createStudentWithParentPhone(
        int $campusId,
        string $name,
        ?string $phone,
        ?string $parentPhone
    ): Student {
        return Student::create([
            'name'         => $name,
            'CampusID'     => $campusId,
            'ClassID'      => 1,
            'enable'       => 1,
            'MDT'          => now(),
            'Notify_Token' => '',
            'Phone'        => $phone,
            'parent_phone' => $parentPhone,
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
