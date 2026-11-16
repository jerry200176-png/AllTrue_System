<?php

namespace Tests\Feature\Billing;

use App\Models\AuthToken;
use App\Models\Invoice;
use App\Models\InvoiceItem;
use App\Models\Payment;
use App\Models\PaymentReport;
use App\Models\Student;
use App\Models\StudentClass;
use App\Models\User;
use App\Models\UserCampus;
use App\Services\BillingPayableResolver;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Founder 2026-11-11 (in-app #531, #5160): a standalone count course with no bill is billed at its contract Charge when a
 * payment is confirmed (the payment records only what came in); when the only receipt of such an auto-issued bill is
 * reversed, that bill is voided with it. Everything else (stamp-less bills, other receipts, amendments) is left alone.
 */
class FirstBillAtContractChargeTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow('2026-04-28 10:00:00');
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    public function test_confirm_below_charge_bills_the_contract_charge_and_records_only_the_received_amount(): void
    {
        [$h, $sc] = $this->fixture(20000);
        $this->confirmEntered($h, $sc, 15000);

        $invoice = Invoice::where('StudentClassID', $sc->ID)->sole();
        $this->assertSame(20000, (int) $invoice->TotalAmount);
        $this->assertSame(15000, (int) Payment::where('InvoiceID', $invoice->id)->sole()->Amount);
        $this->assertSame('partial', $invoice->Status);
        $this->assertSame(15000, (int) $invoice->PaidAmount);
        $this->assertSame(['contract_charge_first_bill', 20000], [$invoice->billing_snapshot['source'], $invoice->billing_snapshot['contract_charge']]);
        $row = $this->courseRow($sc);
        $this->assertSame(['partial', 5000], [$row['status'], $row['outstanding']]);
        $this->assertSame(0, (int) $sc->refresh()->Paid);
    }

    public function test_confirm_equal_to_charge_is_fully_paid(): void
    {
        [$h, $sc] = $this->fixture(20000);
        $this->confirmEntered($h, $sc, 20000);

        $invoice = Invoice::where('StudentClassID', $sc->ID)->sole();
        $this->assertSame([20000, 'paid'], [(int) $invoice->TotalAmount, $invoice->Status]);
        $this->assertSame(1, (int) $sc->refresh()->Paid);
    }

    public function test_entry_above_charge_is_still_refused_at_record_and_at_confirm(): void
    {
        [$h, $sc] = $this->fixture(20000);
        $this->withHeaders($h)->postJson('/api/v1/payment-reports/director-record', $this->recordBody($sc, 22000))
            ->assertStatus(422)->assertJsonPath('code', 'amount_exceeds_outstanding');

        $report = $this->pendingReport($sc, 22000, 'over');
        $this->withHeaders($h)->putJson("/api/v1/payment-reports/{$report->id}/confirm")
            ->assertStatus(422)->assertJsonPath('code', 'amount_exceeds_outstanding');
        $this->assertSame(0, Invoice::where('StudentClassID', $sc->ID)->count());
        $this->assertSame(0, Payment::count());
    }

    public function test_reversing_the_only_receipt_voids_the_auto_issued_bill_and_the_course_is_unbilled_again(): void
    {
        [$h, $sc] = $this->fixture(20000);
        $reportId = $this->confirmEntered($h, $sc, 15000);

        $this->withHeaders($h)->putJson("/api/v1/payment-reports/{$reportId}/void", ['void_reason' => '輸入錯誤'])->assertOk();

        $invoice = Invoice::where('StudentClassID', $sc->ID)->sole();
        $this->assertSame('void', $invoice->Status);
        $this->assertSame(2, Payment::where('InvoiceID', $invoice->id)->count()); // receipt + its reversal stay as the audit trail
        $row = $this->courseRow($sc);
        $this->assertSame(['unbilled', 20000], [$row['status'], $row['outstanding']]);
        $this->assertSame(0, (int) $sc->refresh()->Paid);
        // the director can record again and is billed at the contract amount again
        $this->confirmEntered($h, $sc, 20000);
        $this->assertSame(1, Invoice::where('StudentClassID', $sc->ID)->where('Status', 'paid')->count());
    }

    /**
     * Founder 2026-11-16 (course 1907; 80 such bills in production): an old 「0 元」 登記 left a NT$0 bill marked paid, which
     * could be voided neither way. Undoing the NT$0 receipt now takes the bill with it and the course is 未繳 again.
     */
    public function test_undoing_a_zero_amount_receipt_takes_its_unmarked_zero_bill_with_it_and_the_course_can_be_recorded(): void
    {
        [$h, $sc] = $this->fixture(6000, ['Paid' => 1, 'PayDate' => '2026-04-20']);
        [$invoice, $report] = $this->zeroBill($sc);
        $this->assertSame('review_required', $this->courseRow($sc)['status']); // a NT$0 bill is not payment evidence
        // The bill itself cannot be voided while its receipt stands: the receipt is undone first.
        $this->withHeaders($h)->postJson("/api/v1/invoices/{$invoice->id}/void", ['reason' => '金額登記錯誤'])->assertStatus(422);

        $this->withHeaders($h)->putJson("/api/v1/payment-reports/{$report->id}/void", ['void_reason' => '金額登記錯誤'])->assertOk();

        $this->assertSame('void', $invoice->refresh()->Status);
        $this->assertSame('voided', $report->refresh()->status);
        $this->assertSame([0, null], [(int) $sc->refresh()->Paid, $sc->PayDate]);
        $row = $this->courseRow($sc);
        $this->assertSame(['unbilled', 6000], [$row['status'], $row['outstanding']]);
        // The real amount can now be recorded.
        $this->confirmEntered($h, $sc, 6000);
        $this->assertSame(1, Invoice::where('StudentClassID', $sc->ID)->where('Status', 'paid')->where('TotalAmount', 6000)->count());
    }

    public function test_undoing_a_zero_amount_receipt_keeps_the_course_settled_when_another_paid_bill_backs_it(): void
    {
        [$h, $sc] = $this->fixture(6000, ['Paid' => 1, 'PayDate' => '2026-04-20']);
        $real = Invoice::create(['StudentID' => $sc->StudentID, 'StudentClassID' => $sc->ID, 'IssueDate' => '2026-04-20', 'TotalAmount' => 6000, 'PaidAmount' => 6000, 'Status' => 'paid']);
        Payment::create(['InvoiceID' => $real->id, 'Amount' => 6000, 'PaidAt' => '2026-04-20', 'Method' => 'cash']);
        [$zero, $report] = $this->zeroBill($sc);

        $this->withHeaders($h)->putJson("/api/v1/payment-reports/{$report->id}/void", ['void_reason' => '重複登記'])->assertOk();

        $this->assertSame('void', $zero->refresh()->Status);
        $this->assertSame('paid', $real->refresh()->Status);
        $this->assertSame(1, (int) $sc->refresh()->Paid); // the real bill still settles the course
        $this->assertSame('paid', $this->courseRow($sc)['status']);
    }

    public function test_a_zero_bill_with_no_receipt_left_is_voidable_and_a_marked_or_shared_one_is_left_alone(): void
    {
        // No confirmed receipt on it: voidable directly, and the ledger offers it.
        [$h, $sc] = $this->fixture(6000, ['Paid' => 1, 'PayDate' => '2026-04-20']);
        $bare = Invoice::create(['StudentID' => $sc->StudentID, 'StudentClassID' => $sc->ID, 'IssueDate' => '2026-04-20', 'TotalAmount' => 0, 'PaidAmount' => 0, 'Status' => 'paid']);
        $ledger = collect($this->withHeaders($h)->getJson("/api/v1/accounting/ledger?student_class_id={$sc->ID}")->assertOk()->json('invoices'))->firstWhere('id', $bare->id);
        $this->assertTrue($ledger['can_direct_void']);
        $this->withHeaders($h)->postJson("/api/v1/invoices/{$bare->id}/void", ['reason' => '金額登記錯誤'])->assertOk();
        $this->assertSame('void', $bare->refresh()->Status);
        // The stale 已繳 flag goes with its last bill, so the real amount can be recorded.
        $this->assertSame(0, (int) $sc->refresh()->Paid);
        $this->assertSame(['unbilled', 6000], array_values($this->courseRow($sc)));
        $this->confirmEntered($h, $sc, 6000);

        // A fully refunded bill is NT$0 and paid too, but money moved on it: its history is not offered for voiding.
        [$hr, $refunded] = $this->fixture(6000, ['Paid' => 1]);
        $back = Invoice::create(['StudentID' => $refunded->StudentID, 'StudentClassID' => $refunded->ID, 'IssueDate' => '2026-04-20', 'TotalAmount' => 0, 'PaidAmount' => 0, 'Status' => 'paid']);
        Payment::create(['InvoiceID' => $back->id, 'Amount' => 6000, 'PaidAt' => '2026-04-20', 'Method' => 'cash']);
        Payment::create(['InvoiceID' => $back->id, 'Amount' => -6000, 'PaidAt' => '2026-04-25', 'Method' => 'refund']);
        $row = collect($this->withHeaders($hr)->getJson("/api/v1/accounting/ledger?student_class_id={$refunded->ID}")->assertOk()->json('invoices'))->firstWhere('id', $back->id);
        $this->assertFalse($row['can_direct_void']);
        $this->withHeaders($hr)->postJson("/api/v1/invoices/{$back->id}/void", ['reason' => '金額登記錯誤'])->assertStatus(422);
        $this->assertSame(1, (int) $refunded->refresh()->Paid);

        // Marked 免費／贈送: NT$0 by decision. Undoing its receipt leaves the bill.
        [$h2, $free] = $this->fixture(6000, ['Paid' => 1]);
        [$marked, $markedReport] = $this->zeroBill($free, ['zero_amount_reason' => Invoice::ZERO_REASON_FREE_GIFT]);
        $this->withHeaders($h2)->putJson("/api/v1/payment-reports/{$markedReport->id}/void", ['void_reason' => 'x'])->assertOk();
        $this->assertNotSame('void', $marked->refresh()->Status);

        // A second confirmed receipt on the same bill: the bill stays for it.
        [$h3, $two] = $this->fixture(6000, ['Paid' => 1]);
        [$shared, $first] = $this->zeroBill($two);
        $second = $this->pendingReport($two, 0, 'second-zero', $shared->id);
        $second->update(['status' => 'confirmed']);
        $this->withHeaders($h3)->putJson("/api/v1/payment-reports/{$first->id}/void", ['void_reason' => 'x'])->assertOk();
        $this->assertNotSame('void', $shared->refresh()->Status);
    }

    public function test_reversing_one_of_two_receipts_keeps_the_bill(): void
    {
        [$h, $sc] = $this->fixture(20000);
        $first = $this->confirmEntered($h, $sc, 10000);
        $second = $this->confirmEntered($h, $sc, 5000);

        $this->withHeaders($h)->putJson("/api/v1/payment-reports/{$second}/void", ['void_reason' => 'x'])->assertOk();
        $invoice = Invoice::where('StudentClassID', $sc->ID)->sole();
        $this->assertNotSame('void', $invoice->Status);
        $this->assertSame([20000, 10000], [(int) $invoice->TotalAmount, (int) $invoice->PaidAmount]);

        // Fail closed: an earlier reversal row exists on the bill, so it is no longer a lone first receipt; the last reversal leaves it too.
        $this->withHeaders($h)->putJson("/api/v1/payment-reports/{$first}/void", ['void_reason' => 'y'])->assertOk();
        $this->assertNotSame('void', $invoice->refresh()->Status);
    }

    public function test_reversing_the_first_receipt_while_a_second_one_exists_keeps_the_bill(): void
    {
        [$h, $sc] = $this->fixture(20000);
        $first = $this->confirmEntered($h, $sc, 10000);
        $this->confirmEntered($h, $sc, 5000);

        $this->withHeaders($h)->putJson("/api/v1/payment-reports/{$first}/void", ['void_reason' => 'x'])->assertOk();
        $this->assertNotSame('void', Invoice::where('StudentClassID', $sc->ID)->sole()->Status);
    }

    public function test_a_bill_not_auto_issued_at_confirmation_is_untouched_by_reversal(): void
    {
        [$h, $sc] = $this->fixture(20000);
        $invoice = Invoice::create(['StudentID' => $sc->StudentID, 'StudentClassID' => $sc->ID, 'IssueDate' => '2026-04-01',
            'TotalAmount' => 20000, 'PaidAmount' => 0, 'Status' => 'unpaid']);
        $reportId = $this->confirmOn($h, $sc, $invoice, 8000);

        $this->withHeaders($h)->putJson("/api/v1/payment-reports/{$reportId}/void", ['void_reason' => 'x'])->assertOk();
        $this->assertSame('unpaid', $invoice->refresh()->Status);
    }

    public function test_a_stamp_for_another_report_or_an_amended_total_or_extra_lines_never_auto_void(): void
    {
        foreach (['other_report', 'amended_total', 'has_item', 'has_correction'] as $case) {
            [$h, $sc] = $this->fixture(20000);
            $reportId = $this->confirmEntered($h, $sc, 9000);
            $invoice = Invoice::where('StudentClassID', $sc->ID)->sole();
            match ($case) {
                'other_report' => $invoice->update(['billing_snapshot' => array_merge($invoice->billing_snapshot, ['payment_report_id' => $reportId + 999])]),
                'amended_total' => $invoice->update(['TotalAmount' => 19000]),
                'has_item' => InvoiceItem::create(['InvoiceID' => $invoice->id, 'StudentClassID' => $sc->ID, 'Description' => 'x', 'Amount' => 100]),
                'has_correction' => DB::table('invoice_corrections')->insert([
                    'original_invoice_id' => $invoice->id, 'replacement_invoice_id' => $invoice->id + 100000, 'student_class_id' => $sc->ID,
                    'payment_report_id' => null, 'amount' => 1, 'reason' => 'synthetic', 'idempotency_key' => str_repeat('a', 36),
                ]),
            };
            $this->withHeaders($h)->putJson("/api/v1/payment-reports/{$reportId}/void", ['void_reason' => 'x'])->assertOk();
            $this->assertNotSame('void', $invoice->refresh()->Status, $case);
        }
    }

    public function test_another_pending_report_on_the_bill_keeps_it_open_and_confirmable(): void
    {
        [$h, $sc] = $this->fixture(20000);
        $reportId = $this->confirmEntered($h, $sc, 9000);
        $invoice = Invoice::where('StudentClassID', $sc->ID)->sole();
        $pending = $this->pendingReport($sc, 4000, 'stranded', $invoice->id);

        $this->withHeaders($h)->putJson("/api/v1/payment-reports/{$reportId}/void", ['void_reason' => 'x'])->assertOk();
        $this->assertNotSame('void', $invoice->refresh()->Status);
        $this->withHeaders($h)->putJson("/api/v1/payment-reports/{$pending->id}/confirm")->assertOk();
    }

    public function test_auto_void_audit_tag_survives_a_long_note(): void
    {
        [$h, $sc] = $this->fixture(20000);
        $reportId = $this->confirmEntered($h, $sc, 9000);
        $invoice = Invoice::where('StudentClassID', $sc->ID)->sole();
        $invoice->update(['Note' => str_repeat('長', 250)]);

        $this->withHeaders($h)->putJson("/api/v1/payment-reports/{$reportId}/void", ['void_reason' => 'x'])->assertOk();
        $invoice->refresh();
        $this->assertSame('void', $invoice->Status);
        $this->assertLessThanOrEqual(255, mb_strlen($invoice->Note));
        $this->assertStringContainsString('[void: 唯一收款撤銷，系統自動作廢;', $invoice->Note);
    }

    public function test_courses_without_a_usable_charge_keep_billing_the_entered_amount(): void
    {
        [$h, $sc] = $this->fixture(0, ['Rate' => 1100]);
        $report = $this->pendingReport($sc, 3000, 'nocharge');
        $this->withHeaders($h)->putJson("/api/v1/payment-reports/{$report->id}/confirm")->assertOk();

        $invoice = Invoice::where('StudentClassID', $sc->ID)->sole();
        $this->assertSame(3000, (int) $invoice->TotalAmount);
        $this->assertNull($invoice->billing_snapshot);
    }

    public function test_monthly_course_is_not_billed_at_the_contract_charge(): void
    {
        [$h, $sc] = $this->fixture(20000, ['ScheduleMode' => 'date', 'SessionCount' => null]);
        $report = $this->pendingReport($sc, 3000, 'monthly');
        $this->withHeaders($h)->putJson("/api/v1/payment-reports/{$report->id}/confirm");

        $invoice = Invoice::where('StudentClassID', $sc->ID)->first();
        $this->assertTrue($invoice === null || ($invoice->billing_snapshot['source'] ?? null) !== 'contract_charge_first_bill');
    }

    public function test_another_campus_director_can_neither_confirm_nor_reverse(): void
    {
        [$h, $sc] = $this->fixture(20000);
        $other = ['Authorization' => 'Bearer ' . $this->token([2]), 'Accept' => 'application/json'];
        $report = $this->pendingReport($sc, 9000, 'campus');
        $this->withHeaders($other)->putJson("/api/v1/payment-reports/{$report->id}/confirm")->assertStatus(403);
        $this->assertSame(0, Invoice::where('StudentClassID', $sc->ID)->count());
        $report->update(['status' => 'rejected']); // a pending report would block the next record

        $reportId = $this->confirmEntered($h, $sc, 9000);
        $this->withHeaders($other)->putJson("/api/v1/payment-reports/{$reportId}/void", ['void_reason' => 'x'])->assertStatus(403);
        $this->assertNotSame('void', Invoice::where('StudentClassID', $sc->ID)->sole()->Status);
    }

    // ── helpers ──────────────────────────────────────────────────────────

    /** @return array{0: array<string,string>, 1: StudentClass} */
    private function fixture(int $charge, array $overrides = []): array
    {
        $student = Student::create(['name' => 'S' . uniqid(), 'CampusID' => 1, 'ClassID' => 1, 'enable' => 1, 'Phone' => '0912345678', 'MDT' => now(), 'Notify_Token' => '']);
        $sc = StudentClass::create(array_merge([
            'StudentID' => $student->id, 'GradeID' => 1, 'SubjectID' => 1, 'TeacherID' => 99, 'by1' => 1, 'Period' => 4,
            'StartDate' => now(), 'EndDate' => null, 'TotalHours' => 20, 'Memo' => null, 'Charge' => $charge, 'Pay' => null,
            'PayDate' => null, 'Paid' => 0, 'Disconunt' => null, 'Rate' => null, 'LearnTimeID' => null, 'MDate' => now(),
            'Stop' => 0, 'ScheduleMode' => 'count', 'SessionCount' => 8, 'SessionDuration' => 120, 'RemainingSessions' => 5,
            'ClassType' => 'one_on_one', 'UsedSessions' => 0,
        ], $overrides));

        return [['Authorization' => 'Bearer ' . $this->token([1]), 'Accept' => 'application/json'], $sc];
    }

    private function token(array $campusIds): string
    {
        $user = User::create(['LoginName' => 'd_' . uniqid() . '@test.com', 'Name' => 'D', 'PSW' => 'secret', 'type' => 'A', 'phone' => 912345678]);
        foreach ($campusIds as $cid) {
            UserCampus::create(['CampusID' => $cid, 'UserID' => $user->id, 'Admin' => 1, 'Approved' => 1]);
        }
        $raw = bin2hex(random_bytes(16));
        AuthToken::create(['user_id' => $user->id, 'token' => $raw, 'expires_at' => Carbon::now()->addDay()]);

        return $raw;
    }

    private function recordBody(StudentClass $sc, int $amount): array
    {
        return ['student_class_id' => $sc->ID, 'payment_date' => '2026-04-28', 'payment_method' => 'cash', 'amount' => $amount];
    }

    /** Director-record then confirm; returns the report id. */
    private function confirmEntered(array $h, StudentClass $sc, int $amount): int
    {
        $id = (int) $this->withHeaders($h)->postJson('/api/v1/payment-reports/director-record', $this->recordBody($sc, $amount))
            ->assertOk()->json('report_id');
        $this->withHeaders($h)->putJson("/api/v1/payment-reports/{$id}/confirm")->assertOk();

        return $id;
    }

    private function confirmOn(array $h, StudentClass $sc, Invoice $invoice, int $amount): int
    {
        $report = $this->pendingReport($sc, $amount, 'on-' . $amount, $invoice->id);
        $this->withHeaders($h)->putJson("/api/v1/payment-reports/{$report->id}/confirm")->assertOk();

        return (int) $report->id;
    }

    private function pendingReport(StudentClass $sc, int $amount, string $tag, ?int $invoiceId = null): PaymentReport
    {
        return PaymentReport::create([
            'StudentID' => $sc->StudentID, 'StudentClassID' => $sc->ID, 'InvoiceID' => $invoiceId, 'reported_by_name' => 'x',
            'payment_date' => '2026-04-28', 'payment_method' => 'cash', 'reported_amount' => $amount, 'status' => 'pending',
            'report_token_hash' => hash('sha256', $tag), 'token_expires_at' => Carbon::now()->addDay(),
        ]);
    }

    /**
     * The shape an old 「0 元」 登記 left behind: a NT$0 bill marked paid, a NT$0 payment row and its confirmed NT$0 receipt.
     *
     * @return array{0: Invoice, 1: PaymentReport}
     */
    private function zeroBill(StudentClass $sc, array $invoiceOverrides = []): array
    {
        $invoice = Invoice::create(array_merge(['StudentID' => $sc->StudentID, 'StudentClassID' => $sc->ID, 'IssueDate' => '2026-04-20',
            'TotalAmount' => 0, 'PaidAmount' => 0, 'Status' => 'paid'], $invoiceOverrides));
        $report = $this->pendingReport($sc, 0, 'zero-' . $invoice->id, $invoice->id);
        $payment = Payment::create(['InvoiceID' => $invoice->id, 'Amount' => 0, 'PaidAt' => '2026-04-20', 'Method' => 'transfer', 'payment_report_id' => $report->id]);
        $report->update(['status' => 'confirmed', 'payment_id' => $payment->id, 'confirmed_at' => Carbon::now()]);

        return [$invoice, $report];
    }

    /** @return array{status: string, outstanding: int} */
    private function courseRow(StudentClass $sc): array
    {
        $row = app(BillingPayableResolver::class)->courseStatusesByStudentClassIds([(int) $sc->ID])[(int) $sc->ID];

        return ['status' => $row['status'], 'outstanding' => (int) $row['outstanding']];
    }
}
