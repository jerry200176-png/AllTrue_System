<?php

namespace Tests\Feature;

use App\Models\AuthToken;
use App\Models\Invoice;
use App\Models\Payment;
use App\Models\Student;
use App\Models\StudentClass;
use App\Models\User;
use App\Models\UserCampus;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class BillingInvoiceVoidTest extends TestCase
{
    use RefreshDatabase;

    public function test_director_can_void_unpaid_invoice_with_reason(): void
    {
        $token = $this->createToken('A', [1]);
        [$student, $course, $invoice] = $this->createInvoiceFixture(1, [
            'Status' => 'unpaid',
            'PaidAmount' => 0,
            'billing_period' => '2026-05',
            'Note' => 'legacy duplicate',
        ]);

        $res = $this->withHeaders([
            'Authorization' => "Bearer {$token}",
            'Accept' => 'application/json',
        ])->postJson("/api/v1/invoices/{$invoice->id}/void", [
            'reason' => '吳艾潼 COURSE-000382 歷史錯帳',
        ]);

        $res->assertOk()
            ->assertJsonPath('invoice.status', 'void');

        $this->assertDatabaseHas('Invoice', [
            'id' => $invoice->id,
            'StudentID' => $student->id,
            'StudentClassID' => $course->ID,
            'Status' => 'void',
        ]);

        $fresh = Invoice::findOrFail($invoice->id);
        $this->assertStringContainsString('legacy duplicate', (string) $fresh->Note);
        $this->assertStringContainsString('吳艾潼 COURSE-000382 歷史錯帳', (string) $fresh->Note);
        $this->assertStringContainsString('user_id=', (string) $fresh->Note);
    }

    public function test_voided_invoice_is_excluded_from_student_class_invoice_list(): void
    {
        $token = $this->createToken('A', [1]);
        [, $course, $invoice] = $this->createInvoiceFixture(1, [
            'Status' => 'unpaid',
            'billing_period' => '2026-04',
        ]);

        $this->withHeaders([
            'Authorization' => "Bearer {$token}",
            'Accept' => 'application/json',
        ])->postJson("/api/v1/invoices/{$invoice->id}/void", [
            'reason' => 'duplicate monthly invoice',
        ])->assertOk();

        $res = $this->withHeaders([
            'Authorization' => "Bearer {$token}",
            'Accept' => 'application/json',
        ])->getJson("/api/v1/student-classes/{$course->ID}/invoices");

        $res->assertOk();
        $ids = collect($res->json('invoices'))->pluck('id')->map(fn ($id) => (int) $id)->all();
        $this->assertNotContains((int) $invoice->id, $ids);
    }

    public function test_paid_or_partial_invoice_cannot_be_voided_directly(): void
    {
        $token = $this->createToken('A', [1]);
        [, , $paidInvoice] = $this->createInvoiceFixture(1, [
            'Status' => 'paid',
            'PaidAmount' => 8800,
        ]);

        $this->withHeaders([
            'Authorization' => "Bearer {$token}",
            'Accept' => 'application/json',
        ])->postJson("/api/v1/invoices/{$paidInvoice->id}/void", [
            'reason' => 'wrong paid invoice',
        ])->assertStatus(422);

        [, , $invoiceWithPayment] = $this->createInvoiceFixture(1, [
            'Status' => 'unpaid',
            'PaidAmount' => 0,
        ]);
        Payment::create([
            'InvoiceID' => $invoiceWithPayment->id,
            'Amount' => 1000,
            'PaidAt' => '2026-04-10',
            'Method' => 'cash',
        ]);

        $this->withHeaders([
            'Authorization' => "Bearer {$token}",
            'Accept' => 'application/json',
        ])->postJson("/api/v1/invoices/{$invoiceWithPayment->id}/void", [
            'reason' => 'has payment row',
        ])->assertStatus(422);
    }

    public function test_invoice_void_requires_reason_and_same_campus(): void
    {
        $sameCampusToken = $this->createToken('A', [1]);
        $otherCampusToken = $this->createToken('A', [2]);
        [, , $invoice] = $this->createInvoiceFixture(1);

        $this->withHeaders([
            'Authorization' => "Bearer {$sameCampusToken}",
            'Accept' => 'application/json',
        ])->postJson("/api/v1/invoices/{$invoice->id}/void", [
            'reason' => '',
        ])->assertStatus(422);

        $this->withHeaders([
            'Authorization' => "Bearer {$otherCampusToken}",
            'Accept' => 'application/json',
        ])->postJson("/api/v1/invoices/{$invoice->id}/void", [
            'reason' => 'cross-campus attempt',
        ])->assertStatus(403);
    }

    public function test_teacher_cannot_void_invoice(): void
    {
        $teacherToken = $this->createToken('T', [1]);
        [, , $invoice] = $this->createInvoiceFixture(1);

        $this->withHeaders([
            'Authorization' => "Bearer {$teacherToken}",
            'Accept' => 'application/json',
        ])->postJson("/api/v1/invoices/{$invoice->id}/void", [
            'reason' => 'teacher attempt',
        ])->assertStatus(403);
    }

    private function createToken(string $type, array $campusIds): string
    {
        $user = User::create([
            'LoginName' => strtolower($type) . '_' . uniqid() . '@test.com',
            'Name' => 'Test User',
            'PSW' => 'secret',
            'type' => $type,
            'phone' => 912345678,
        ]);

        foreach ($campusIds as $campusId) {
            UserCampus::create([
                'CampusID' => $campusId,
                'UserID' => $user->id,
                'Admin' => $type === 'T' ? 0 : 1,
                'Approved' => 1,
            ]);
        }

        $raw = bin2hex(random_bytes(16));
        AuthToken::create([
            'user_id' => $user->id,
            'token' => $raw,
            'expires_at' => Carbon::now()->addDay(),
        ]);

        return $raw;
    }

    /**
     * @return array{0: Student, 1: StudentClass, 2: Invoice}
     */
    private function createInvoiceFixture(int $campusId, array $invoiceOverrides = []): array
    {
        $student = Student::create([
            'name' => '吳艾潼',
            'CampusID' => $campusId,
            'ClassID' => 1,
            'enable' => 1,
            'Phone' => '0912345678',
            'MDT' => now(),
            'Notify_Token' => '',
        ]);

        $course = StudentClass::create([
            'StudentID' => $student->id,
            'GradeID' => 1,
            'SubjectID' => 1,
            'TeacherID' => 99,
            'by1' => 1,
            'Period' => 4,
            'StartDate' => now(),
            'TotalHours' => 20,
            'Charge' => 8800,
            'Paid' => 0,
            'Rate' => null,
            'RoomID' => 'R1',
            'MDate' => now(),
            'Stop' => 0,
            'ScheduleMode' => 'date',
            'SessionCount' => 0,
            'SessionDuration' => 120,
            'RemainingSessions' => 0,
            'ClassType' => 'one_on_one',
            'UsedSessions' => 0,
        ]);

        $invoice = Invoice::create(array_merge([
            'StudentID' => $student->id,
            'StudentClassID' => $course->ID,
            'IssueDate' => '2026-04-01',
            'DueDate' => '2026-04-15',
            'TotalAmount' => 8800,
            'PaidAmount' => 0,
            'Status' => 'unpaid',
            'Note' => '',
            'billing_period' => '2026-04',
        ], $invoiceOverrides));

        return [$student, $course, $invoice];
    }
}
