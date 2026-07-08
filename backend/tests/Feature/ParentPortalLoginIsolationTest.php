<?php

namespace Tests\Feature;

use App\Models\ClassSession;
use App\Models\Invoice;
use App\Models\ParentSession;
use App\Models\Payment;
use App\Models\Student;
use App\Models\StudentClass;
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

    public function test_dashboard_invoices_only_include_visible_courses(): void
    {
        $student = $this->createStudent(1, '吳艾潼', '0912555000');
        $visibleCourse = $this->createStudentClass($student->id, [
            'RemainingSessions' => 4,
            'Paid' => 0,
            'Stop' => 0,
        ]);
        $hiddenSettledCourse = $this->createStudentClass($student->id, [
            'RemainingSessions' => 0,
            'Paid' => 1,
            'Stop' => 1,
        ]);

        $visibleInvoice = Invoice::create([
            'StudentID' => $student->id,
            'StudentClassID' => $visibleCourse->ID,
            'IssueDate' => '2026-04-01',
            'DueDate' => '2026-04-15',
            'TotalAmount' => 8800,
            'PaidAmount' => 0,
            'Status' => 'unpaid',
            'Note' => '',
            'billing_period' => '2026-04',
        ]);
        $hiddenInvoice = Invoice::create([
            'StudentID' => $student->id,
            'StudentClassID' => $hiddenSettledCourse->ID,
            'IssueDate' => '2026-05-01',
            'DueDate' => '2026-05-15',
            'TotalAmount' => 8800,
            'PaidAmount' => 0,
            'Status' => 'unpaid',
            'Note' => '',
            'billing_period' => '2026-05',
        ]);

        $token = $this->parentLogin('吳艾潼', '0912555000');
        $res = $this->getJson('/api/v1/parent/dashboard', [
            'Authorization' => 'Bearer ' . $token,
        ]);

        $res->assertOk();

        $courseIds = collect($res->json('classes'))->pluck('id')->all();
        $invoiceIds = collect($res->json('invoices'))->pluck('id')->all();

        $this->assertContains($visibleCourse->ID, $courseIds);
        $this->assertNotContains($hiddenSettledCourse->ID, $courseIds);
        $this->assertContains($visibleInvoice->id, $invoiceIds);
        $this->assertNotContains($hiddenInvoice->id, $invoiceIds, 'Hidden/settled course invoices must not appear in parent receivables.');
    }

    public function test_dashboard_invoices_exclude_voided_invoices(): void
    {
        $student = $this->createStudent(1, '吳艾潼', '0912555000');
        $course = $this->createStudentClass($student->id, [
            'RemainingSessions' => 4,
            'Paid' => 0,
            'Stop' => 0,
            'ScheduleMode' => 'date',
        ]);
        $openInvoice = Invoice::create([
            'StudentID' => $student->id,
            'StudentClassID' => $course->ID,
            'IssueDate' => '2026-04-01',
            'DueDate' => '2026-04-15',
            'TotalAmount' => 8800,
            'PaidAmount' => 0,
            'Status' => 'unpaid',
            'Note' => '',
            'billing_period' => '2026-04',
        ]);
        $voidInvoice = Invoice::create([
            'StudentID' => $student->id,
            'StudentClassID' => $course->ID,
            'IssueDate' => '2026-05-01',
            'DueDate' => '2026-05-15',
            'TotalAmount' => 8800,
            'PaidAmount' => 0,
            'Status' => 'void',
            'Note' => '歷史錯帳作廢',
            'billing_period' => '2026-05',
        ]);

        $token = $this->parentLogin('吳艾潼', '0912555000');
        $res = $this->getJson('/api/v1/parent/dashboard', [
            'Authorization' => 'Bearer ' . $token,
        ]);

        $res->assertOk();
        $invoiceIds = collect($res->json('invoices'))->pluck('id')->all();
        $this->assertContains($openInvoice->id, $invoiceIds);
        $this->assertNotContains($voidInvoice->id, $invoiceIds, 'Voided/stale invoices must not appear in parent receivables.');
    }

    public function test_monthly_course_uses_billing_period_for_parent_display_month(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-04-29 12:00:00'));

        try {
            $student = $this->createStudent(1, '月結五月學生', '0912666000');
            $course = $this->createStudentClass($student->id, [
                'ScheduleMode' => 'date',
                'StartDate' => '2026-05-01',
                'EndDate' => '2026-05-31',
                'SessionCount' => 0,
                'RemainingSessions' => 0,
                'monthly_sessions' => 4,
                'Paid' => 1,
                'Stop' => 0,
            ]);

            ClassSession::create([
                'StudentClassID' => $course->ID,
                'SessionDate' => '2026-04-25',
                'StartTime' => '10:00',
                'EndTime' => '12:00',
                'Status' => 'attended',
            ]);
            ClassSession::create([
                'StudentClassID' => $course->ID,
                'SessionDate' => '2026-05-02',
                'StartTime' => '10:00',
                'EndTime' => '12:00',
                'Status' => 'attended',
            ]);

            Invoice::create([
                'StudentID' => $student->id,
                'StudentClassID' => $course->ID,
                'IssueDate' => '2026-04-29',
                'DueDate' => '2026-05-05',
                'TotalAmount' => 8800,
                'PaidAmount' => 8800,
                'Status' => 'paid',
                'Note' => '',
                'billing_period' => '2026-05',
            ]);

            $token = $this->parentLogin('月結五月學生', '0912666000');
            $res = $this->getJson('/api/v1/parent/dashboard', [
                'Authorization' => 'Bearer ' . $token,
            ]);

            $res->assertOk();
            $this->assertSame('4月', $res->json('current_month_label'));

            $class = collect($res->json('classes'))->firstWhere('id', $course->ID);
            $this->assertNotNull($class);
            $this->assertSame('2026-05', $class['billing_period']);
            $this->assertSame('5月', $class['display_month_label']);
            $this->assertSame(1, $class['attended_this_month']);
        } finally {
            Carbon::setTestNow();
        }
    }

    public function test_stopped_paid_monthly_course_keeps_paid_status_visible(): void
    {
        $student = $this->createStudent(1, '已結案月結學生', '0912777000');
        $course = $this->createStudentClass($student->id, [
            'ScheduleMode' => 'date',
            'StartDate' => '2026-05-01',
            'EndDate' => '2026-05-31',
            'SessionCount' => 0,
            'RemainingSessions' => 0,
            'monthly_sessions' => 4,
            'Paid' => 1,
            'Stop' => 1,
        ]);

        $token = $this->parentLogin('已結案月結學生', '0912777000');
        $res = $this->getJson('/api/v1/parent/dashboard', [
            'Authorization' => 'Bearer ' . $token,
        ]);

        $res->assertOk();
        $class = collect($res->json('classes'))->firstWhere('id', $course->ID);
        $this->assertNotNull($class, 'Stopped and paid monthly course should remain visible with settled wording, not disappear into an ambiguous state.');
        $this->assertTrue($class['paid']);
        $this->assertTrue($class['is_stopped']);
        $this->assertSame('paid', $class['payment_status']);
        $this->assertSame('closed', $class['lifecycle_status']);
    }

    public function test_paid_low_session_course_is_not_parent_payment_alert(): void
    {
        $student = $this->createStudent(1, '低堂數提醒學生', '0912888000');
        $paidCourse = $this->createStudentClass($student->id, [
            'SessionCount' => 8,
            'Paid' => 1,
            'Stop' => 0,
        ]);
        $unpaidCourse = $this->createStudentClass($student->id, [
            'SessionCount' => 8,
            'Paid' => 0,
            'Stop' => 0,
        ]);

        $this->createAttendedSessions($paidCourse, 7);
        $this->createAttendedSessions($unpaidCourse, 7);

        $token = $this->parentLogin('低堂數提醒學生', '0912888000');
        $res = $this->getJson('/api/v1/parent/dashboard', [
            'Authorization' => 'Bearer ' . $token,
        ]);

        $res->assertOk();
        $alertClassIds = collect($res->json('payment_alerts'))->pluck('class_id')->all();
        $this->assertNotContains($paidCourse->ID, $alertClassIds, 'Paid low-session courses are director renewal reminders, not parent payment alerts.');
        $this->assertContains($unpaidCourse->ID, $alertClassIds);
    }

    public function test_invoice_payment_counts_as_paid_for_parent_payment_alerts(): void
    {
        $student = $this->createStudent(1, '帳單已繳學生', '0912999000');
        $course = $this->createStudentClass($student->id, [
            'SessionCount' => 8,
            'Paid' => 0,
            'Stop' => 0,
        ]);
        $this->createAttendedSessions($course, 7);
        $invoice = Invoice::create([
            'StudentID' => $student->id,
            'StudentClassID' => $course->ID,
            'IssueDate' => '2026-04-01',
            'DueDate' => '2026-04-15',
            'TotalAmount' => 8800,
            'PaidAmount' => 8800,
            'Status' => 'paid',
            'Note' => '',
            'billing_period' => '2026-04',
        ]);
        Payment::create([
            'InvoiceID' => $invoice->id,
            'Amount' => 8800,
            'PaidAt' => '2026-04-10',
            'Method' => 'cash',
            'Note' => '',
        ]);

        $token = $this->parentLogin('帳單已繳學生', '0912999000');
        $res = $this->getJson('/api/v1/parent/dashboard', [
            'Authorization' => 'Bearer ' . $token,
        ]);

        $res->assertOk();
        $class = collect($res->json('classes'))->firstWhere('id', $course->ID);
        $this->assertTrue($class['paid']);
        $this->assertSame('paid', $class['payment_status']);
        $alertClassIds = collect($res->json('payment_alerts'))->pluck('class_id')->all();
        $this->assertNotContains($course->ID, $alertClassIds, 'Course management treats invoice payments as paid; parent portal must use the same paid source.');
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

    private function createStudentClass(int $studentId, array $overrides = []): StudentClass
    {
        return StudentClass::create(array_merge([
            'StudentID' => $studentId,
            'GradeID' => 1,
            'SubjectID' => 1,
            'TeacherID' => 1,
            'by1' => 1,
            'Period' => 4,
            'StartDate' => '2026-04-01',
            'EndDate' => '2026-05-31',
            'TotalHours' => 8,
            'Charge' => 8800,
            'Paid' => 0,
            'Rate' => 1100,
            'MDate' => now(),
            'Stop' => 0,
            'ScheduleMode' => 'count',
            'SessionCount' => 8,
            'SessionDuration' => 60,
            'RemainingSessions' => 8,
            'ClassType' => 'one_on_one',
            'UsedSessions' => 0,
        ], $overrides));
    }

    private function createAttendedSessions(StudentClass $course, int $count): void
    {
        for ($i = 0; $i < $count; $i++) {
            ClassSession::create([
                'StudentClassID' => $course->ID,
                'SessionDate' => Carbon::parse('2026-04-01')->addDays($i)->toDateString(),
                'StartTime' => '10:00',
                'EndTime' => '12:00',
                'Status' => 'attended',
            ]);
        }
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
