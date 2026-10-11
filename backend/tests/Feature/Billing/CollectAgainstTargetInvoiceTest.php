<?php

namespace Tests\Feature\Billing;

use App\Models\AuthToken;
use App\Models\Invoice;
use App\Models\Payment;
use App\Models\PaymentReport;
use App\Models\Student;
use App\Models\StudentClass;
use App\Models\User;
use App\Models\UserCampus;
use App\Services\InvoiceAmountReconciliationService;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * #3864 hotfix (Codex P1s): a Paid=1 contract with open invoices is collectible (Founder Q2), but only against what the
 * TARGET bill still owes by its Payment rows — never the course-wide total, never the stored PaidAmount.
 */
class CollectAgainstTargetInvoiceTest extends TestCase
{
    use RefreshDatabase;

    private array $headers;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow('2026-04-28 10:00:00');
        $user = User::create(['LoginName' => 'hotfix3864@example.com', 'Name' => 'd', 'PSW' => 'secret', 'type' => 'A', 'phone' => 912345678]);
        UserCampus::create(['CampusID' => 1, 'UserID' => $user->id, 'Admin' => 1, 'Approved' => 1]);
        $token = bin2hex(random_bytes(16));
        AuthToken::create(['user_id' => $user->id, 'token' => $token, 'expires_at' => now()->addDay()]);
        $this->headers = ['Authorization' => "Bearer $token", 'Accept' => 'application/json'];
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    /** P1-a: invoice A owes 1,000, invoice B owes 8,000 → NT$8,000 aimed at A must not land on A. */
    public function test_director_record_caps_at_the_target_invoice_not_the_course_total(): void
    {
        [$sc, $a] = $this->twoOpenInvoices();

        $this->record($sc, 8000, $a->id)->assertStatus(422)->assertJsonPath('code', 'amount_exceeds_outstanding')
            ->assertJsonPath('message', '這張帳單只欠 NT$1000，請改成 1000 以下，或分到其他帳單。');
        $this->assertSame(0, PaymentReport::count());

        $this->confirm($this->record($sc, 1000, $a->id)->assertOk()->json('report_id'))->assertOk();
        $this->assertMatchesPaymentRows($a, 8800, 'paid');
    }

    public function test_confirm_rechecks_the_target_invoice_before_writing_a_payment(): void
    {
        [$sc, $a] = $this->twoOpenInvoices();
        $report = $this->pendingReport($sc, $a, 8000); // e.g. recorded before this hotfix

        $this->confirm($report->id)->assertStatus(422)->assertJsonPath('code', 'amount_exceeds_outstanding');
        $this->assertSame(7800, (int) Payment::where('InvoiceID', $a->id)->sum('Amount'));
        $this->assertSame('pending', $report->fresh()->status);
    }

    public function test_batch_record_caps_each_entry_at_its_target_invoice(): void
    {
        [$sc, $a] = $this->twoOpenInvoices();

        $res = $this->withHeaders($this->headers)->postJson('/api/v1/payment-reports/director-record-batch', [
            'payment_date' => '2026-04-28', 'payment_method' => 'cash',
            'entries' => [['student_class_id' => $sc->ID, 'invoice_id' => $a->id, 'amount' => 8000]],
        ]);

        $res->assertStatus(207)->assertJsonPath('results.0.http_status', 422)->assertJsonPath('results.0.code', 'amount_exceeds_outstanding');
        $this->assertSame(0, PaymentReport::count());
    }

    /** Two confirms on the same bill: the second sees the first's Payment row and is refused. */
    public function test_second_confirm_on_the_same_invoice_is_refused(): void
    {
        [$sc, $a] = $this->twoOpenInvoices();
        $first = $this->pendingReport($sc, $a, 600);
        $second = $this->pendingReport($sc, $a, 600);

        $this->confirm($first->id)->assertOk();
        $this->confirm($second->id)->assertStatus(422)->assertJsonPath('expected_amount', 400);
        $this->assertMatchesPaymentRows($a, 8400, 'partial');
        $this->assertSame('pending', $second->fresh()->status);
    }

    /** P1-b: NT$8,800 monthly bill stored partial/PaidAmount=3000 but no Payment rows → all 8,800 is still owed. */
    public function test_monthly_settlement_uses_payment_rows_not_the_stale_stored_paid_amount(): void
    {
        $sc = $this->course(['ScheduleMode' => 'date', 'Charge' => 8800, 'Paid' => 1]);
        $inv = Invoice::create(['StudentID' => $sc->StudentID, 'StudentClassID' => $sc->ID, 'IssueDate' => '2026-04-01',
            'TotalAmount' => 8800, 'PaidAmount' => 3000, 'Status' => 'partial', 'billing_period' => '2026-04']);

        $this->record($sc, 5800, $inv->id)->assertStatus(422)->assertJsonPath('expected_amount', 8800);
        $this->confirm($this->record($sc, 8800, $inv->id)->assertOk()->json('report_id'))->assertOk();

        $this->assertMatchesPaymentRows($inv, 8800, 'paid');
    }

    public function test_confirm_writes_paid_amount_from_payment_rows_never_stored_plus_new(): void
    {
        $sc = $this->course(['ScheduleMode' => 'date', 'Charge' => 8800, 'Paid' => 1]);
        $inv = Invoice::create(['StudentID' => $sc->StudentID, 'StudentClassID' => $sc->ID, 'IssueDate' => '2026-04-01',
            'TotalAmount' => 8800, 'PaidAmount' => 3000, 'Status' => 'partial', 'billing_period' => '2026-04']);
        $report = $this->pendingReport($sc, $inv, 5800);

        $this->confirm($report->id)->assertOk();

        // Stored+new would claim 8,800 "paid" with only 5,800 in Payment rows and close the bill.
        $this->assertMatchesPaymentRows($inv, 5800, 'partial');
    }

