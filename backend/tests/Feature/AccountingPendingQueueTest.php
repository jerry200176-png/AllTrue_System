<?php

namespace Tests\Feature;

use App\Models\AuthToken;
use App\Models\Invoice;
use App\Models\Payment;
use App\Models\Student;
use App\Models\StudentClass;
use App\Models\User;
use App\Models\UserCampus;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/** F7 S3a: 待對帳 is "stopped and the resolver says it still owes", not a closed_reason allowlist. */
class AccountingPendingQueueTest extends TestCase
{
    use RefreshDatabase;

    private array $headers;

    protected function setUp(): void
    {
        parent::setUp();
        $user = User::create(['LoginName' => 'pq-' . uniqid() . '@example.com', 'Name' => '帳務', 'PSW' => 'secret', 'type' => 'D', 'phone' => '0900000000']);
        UserCampus::create(['CampusID' => 1, 'UserID' => $user->id, 'Admin' => 1, 'Approved' => 1]);
        $token = bin2hex(random_bytes(16));
        AuthToken::create(['user_id' => $user->id, 'token' => $token, 'expires_at' => now()->addHour()]);
        $this->headers = ['Authorization' => "Bearer {$token}"];
    }

    private function course(array $o = []): StudentClass
    {
        $student = Student::create(['name' => '待對帳測試生', 'CampusID' => 1, 'ClassID' => 1, 'SchoolName' => 'T', 'enable' => 1, 'MDT' => now(), 'Notify_Token' => '']);

        return StudentClass::create(array_merge([
            'StudentID' => $student->id, 'GradeID' => 1, 'SubjectID' => 1, 'TeacherID' => 99, 'by1' => 1, 'Period' => 4,
            'StartDate' => '2026-04-01', 'TotalHours' => 20, 'Charge' => 8800, 'Paid' => 0, 'Rate' => 0, 'MDate' => now(),
            'Stop' => 1, 'ScheduleMode' => 'count', 'SessionCount' => 8, 'SessionDuration' => 60, 'RemainingSessions' => 4,
            'ClassType' => 'one_on_one', 'UsedSessions' => 0,
        ], $o));
    }

    private function invoice(StudentClass $c, int $total, string $status = 'unpaid', int $paid = 0): Invoice
    {
        $invoice = Invoice::create(['StudentID' => $c->StudentID, 'StudentClassID' => $c->ID, 'IssueDate' => '2026-09-01',
            'DueDate' => '2026-09-10', 'TotalAmount' => $total, 'PaidAmount' => $paid, 'Status' => $status]);
        if ($paid > 0) {
            Payment::create(['InvoiceID' => $invoice->id, 'Amount' => $paid, 'PaidAt' => '2026-09-05', 'Method' => 'cash']);
        }

        return $invoice;
    }

    private function row(StudentClass $c): ?array
    {
        return collect($this->getJson("/api/v1/accounting/settled-courses?branch_id=1&course_id={$c->ID}", $this->headers)
            ->assertOk()->json('data'))->first();
    }

    private function tuitionRow(StudentClass $c): ?array
    {
        return collect($this->getJson('/api/v1/alerts/tuition?branch_id=1', $this->headers)->assertOk()->json())
            ->first(fn ($r) => (int) ($r['id'] ?? 0) === (int) $c->ID);
    }

    public function test_paused_contract_with_outstanding_is_listed_as_paused_pending(): void
    {
        $c = $this->course(['closed_reason' => null]);
        $this->invoice($c, 8800, 'unpaid', 3000);

        $row = $this->row($c);
        $this->assertTrue($row['pending_reconciliation']);
        $this->assertSame(5800, $row['outstanding_amount']);
        $this->assertSame('暫停中 · 待對帳', $row['reconciliation_label']);

        $alert = $this->tuitionRow($c);
        $this->assertSame('pending_reconciliation', $alert['payment_status']);
        $this->assertSame(5800, $alert['outstanding']);
    }

    public function test_settled_reason_with_unpaid_invoice_is_pending(): void
    {
        $c = $this->course(['closed_reason' => 'settled']);
        $this->invoice($c, 8800);

        $row = $this->row($c);
        $this->assertTrue($row['pending_reconciliation']);
        $this->assertSame(8800, $row['outstanding_amount']);
        $this->assertSame('結案待對帳', $row['reconciliation_label']);
        $this->assertNotNull($this->tuitionRow($c));
    }

    public function test_fully_paid_stopped_contract_is_not_pending(): void
    {
        $c = $this->course(['closed_reason' => 'settled', 'Paid' => 1]);
        $this->invoice($c, 8800, 'paid', 8800);

        $row = $this->row($c);
        $this->assertFalse($row['pending_reconciliation']);
        $this->assertSame(0, $row['outstanding_amount']);
        $this->assertNull($this->tuitionRow($c));
    }

    public function test_waived_is_history_with_zero_outstanding(): void
    {
        $c = $this->course(['closed_reason' => null]);
        $this->invoice($c, 8800);
        $c->forceFill(['closed_reason' => 'waived'])->saveQuietly(); // model guard blocks invoices on waived courses

        $row = $this->row($c);
        $this->assertFalse($row['pending_reconciliation']);
        $this->assertFalse($row['payment_review_required']);
        $this->assertSame(0, $row['outstanding_amount']);
        $this->assertSame('歷史 · 確認不收', $row['reconciliation_label']);
        $this->assertNull($this->tuitionRow($c));
    }

    public function test_review_required_is_its_own_visible_state(): void
    {
        $c = $this->course(['closed_reason' => null]);
        $this->invoice($c, 0, 'unpaid'); // positive charge, only a zero-value invoice => resolver review_required

        $row = $this->row($c);
        $this->assertNotNull($row);
        $this->assertTrue($row['payment_review_required']);
        $this->assertFalse($row['pending_reconciliation']);
        $this->assertSame('付款期間待確認', $row['reconciliation_label']);
    }
}
