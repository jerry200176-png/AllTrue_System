<?php

namespace Tests\Feature;

use App\Models\AuthToken;
use App\Models\Student;
use App\Models\StudentClass;
use App\Models\User;
use App\Models\UserCampus;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class StudentClassPaidStatusTest extends TestCase
{
    use RefreshDatabase;

    /**
     * Contract: editing Memo while round-tripping the existing PayDate as paid_at
     * (what the edit form does when the user did not touch the date field) must
     * keep Paid=1. The frontend initializes form.paid_at from the existing PayDate,
     * so a Memo-only edit still sends the real date back, not null.
     */
    public function test_update_memo_with_existing_paid_at_preserves_paid_status(): void
    {
        $token = $this->createDirectorToken([1]);
        $student = $this->createStudent();
        $sc = $this->createStudentClass($student->id, [
            'Paid' => 1,
            'PayDate' => '2026-04-01',
        ]);

        $res = $this->putJson(
            "/api/v1/student-classes/{$sc->ID}",
            ['Memo' => '新備註', 'paid_at' => '2026-04-01'],
            ['Authorization' => "Bearer {$token}"]
        );

        $res->assertOk();
        $sc->refresh();
        $this->assertSame(1, (int) $sc->Paid, 'Paid must remain 1 when the existing paid_at is round-tripped');
        $this->assertSame('新備註', $sc->Memo);
    }

    /**
     * Explicit payment_status=unpaid must set Paid=0.
     */
    public function test_explicit_payment_status_unpaid_sets_paid_zero(): void
    {
        $token = $this->createDirectorToken([1]);
        $student = $this->createStudent();
        $sc = $this->createStudentClass($student->id, [
            'Paid' => 1,
            'PayDate' => '2026-04-01',
        ]);

        $res = $this->putJson(
            "/api/v1/student-classes/{$sc->ID}",
            ['payment_status' => 'unpaid'],
            ['Authorization' => "Bearer {$token}"]
        );

        $res->assertOk();
        $sc->refresh();
        $this->assertSame(0, (int) $sc->Paid, 'Explicit payment_status=unpaid must set Paid=0');
    }

    /**
     * Sending paid_at with a date must upgrade Paid to 1 and store the date.
     */
    public function test_paid_at_with_date_upgrades_to_paid(): void
    {
        $token = $this->createDirectorToken([1]);
        $student = $this->createStudent();
        $sc = $this->createStudentClass($student->id, [
            'Paid' => 0,
            'PayDate' => null,
        ]);

        $res = $this->putJson(
            "/api/v1/student-classes/{$sc->ID}",
            ['paid_at' => '2026-04-14'],
            ['Authorization' => "Bearer {$token}"]
        );

        $res->assertOk();
        $sc->refresh();
        $this->assertSame(1, (int) $sc->Paid, 'paid_at with a date must set Paid=1');
        $this->assertStringStartsWith('2026-04-14', (string) $sc->PayDate);
    }

    /**
     * Clearing paid_at (explicit null) must downgrade Paid to 0 and clear PayDate.
     * This backs the edit-form UX: 清空繳費日期 + 存檔 → 改為未繳費。
     */
    public function test_clearing_paid_at_downgrades_to_unpaid(): void
    {
        $token = $this->createDirectorToken([1]);
        $student = $this->createStudent();
        $sc = $this->createStudentClass($student->id, [
            'Paid' => 1,
            'PayDate' => '2026-03-15',
        ]);

        $res = $this->putJson(
            "/api/v1/student-classes/{$sc->ID}",
            ['paid_at' => null],
            ['Authorization' => "Bearer {$token}"]
        );

        $res->assertOk();
        $sc->refresh();
        $this->assertSame(0, (int) $sc->Paid, 'Clearing paid_at must set Paid=0');
        $this->assertNull($sc->PayDate, 'PayDate should be cleared');
    }

    /**
     * Edit-form round-trip: clear the date together with editing Memo → becomes unpaid.
     */
    public function test_edit_form_clears_paid_at_with_memo_marks_unpaid(): void
    {
        $token = $this->createDirectorToken([1]);
        $student = $this->createStudent();
        $sc = $this->createStudentClass($student->id, [
            'Paid' => 1,
            'PayDate' => '2026-04-01',
        ]);

        $res = $this->putJson(
            "/api/v1/student-classes/{$sc->ID}",
            ['Memo' => '改備註並取消繳費', 'paid_at' => null],
            ['Authorization' => "Bearer {$token}"]
        );

        $res->assertOk();
        $sc->refresh();
        $this->assertSame(0, (int) $sc->Paid);
        $this->assertNull($sc->PayDate);
        $this->assertSame('改備註並取消繳費', $sc->Memo);
    }

    /**
     * payment_status is an explicit trump card: even if paid_at is also sent,
     * payment_status wins. This preserves the existing list-button toggle.
     */
    public function test_payment_status_wins_over_paid_at(): void
    {
        $token = $this->createDirectorToken([1]);
        $student = $this->createStudent();
        $sc = $this->createStudentClass($student->id, [
            'Paid' => 0,
            'PayDate' => null,
        ]);

        $res = $this->putJson(
            "/api/v1/student-classes/{$sc->ID}",
            ['paid_at' => '2026-04-14', 'payment_status' => 'unpaid'],
            ['Authorization' => "Bearer {$token}"]
        );

        $res->assertOk();
        $sc->refresh();
        $this->assertSame(0, (int) $sc->Paid, 'Explicit payment_status=unpaid must win over paid_at date');
        $this->assertStringStartsWith('2026-04-14', (string) $sc->PayDate, 'PayDate still reflects the sent paid_at');
    }

    /**
     * Explicit payment_status=paid must set Paid=1 even without paid_at.
     */
    public function test_explicit_payment_status_paid_sets_paid_one(): void
    {
        $token = $this->createDirectorToken([1]);
        $student = $this->createStudent();
        $sc = $this->createStudentClass($student->id, [
            'Paid' => 0,
            'PayDate' => null,
        ]);

        $res = $this->putJson(
            "/api/v1/student-classes/{$sc->ID}",
            ['payment_status' => 'paid'],
            ['Authorization' => "Bearer {$token}"]
        );

        $res->assertOk();
        $sc->refresh();
        $this->assertSame(1, (int) $sc->Paid);
    }

    /**
     * Sending only Memo (no paid_at key at all) must not touch Paid.
     */
    public function test_update_memo_only_does_not_touch_paid(): void
    {
        $token = $this->createDirectorToken([1]);
        $student = $this->createStudent();
        $sc = $this->createStudentClass($student->id, [
            'Paid' => 1,
            'PayDate' => null,
        ]);

        $res = $this->putJson(
            "/api/v1/student-classes/{$sc->ID}",
            ['Memo' => '只改備註'],
            ['Authorization' => "Bearer {$token}"]
        );

        $res->assertOk();
        $sc->refresh();
        $this->assertSame(1, (int) $sc->Paid);
        $this->assertSame('只改備註', $sc->Memo);
    }

    /**
     * Batch toggle: after toggling to unpaid then editing memo, Paid stays 0.
     */
    public function test_toggle_unpaid_then_edit_memo_keeps_unpaid(): void
    {
        $token = $this->createDirectorToken([1]);
        $student = $this->createStudent();
        $sc = $this->createStudentClass($student->id, [
            'Paid' => 1,
            'PayDate' => '2026-04-01',
        ]);

        $this->putJson(
            "/api/v1/student-classes/{$sc->ID}",
            ['payment_status' => 'unpaid'],
            ['Authorization' => "Bearer {$token}"]
        )->assertOk();

        $sc->refresh();
        $this->assertSame(0, (int) $sc->Paid);

        $this->putJson(
            "/api/v1/student-classes/{$sc->ID}",
            ['Memo' => '改備註後'],
            ['Authorization' => "Bearer {$token}"]
        )->assertOk();

        $sc->refresh();
        $this->assertSame(0, (int) $sc->Paid, 'After toggling unpaid, editing memo must keep Paid=0');
        $this->assertSame('改備註後', $sc->Memo);
    }

    /**
     * Recording a payment on an invoice linked to a StudentClass must sync Paid=1.
     */
    public function test_record_payment_syncs_student_class_paid(): void
    {
        $token = $this->createDirectorToken([1]);
        $student = $this->createStudent();
        $sc = $this->createStudentClass($student->id, [
            'Paid' => 0,
            'PayDate' => null,
        ]);

        $invoice = \App\Models\Invoice::create([
            'StudentID' => $student->id,
            'StudentClassID' => $sc->ID,
            'IssueDate' => now()->toDateString(),
            'TotalAmount' => 16500,
            'PaidAmount' => 0,
            'Status' => 'unpaid',
        ]);

        $res = $this->postJson(
            "/api/v1/invoices/{$invoice->id}/payments",
            ['Amount' => 16500, 'PaidAt' => '2026-04-15'],
            ['Authorization' => "Bearer {$token}"]
        );

        $res->assertOk();
        $sc->refresh();
        $this->assertSame(1, (int) $sc->Paid, 'Recording payment must sync Paid=1');
        $this->assertNotNull($sc->PayDate, 'PayDate should be set after payment');
    }

    /**
     * Partial payment should also mark the course as paid.
     */
    public function test_partial_payment_syncs_student_class_paid(): void
    {
        $token = $this->createDirectorToken([1]);
        $student = $this->createStudent();
        $sc = $this->createStudentClass($student->id, [
            'Paid' => 0,
            'PayDate' => null,
        ]);

        $invoice = \App\Models\Invoice::create([
            'StudentID' => $student->id,
            'StudentClassID' => $sc->ID,
            'IssueDate' => now()->toDateString(),
            'TotalAmount' => 16500,
            'PaidAmount' => 0,
            'Status' => 'unpaid',
        ]);

        $res = $this->postJson(
            "/api/v1/invoices/{$invoice->id}/payments",
            ['Amount' => 8000],
            ['Authorization' => "Bearer {$token}"]
        );

        $res->assertOk();
        $sc->refresh();
        $this->assertSame(1, (int) $sc->Paid, 'Partial payment must also sync Paid=1');
    }

    /**
     * GET student-classes should show paid when invoice has payment even if Paid=0 in DB.
     */
    public function test_index_shows_paid_when_invoice_has_payment(): void
    {
        $token = $this->createDirectorToken([1]);
        $student = $this->createStudent();
        $sc = $this->createStudentClass($student->id, [
            'Paid' => 0,
            'PayDate' => null,
        ]);

        $invoice = \App\Models\Invoice::create([
            'StudentID' => $student->id,
            'StudentClassID' => $sc->ID,
            'IssueDate' => now()->toDateString(),
            'TotalAmount' => 16500,
            'PaidAmount' => 16500,
            'Status' => 'paid',
        ]);
        \App\Models\Payment::create([
            'InvoiceID' => $invoice->id,
            'Amount' => 16500,
            'PaidAt' => '2026-04-15',
            'Method' => 'cash',
        ]);

        $res = $this->getJson(
            '/api/v1/student-classes?branch_id=1',
            ['Authorization' => "Bearer {$token}"]
        );

        $res->assertOk();
        $json = $res->json();
        $data = $json['data'] ?? $json;
        $match = collect($data)->first(fn ($c) => (int) ($c['ID'] ?? $c['id'] ?? 0) === (int) $sc->ID);
        $this->assertNotNull($match, 'Course should appear in list');
        $this->assertSame('paid', $match['payment_status'], 'payment_status should be paid when invoice has payment');
    }

    /**
     * FR-002 backend guard: toggle to unpaid without paid_at must also clear PayDate.
     * Regression for Bug B: StudentsList.vue previously sent only { payment_status: 'unpaid' }
     * without paid_at: null, leaving PayDate stale while Paid was set to 0.
     */
    public function test_toggle_to_unpaid_without_paid_at_clears_paydate(): void
    {
        $token = $this->createDirectorToken([1]);
        $student = $this->createStudent();
        $sc = $this->createStudentClass($student->id, [
            'Paid'    => 1,
            'PayDate' => '2026-03-01',
        ]);

        $res = $this->putJson(
            "/api/v1/student-classes/{$sc->ID}",
            ['payment_status' => 'unpaid'],
            ['Authorization' => "Bearer {$token}"]
        );

        $res->assertOk();
        $sc->refresh();
        $this->assertSame(0, (int) $sc->Paid, 'Paid must be 0 after toggling to unpaid');
        $this->assertNull($sc->PayDate, 'PayDate must be cleared when toggling to unpaid without paid_at');
    }

    /**
     * FR-004 batch create with paid_at: course must show payment_status = "paid".
     */
    public function test_batch_create_with_paid_at_sets_payment_status_paid(): void
    {
        $token = $this->createDirectorToken([1]);
        $student = $this->createStudent();
        $teacher = $this->createTeacher();

        $future = now()->addWeek()->toDateString();

        $res = $this->postJson('/api/v1/class-sessions/batch', [
            'branch_id'       => 1,
            'student_id'      => $student->id,
            'teacher_id'      => $teacher,
            'subject'         => 'Math',
            'class_type'      => 'one_on_one',
            'confirmed_dates' => [],
            'future_dates'    => [$future],
            'start_time'      => '16:00',
            'duration_minutes' => 60,
            'price_per_session' => 500,
            'payment_type'    => 'session',
            'total_classes'   => 1,
            'paid_at'         => '2026-03-02',
        ], ['Authorization' => "Bearer {$token}"]);

        $res->assertCreated();
        $studentClassId = (int) ($res->json('student_class_id') ?? 0);
        $this->assertGreaterThan(0, $studentClassId);

        $sc = \App\Models\StudentClass::find($studentClassId);
        $this->assertNotNull($sc, 'StudentClass must be created');
        $this->assertSame(1, (int) $sc->Paid, 'Paid must be 1 when paid_at is provided');
        $this->assertStringStartsWith('2026-03-02', (string) $sc->PayDate, 'PayDate must match paid_at');
    }

    /**
     * FR-004 batch create without paid_at: course must show payment_status = "unpaid".
     */
    public function test_batch_create_without_paid_at_sets_payment_status_unpaid(): void
    {
        $token = $this->createDirectorToken([1]);
        $student = $this->createStudent();
        $teacher = $this->createTeacher();

        $future = now()->addWeek()->toDateString();

        $res = $this->postJson('/api/v1/class-sessions/batch', [
            'branch_id'       => 1,
            'student_id'      => $student->id,
            'teacher_id'      => $teacher,
            'subject'         => 'Math',
            'class_type'      => 'one_on_one',
            'confirmed_dates' => [],
            'future_dates'    => [$future],
            'start_time'      => '16:00',
            'duration_minutes' => 60,
            'price_per_session' => 500,
            'payment_type'    => 'session',
            'total_classes'   => 1,
        ], ['Authorization' => "Bearer {$token}"]);

        $res->assertCreated();
        $studentClassId = (int) ($res->json('student_class_id') ?? 0);
        $this->assertGreaterThan(0, $studentClassId);

        $sc = \App\Models\StudentClass::find($studentClassId);
        $this->assertNotNull($sc, 'StudentClass must be created');
        $this->assertSame(0, (int) $sc->Paid, 'Paid must be 0 when no paid_at is provided');
        $this->assertNull($sc->PayDate, 'PayDate must be NULL when no paid_at is provided');
    }

    // ── Helpers ──

    private function createDirectorToken(array $campusIds): string
    {
        $user = User::create([
            'LoginName' => 'paid-test-' . uniqid() . '@example.com',
            'Name' => '測試主任',
            'PSW' => 'secret',
            'type' => 'A',
            'phone' => '0900000000',
        ]);

        foreach ($campusIds as $campusId) {
            UserCampus::create([
                'CampusID' => $campusId,
                'UserID' => $user->id,
                'Admin' => 1,
                'Approved' => 1,
            ]);
        }

        $token = bin2hex(random_bytes(16));
        AuthToken::create([
            'user_id' => $user->id,
            'token' => $token,
            'expires_at' => now()->addDay(),
        ]);

        return $token;
    }

    private function createStudent(): Student
    {
        return Student::create([
            'name' => '繳費測試生',
            'CampusID' => 1,
            'ClassID' => 1,
            'enable' => 1,
            'MDT' => now(),
            'Notify_Token' => '',
        ]);
    }

    private function createStudentClass(int $studentId, array $overrides = []): StudentClass
    {
        $defaults = [
            'StudentID' => $studentId,
            'GradeID' => 1,
            'SubjectID' => 1,
            'TeacherID' => 99,
            'by1' => 1,
            'Period' => 4,
            'StartDate' => now(),
            'TotalHours' => 20,
            'Charge' => 0,
            'Paid' => 0,
            'Rate' => 0,
            'RoomID' => 'R1',
            'MDate' => now(),
            'Stop' => 0,
            'ScheduleMode' => 'count',
            'SessionCount' => 8,
            'SessionDuration' => 60,
            'RemainingSessions' => 8,
            'ClassType' => 'one_on_one',
            'UsedSessions' => 0,
        ];

        return StudentClass::create(array_merge($defaults, $overrides));
    }

    private function createTeacher(int $campusId = 1): int
    {
        $teacher = User::create([
            'LoginName' => 'teacher-paid-test-' . uniqid() . '@example.com',
            'Name' => '測試老師',
            'PSW' => 'secret',
            'type' => 'T',
            'phone' => '0922000000',
        ]);

        UserCampus::create([
            'CampusID' => $campusId,
            'UserID' => $teacher->id,
            'Admin' => 0,
            'Approved' => 1,
        ]);

        return (int) $teacher->id;
    }
}
