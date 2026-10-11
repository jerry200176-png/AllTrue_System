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
 * #3875: the four collection gaps left after #3874, driven the way the director does it — the row comes from the real
 * queue API (GET alerts/tuition), the request is built exactly as the page builds it (see modalRequest/batchEntry),
 * then intake → confirm → Payment ledger. Frontend halves: PaymentEntryModal.test.js and
 * TuitionCollectionBatchInvoiceTarget.test.js. Synthetic data only.
 */
class CollectionQueueRequestShapeTest extends TestCase
{
    use RefreshDatabase;

    private array $headers;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow('2026-04-28 10:00:00');
        $user = User::create(['LoginName' => 'gap3875@example.com', 'Name' => 'd', 'PSW' => 'secret', 'type' => 'A', 'phone' => 912345678]);
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

    // ── 1. zero-amount fallback ──────────────────────────────────────────────

    /** Newer bill: stale status unpaid but its Payment rows cover it. Older bill still owes 3,000. */
    public function test_item1_queue_row_shows_the_bill_that_owes_and_the_modal_books_it(): void
    {
        [$sc, $older, $newer] = $this->newerCoveredOlderOwing();

        $row = $this->queueRow($sc);
        $this->assertSame($older->id, (int) $row['payable_invoice_id'], 'the queue shows the bill that still owes');
        $this->assertSame(3000, (int) $row['payable_outstanding']);

        $res = $this->report('director-record', $this->modalRequest($row))->assertOk()->assertJsonPath('invoice_id', $older->id);
        $this->confirm($res->json('report_id'))->assertOk();

        $this->assertLedger($older, 3000, 'paid');
        $this->assertSame(5000, (int) Payment::where('InvoiceID', $newer->id)->sum('Amount'), 'covered bill untouched');
        $this->assertSame(0, Payment::where('Amount', 0)->count(), 'no NT$0 Payment row');
    }

    /** The pre-#3875 modal request (amount 0, no invoice id) — or any client sending it — is refused at intake and confirm. */
    public function test_item1_zero_report_against_a_bill_that_owes_is_refused_at_intake_and_confirm(): void
    {
        [$sc, $older] = $this->newerCoveredOlderOwing();

        $this->report('director-record', ['student_class_id' => $sc->ID, 'payment_date' => '2026-04-28', 'payment_method' => 'cash', 'amount' => 0])
            ->assertStatus(422)->assertJsonPath('code', 'zero_amount_with_balance')->assertJsonPath('invoice_id', $older->id);
        $this->assertSame(0, PaymentReport::count());

        $report = $this->pendingReport($sc, null, 0); // recorded before this fix
        $this->confirm($report->id)->assertStatus(422)->assertJsonPath('code', 'zero_amount_with_balance');
        $this->assertSame('pending', $report->fresh()->status);
        $this->assertSame(0, Payment::where('Amount', 0)->count());
        $this->assertLedger($older, 0, 'unpaid');
    }

    // ── 2. zero-total invoice cap ────────────────────────────────────────────

    public function test_item2_positive_amount_on_a_zero_total_invoice_is_refused_at_intake(): void
    {
        [$sc, $zero] = $this->zeroTotalInvoice();
        $row = $this->queueRow($sc);
        $this->assertSame($zero->id, (int) $row['payable_invoice_id']);

        // Director types NT$500 into the modal (prefill was 0); also the no-invoice-id fallback.
        $this->report('director-record', $this->modalRequest($row, 500))
            ->assertStatus(422)->assertJsonPath('code', 'amount_exceeds_outstanding')->assertJsonPath('invoice_id', $zero->id);
        $this->report('director-record', ['student_class_id' => $sc->ID, 'payment_date' => '2026-04-28', 'payment_method' => 'cash', 'amount' => 500])
            ->assertStatus(422)->assertJsonPath('code', 'amount_exceeds_outstanding');
        $this->report('director-record-batch', ['payment_date' => '2026-04-28', 'payment_method' => 'cash', 'entries' => [$this->batchEntry($row, 500)]])
            ->assertStatus(207)->assertJsonPath('results.0.code', 'amount_exceeds_outstanding');

        $this->assertSame(0, PaymentReport::count());
        $this->assertSame(0, Payment::count());
    }

    public function test_item2_positive_amount_on_a_zero_total_invoice_is_refused_at_confirm(): void
    {
        [$sc, $zero] = $this->zeroTotalInvoice();
        $named = $this->pendingReport($sc, $zero, 500);   // recorded before this fix
        $fallback = $this->pendingReport($sc, null, 500); // parent link report, no invoice

        $this->confirm($named->id)->assertStatus(422)->assertJsonPath('code', 'amount_exceeds_outstanding');
        $this->confirm($fallback->id)->assertStatus(422)->assertJsonPath('code', 'amount_exceeds_outstanding');

        $this->assertSame(0, Payment::count(), 'no Payment row on the NT$0 bill');
        $this->assertSame(['pending', 'pending'], [$named->fresh()->status, $fallback->fresh()->status]);
        $this->assertSame(0, (int) $zero->fresh()->PaidAmount);
    }

