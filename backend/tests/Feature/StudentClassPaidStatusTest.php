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
     * BUG regression: editing only Memo with paid_at=null must NOT downgrade Paid from 1 to 0.
     */
    public function test_update_memo_with_null_paid_at_preserves_paid_status(): void
    {
        $token = $this->createDirectorToken([1]);
        $student = $this->createStudent();
        $sc = $this->createStudentClass($student->id, [
            'Paid' => 1,
            'PayDate' => null,
        ]);

        $res = $this->putJson(
            "/api/v1/student-classes/{$sc->ID}",
            ['Memo' => '新備註', 'paid_at' => null],
            ['Authorization' => "Bearer {$token}"]
        );

        $res->assertOk();
        $sc->refresh();
        $this->assertSame(1, (int) $sc->Paid, 'Paid must remain 1 when paid_at is null');
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
     * Clearing paid_at (null) on a paid course without payment_status must NOT change Paid.
     */
    public function test_clearing_paid_at_without_payment_status_keeps_paid(): void
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
        $this->assertSame(1, (int) $sc->Paid, 'Clearing paid_at must not downgrade Paid');
        $this->assertNull($sc->PayDate, 'PayDate should be cleared');
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
            ['Memo' => '改備註後', 'paid_at' => null],
            ['Authorization' => "Bearer {$token}"]
        )->assertOk();

        $sc->refresh();
        $this->assertSame(0, (int) $sc->Paid, 'After toggling unpaid, editing memo must keep Paid=0');
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
}