    /** P2: the fallback skips a stale-status bill whose Payment rows cover it and lands on the bill that still owes. */
    public function test_fallback_skips_a_covered_stale_invoice_for_one_that_still_owes(): void
    {
        $sc = $this->course(['Paid' => 1]);
        // The covered bill is the current period, which the lookup tries first.
        $covered = Invoice::create(['StudentID' => $sc->StudentID, 'StudentClassID' => $sc->ID, 'IssueDate' => '2026-04-01',
            'TotalAmount' => 5000, 'PaidAmount' => 0, 'Status' => 'unpaid', 'billing_period' => '2026-04']);
        Payment::create(['InvoiceID' => $covered->id, 'Amount' => 5000, 'PaidAt' => '2026-04-05', 'Method' => 'cash']);
        $owing = Invoice::create(['StudentID' => $sc->StudentID, 'StudentClassID' => $sc->ID, 'IssueDate' => '2026-03-01',
            'TotalAmount' => 3000, 'PaidAmount' => 0, 'Status' => 'unpaid', 'billing_period' => '2026-03']);

        $res = $this->record($sc, 3000)->assertOk()->assertJsonPath('invoice_id', $owing->id);
        $this->confirm($res->json('report_id'))->assertOk();
        $this->assertMatchesPaymentRows($owing, 3000, 'paid');

        // Naming the covered bill explicitly is still refused.
        $this->record($sc, 100, $covered->id)->assertStatus(422)->assertJsonPath('code', 'course_already_paid');
    }

    /** @return array{0: StudentClass, 1: Invoice} count-mode Paid=1; A owes 1,000 (8,800 − 7,800), B owes 8,000 */
    private function twoOpenInvoices(): array
    {
        $sc = $this->course(['Paid' => 1, 'Charge' => 16800]);
        $a = Invoice::create(['StudentID' => $sc->StudentID, 'StudentClassID' => $sc->ID, 'IssueDate' => '2026-03-01',
            'TotalAmount' => 8800, 'PaidAmount' => 7800, 'Status' => 'partial', 'billing_period' => '2026-03']);
        Payment::create(['InvoiceID' => $a->id, 'Amount' => 7800, 'PaidAt' => '2026-03-05', 'Method' => 'cash']);
        Invoice::create(['StudentID' => $sc->StudentID, 'StudentClassID' => $sc->ID, 'IssueDate' => '2026-04-01',
            'TotalAmount' => 8000, 'PaidAmount' => 0, 'Status' => 'unpaid', 'billing_period' => '2026-04']);

        return [$sc, $a];
    }

    private function assertMatchesPaymentRows(Invoice $invoice, int $paid, string $status): void
    {
        $fresh = Invoice::with(['payments', 'items'])->findOrFail($invoice->id);
        $p = app(InvoiceAmountReconciliationService::class)->resolve($fresh);
        $this->assertSame($paid, $p['net_applied'], 'Payment rows');
        $this->assertLessThanOrEqual($p['total_amount'], $p['net_applied'], 'Payment rows never exceed the bill');
        $this->assertSame($paid, (int) $fresh->PaidAmount, 'stored PaidAmount = Payment rows');
        $this->assertSame($status, (string) $fresh->Status);
    }

    private function record(StudentClass $sc, int $amount, ?int $invoiceId = null)
    {
        return $this->withHeaders($this->headers)->postJson('/api/v1/payment-reports/director-record', array_filter([
            'student_class_id' => $sc->ID, 'invoice_id' => $invoiceId, 'payment_date' => '2026-04-28', 'payment_method' => 'cash', 'amount' => $amount,
        ]));
    }

    private function confirm($reportId)
    {
        return $this->withHeaders($this->headers)->putJson("/api/v1/payment-reports/$reportId/confirm");
    }

    private function pendingReport(StudentClass $sc, Invoice $invoice, int $amount): PaymentReport
    {
        return PaymentReport::create(['StudentID' => $sc->StudentID, 'StudentClassID' => $sc->ID, 'InvoiceID' => $invoice->id,
            'reported_by_name' => 'x', 'payment_date' => '2026-04-28', 'payment_method' => 'cash', 'reported_amount' => $amount,
            'status' => 'pending', 'report_token_hash' => hash('sha256', uniqid('r', true)), 'token_expires_at' => now()->addDay()]);
    }

    private function course(array $overrides): StudentClass
    {
        $student = Student::create(['name' => 's' . uniqid(), 'CampusID' => 1, 'ClassID' => 1, 'enable' => 1, 'Phone' => '0912345678',
            'MDT' => now(), 'Notify_Token' => '']);

        return StudentClass::create(array_merge([
            'StudentID' => $student->id, 'GradeID' => 1, 'SubjectID' => 1, 'TeacherID' => 99, 'by1' => 1, 'Period' => 4,
            'StartDate' => now(), 'TotalHours' => 20, 'Charge' => 8800, 'Paid' => 0, 'MDate' => now(), 'Stop' => 0,
            'ScheduleMode' => 'count', 'SessionCount' => 8, 'SessionDuration' => 120, 'RemainingSessions' => 5,
            'ClassType' => 'one_on_one', 'UsedSessions' => 0,
        ], $overrides));
    }
}