    // ── 3. stored paid, Payment rows still owe ──────────────────────────────

    public function test_item3_explicit_invoice_stored_paid_but_ledger_owing_is_collectible(): void
    {
        [$sc, $inv] = $this->storedPaidLedgerPartial();
        $row = $this->queueRow($sc);
        $this->assertSame($inv->id, (int) $row['payable_invoice_id']);
        $this->assertSame(4000, (int) $row['payable_outstanding']);

        $this->report('director-record', $this->modalRequest($row, 4001))->assertStatus(422)->assertJsonPath('expected_amount', 4000);
        $res = $this->report('director-record', $this->modalRequest($row))->assertOk()->assertJsonPath('invoice_id', $inv->id);
        $this->confirm($res->json('report_id'))->assertOk();

        $this->assertLedger($inv, 6000, 'paid');
    }

    public function test_item3_fallback_finds_the_stored_paid_bill_that_still_owes(): void
    {
        [$sc, $inv] = $this->storedPaidLedgerPartial();

        $res = $this->report('director-record', ['student_class_id' => $sc->ID, 'payment_date' => '2026-04-28', 'payment_method' => 'cash', 'amount' => 4000])
            ->assertOk()->assertJsonPath('invoice_id', $inv->id);
        $this->confirm($res->json('report_id'))->assertOk();
        $this->assertLedger($inv, 6000, 'paid');

        // Once the rows cover it, the same bill is refused again — explicit and fallback.
        $this->report('director-record', ['student_class_id' => $sc->ID, 'invoice_id' => $inv->id, 'payment_date' => '2026-04-28', 'payment_method' => 'cash', 'amount' => 1])
            ->assertStatus(422)->assertJsonPath('code', 'course_already_paid');
        $this->assertSame(1, PaymentReport::count());
    }

    public function test_item3_void_and_other_course_invoices_stay_refused(): void
    {
        [$sc, $inv] = $this->storedPaidLedgerPartial();
        $inv->update(['Status' => 'void']);
        $this->report('director-record', ['student_class_id' => $sc->ID, 'invoice_id' => $inv->id, 'payment_date' => '2026-04-28', 'payment_method' => 'cash', 'amount' => 100])
            ->assertStatus(422)->assertJsonPath('code', 'void_invoice_cannot_record');

        [$other, $otherInv] = $this->storedPaidLedgerPartial();
        $this->report('director-record', ['student_class_id' => $sc->ID, 'invoice_id' => $otherInv->id, 'payment_date' => '2026-04-28', 'payment_method' => 'cash', 'amount' => 100])
            ->assertStatus(404);
        $this->assertSame(0, PaymentReport::count());
    }

    // ── 4. batch payload carries the row's invoice ──────────────────────────

    /** Two historical open bills, no current-period match: the queue shows the newer; the fallback would take the older. */
    public function test_item4_batch_entry_pays_the_displayed_newer_bill_when_it_exceeds_the_older_balance(): void
    {
        [$sc, $older, $newer] = $this->twoHistoricalOpen(olderOwes: 1000, newerOwes: 3000);
        $row = $this->queueRow($sc);
        $this->assertSame($newer->id, (int) $row['payable_invoice_id']);

        // Without the invoice id (the pre-#3875 page payload) the fallback caps at the older bill → 207.
        $this->report('director-record-batch', ['payment_date' => '2026-04-28', 'payment_method' => 'cash', 'entries' => [
            ['student_class_id' => $row['id'], 'amount' => (int) $row['payable_outstanding']],
        ]])->assertStatus(207)->assertJsonPath('results.0.code', 'amount_exceeds_outstanding');

        $res = $this->report('director-record-batch', ['payment_date' => '2026-04-28', 'payment_method' => 'cash', 'entries' => [$this->batchEntry($row)]])
            ->assertOk();
        $this->confirm($res->json('results.0.report_id'))->assertOk();

        $this->assertLedger($newer, 3000, 'paid');
        $this->assertLedger($older, 0, 'unpaid');
    }

    public function test_item4_batch_entry_does_not_book_a_smaller_amount_on_the_older_bill(): void
    {
        [$sc, $older, $newer] = $this->twoHistoricalOpen(olderOwes: 1000, newerOwes: 800);
        $row = $this->queueRow($sc);
        $this->assertSame($newer->id, (int) $row['payable_invoice_id']);

        $res = $this->report('director-record-batch', ['payment_date' => '2026-04-28', 'payment_method' => 'cash', 'entries' => [$this->batchEntry($row)]])
            ->assertOk();
        $report = PaymentReport::findOrFail($res->json('results.0.report_id'));
        $this->assertSame($newer->id, (int) $report->InvoiceID, 'the pending report targets the displayed bill');
        $this->confirm($report->id)->assertOk();

        $this->assertLedger($newer, 800, 'paid');
        $this->assertLedger($older, 0, 'unpaid');
    }

    // ── request shapes: mirror the frontend exactly ─────────────────────────

    /** PaymentEntryModal.vue submit(): amount = payable_outstanding prefill, invoice_id = row.invoice_id ?? payable_invoice_id. */
    private function modalRequest(array $row, ?int $typedAmount = null): array
    {
        return array_filter([
            'student_class_id' => $row['id'],
            'payment_date' => '2026-04-28',
            'payment_method' => 'cash',
            'amount' => $typedAmount ?? (int) ($row['payable_outstanding'] ?? $row['payable_amount']),
            'invoice_id' => $row['invoice_id'] ?? $row['payable_invoice_id'] ?? null,
        ], fn ($v) => $v !== null);
    }

    /** TuitionCollectionPage.vue submitBatchReport() entries[]: student_class_id, amount, invoice_id = payable_invoice_id. */
    private function batchEntry(array $row, ?int $amount = null): array
    {
        return array_filter([
            'student_class_id' => $row['id'],
            'amount' => $amount ?? (int) ($row['payable_outstanding'] ?? $row['payable_amount'] ?? 0),
            'invoice_id' => $row['payable_invoice_id'] ?: null,
        ], fn ($v) => $v !== null);
    }

    // ── fixtures ────────────────────────────────────────────────────────────

    private function newerCoveredOlderOwing(): array
    {
        $sc = $this->course(['Charge' => 8000]);
        $older = $this->invoice($sc, '2026-02-01', 3000, 'unpaid');
        $newer = $this->invoice($sc, '2026-03-01', 5000, 'unpaid');
        Payment::create(['InvoiceID' => $newer->id, 'Amount' => 5000, 'PaidAt' => '2026-03-05', 'Method' => 'cash']);

        return [$sc, $older, $newer];
    }

    /** Positive-Charge count-mode contract whose only bill is NT$0 (resolver: review_required). */
    private function zeroTotalInvoice(): array
    {
        $sc = $this->course(['Charge' => 6000]);

        return [$sc, $this->invoice($sc, '2026-04-01', 0, 'unpaid')];
    }

    /** Stored paid/full, Payment rows only 2,000 of 6,000. */
    private function storedPaidLedgerPartial(): array
    {
        $sc = $this->course(['Charge' => 6000]);
        $inv = Invoice::create(['StudentID' => $sc->StudentID, 'StudentClassID' => $sc->ID, 'IssueDate' => '2026-04-01',
            'TotalAmount' => 6000, 'PaidAmount' => 6000, 'Status' => 'paid']);
        Payment::create(['InvoiceID' => $inv->id, 'Amount' => 2000, 'PaidAt' => '2026-04-02', 'Method' => 'cash']);

        return [$sc, $inv];
    }

    private function twoHistoricalOpen(int $olderOwes, int $newerOwes): array
    {
        $sc = $this->course(['Charge' => $olderOwes + $newerOwes]);

        return [$sc, $this->invoice($sc, '2026-01-01', $olderOwes, 'unpaid'), $this->invoice($sc, '2026-02-01', $newerOwes, 'unpaid')];
    }

    private function invoice(StudentClass $sc, string $issueDate, int $total, string $status): Invoice
    {
        return Invoice::create(['StudentID' => $sc->StudentID, 'StudentClassID' => $sc->ID, 'IssueDate' => $issueDate,
            'TotalAmount' => $total, 'PaidAmount' => 0, 'Status' => $status]);
    }

    private function queueRow(StudentClass $sc): array
    {
        $rows = $this->withHeaders($this->headers)->getJson('/api/v1/alerts/tuition?branch_id=1')->assertOk()->json();
        $row = collect($rows)->first(fn ($r) => (int) $r['id'] === (int) $sc->ID && empty($r['package_id']));
        $this->assertNotNull($row, 'the course is in the collection queue');

        return $row;
    }

    private function report(string $path, array $body)
    {
        return $this->withHeaders($this->headers)->postJson("/api/v1/payment-reports/$path", $body);
    }

    private function confirm($reportId)
    {
        return $this->withHeaders($this->headers)->putJson("/api/v1/payment-reports/$reportId/confirm");
    }

    private function assertLedger(Invoice $invoice, int $paid, string $status): void
    {
        $fresh = Invoice::with(['payments', 'items'])->findOrFail($invoice->id);
        $p = app(InvoiceAmountReconciliationService::class)->resolve($fresh);
        $this->assertSame($paid, $p['net_applied'], "Payment rows on invoice {$invoice->id}");
        $this->assertLessThanOrEqual(max($p['total_amount'], 0), $p['net_applied'], 'Payment rows never exceed the bill');
        $this->assertSame($status, (string) $fresh->Status);
        if ($status !== 'unpaid') {
            $this->assertSame($paid, (int) $fresh->PaidAmount, 'stored PaidAmount = Payment rows');
        }
    }

    private function pendingReport(StudentClass $sc, ?Invoice $invoice, int $amount): PaymentReport
    {
        return PaymentReport::create(['StudentID' => $sc->StudentID, 'StudentClassID' => $sc->ID, 'InvoiceID' => $invoice?->id,
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
