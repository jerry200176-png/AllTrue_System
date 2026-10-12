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
 * #3875: the four collection gaps left after #3874, run across the stack with synthetic data. The request bodies are the
 * ones the REAL PaymentEntryModal / TuitionCollectionPage sent, captured by
 * frontend/src/components/__tests__/TuitionCollectionBatchInvoiceTarget.test.js into
 * tests/Fixtures/collection_requests_3875.json (that test fails if the frontend drifts from the file). Here each case's
 * queue row is checked against the live GET alerts/tuition row, then its body goes through intake → confirm → Payment ledger.
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

    // ── cross-stack: bodies the real frontend sent (frontend/.../TuitionCollectionBatchInvoiceTarget.test.js) ──

    /** Item 1: the queue row the modal received is the backend's real row; its captured body books the owing bill. */
    public function test_fixture_item1_modal_body_books_the_bill_that_owes(): void
    {
        [$sc, $older, $newer] = $this->newerCoveredOlderOwing();
        $case = $this->fixtureCase('item1_modal_owing_bill', ['course' => $sc->ID, 'invoice:older' => $older->id, 'invoice:newer' => $newer->id]);

        $res = $this->report($case['endpoint'], $case['request'])->assertOk()->assertJsonPath('invoice_id', $older->id);
        $this->confirm($res->json('report_id'))->assertOk();

        $this->assertLedger($older, 3000, 'paid');
        $this->assertSame(5000, (int) Payment::where('InvoiceID', $newer->id)->sum('Amount'), 'covered bill untouched');
        $this->assertSame(0, Payment::where('Amount', 0)->count(), 'no NT$0 Payment row');
    }

    /** Item 2: positive amount typed against the NT$0 bill the queue shows → refused at intake and at confirm. */
    public function test_fixture_item2_typed_amount_on_zero_bill_is_refused_at_intake_and_confirm(): void
    {
        [$sc, $zero] = $this->zeroTotalInvoice();
        $case = $this->fixtureCase('item2_modal_typed_amount_on_zero_bill', ['course' => $sc->ID, 'invoice:zero' => $zero->id]);

        $this->report($case['endpoint'], $case['request'])
            ->assertStatus(422)->assertJsonPath('code', 'amount_exceeds_outstanding')->assertJsonPath('invoice_id', $zero->id);
        $this->assertSame(0, PaymentReport::count());

        // The same captured body as a pending report recorded before this fix: confirm refuses too.
        $report = $this->pendingReport($sc, Invoice::findOrFail($case['request']['invoice_id']), (int) $case['request']['amount']);
        $this->confirm($report->id)->assertStatus(422)->assertJsonPath('code', 'amount_exceeds_outstanding');
        $this->assertSame('pending', $report->fresh()->status);
        $this->assertSame(0, Payment::count(), 'no Payment row on the NT$0 bill');
    }

    /** Item 1/2 zero amount: for the backend's real NT$0 row the modal sends nothing (blocked), so no report exists. */
    public function test_fixture_zero_amount_row_is_blocked_by_the_modal(): void
    {
        [$sc, $zero] = $this->zeroTotalInvoice();
        $case = $this->fixtureCase('item2_modal_zero_amount_blocked', ['course' => $sc->ID, 'invoice:zero' => $zero->id]);

        $this->assertNull($case['request'], 'the modal sent no request for NT$0');
        $this->assertNotEmpty($case['blocked_message']);
        $this->assertSame(0, PaymentReport::count());
    }

    /** Item 3: stored paid/full but Payment rows 2,000/6,000 → the captured body collects the 4,000 still owed. */
    public function test_fixture_item3_stored_paid_but_owing_bill_is_collectible(): void
    {
        [$sc, $inv] = $this->storedPaidLedgerPartial();
        $case = $this->fixtureCase('item3_modal_stored_paid_owing', ['course' => $sc->ID, 'invoice:paid' => $inv->id]);

        $res = $this->report($case['endpoint'], $case['request'])->assertOk()->assertJsonPath('invoice_id', $inv->id);
        $this->confirm($res->json('report_id'))->assertOk();
        $this->assertLedger($inv, 6000, 'paid');
    }

    /** Item 4: two historical open bills, queue shows the newer; the page's batch body pays it (no 207 on the older cap). */
    public function test_fixture_item4_batch_body_pays_the_displayed_newer_bill_over_the_older_cap(): void
    {
        [$sc, $older, $newer] = $this->twoHistoricalOpen(olderOwes: 1000, newerOwes: 3000);
        $case = $this->fixtureCase('item4_batch_newer_exceeds_older', ['course' => $sc->ID, 'invoice:older' => $older->id, 'invoice:newer' => $newer->id]);

        $res = $this->report($case['endpoint'], $case['request'])->assertOk()->assertJsonPath('accepted', 1);
        $this->confirm($res->json('results.0.report_id'))->assertOk();

        $this->assertLedger($newer, 3000, 'paid');
        $this->assertLedger($older, 0, 'unpaid');
    }

    /** Item 4: a smaller amount that would also fit the older bill still lands on the displayed newer one. */
    public function test_fixture_item4_batch_body_does_not_book_a_smaller_amount_on_the_older_bill(): void
    {
        [$sc, $older, $newer] = $this->twoHistoricalOpen(olderOwes: 1000, newerOwes: 800);
        $case = $this->fixtureCase('item4_batch_newer_smaller_amount', ['course' => $sc->ID, 'invoice:older' => $older->id, 'invoice:newer' => $newer->id]);

        $res = $this->report($case['endpoint'], $case['request'])->assertOk()->assertJsonPath('accepted', 1);
        $report = PaymentReport::findOrFail($res->json('results.0.report_id'));
        $this->assertSame($newer->id, (int) $report->InvoiceID, 'the pending report targets the displayed bill');
        $this->confirm($report->id)->assertOk();

        $this->assertLedger($newer, 800, 'paid');
        $this->assertLedger($older, 0, 'unpaid');
    }

    // ── backend-only guards (bodies any client could send) ──────────────────

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

    /** Whole NT$ only (Payment.Amount is an integer column): 0.50 / 0.99 / 1.50 never become a truncated NT$0 / NT$1 Payment row. */
    public function test_fractional_amounts_are_refused_at_intake_batch_and_confirm(): void
    {
        [$sc, $older] = $this->newerCoveredOlderOwing();
        foreach ([0.5, 0.99, 1.5] as $amount) {
            $this->report('director-record', ['student_class_id' => $sc->ID, 'invoice_id' => $older->id, 'payment_date' => '2026-04-28', 'payment_method' => 'cash', 'amount' => $amount])
                ->assertStatus(422)->assertJsonValidationErrors('amount');
            $this->report('director-record-batch', ['payment_date' => '2026-04-28', 'payment_method' => 'cash',
                'entries' => [['student_class_id' => $sc->ID, 'invoice_id' => $older->id, 'amount' => $amount]]])
                ->assertStatus(422)->assertJsonValidationErrors('entries.0.amount');
        }
        $this->assertSame(0, PaymentReport::count());

        foreach ([0.5, 0.99, 1.5] as $amount) { // pending reports recorded before this fix
            $report = PaymentReport::create(['StudentID' => $sc->StudentID, 'StudentClassID' => $sc->ID, 'InvoiceID' => $older->id,
                'reported_by_name' => 'x', 'payment_date' => '2026-04-28', 'payment_method' => 'cash', 'reported_amount' => $amount,
                'status' => 'pending', 'report_token_hash' => hash('sha256', uniqid('f', true)), 'token_expires_at' => now()->addDay()]);
            $this->confirm($report->id)->assertStatus(422)->assertJsonPath('code', 'fractional_amount');
            $this->assertSame('pending', $report->fresh()->status);
        }
        $this->assertSame(0, Payment::where('InvoiceID', $older->id)->count(), 'no truncated Payment row');
        $this->assertLedger($older, 0, 'unpaid');
    }

    // ── #3888 Codex review (2026-10-11 17:52Z on 632fb584) ─────────────────

    /** r4242583482: a monthly course's queue target is its earliest unsettled period, not the newest. */
    public function test_review_monthly_queue_target_is_the_oldest_owing_period(): void
    {
        $sc = $this->course(['ScheduleMode' => 'date', 'Charge' => 6000]);
        [$feb, $mar] = [$this->monthly($sc, '2026-02', 6000, 1000), $this->monthly($sc, '2026-03', 6000, 1000)];
        $row = app(\App\Services\BillingPayableResolver::class)->byStudentClassIds([$sc->ID], [$sc])[$sc->ID];
        $this->assertSame([$feb->id, 5000], [$row['payable_invoice_id'], $row['payable_outstanding']], "not March #{$mar->id}");
    }

    /** r4242583490: an unbilled charge-bearing contract (no invoice) refuses NT$0 at intake, batch and confirm. */
    public function test_review_zero_report_on_an_unbilled_contract_is_refused(): void
    {
        $sc = $this->course(['Charge' => 6000]);
        $this->report('director-record', ['student_class_id' => $sc->ID, 'payment_date' => '2026-04-28', 'payment_method' => 'cash', 'amount' => 0])
            ->assertStatus(422)->assertJsonPath('code', 'zero_amount_with_balance');
        $this->report('director-record-batch', ['payment_date' => '2026-04-28', 'payment_method' => 'cash', 'entries' => [['student_class_id' => $sc->ID, 'amount' => 0]]])
            ->assertStatus(207)->assertJsonPath('results.0.code', 'zero_amount_with_balance');
        $this->confirm($this->pendingReport($sc, null, 0)->id)->assertStatus(422)->assertJsonPath('code', 'zero_amount_with_balance');
        $this->assertSame([0, 0], [Invoice::count(), Payment::count()]);
    }

    /** r4242583492: stored-paid monthly bill, Payment rows 3,000/8,800, sessions now price the month at 0 → owes 5,800. */
    public function test_review_fixed_total_monthly_bill_collects_its_projected_outstanding(): void
    {
        $sc = $this->course(['ScheduleMode' => 'date', 'Charge' => 8800, 'Rate' => 1500, 'StartDate' => '2026-04-01', 'EndDate' => '2026-04-30']);
        \App\Models\ClassSession::create(['StudentClassID' => $sc->ID, 'SessionDate' => '2026-04-29', 'StartTime' => '18:00', 'EndTime' => '20:00', 'Status' => 'scheduled']);
        $inv = $this->monthly($sc, '2026-04', 8800, 3000, ['Status' => 'paid', 'PaidAmount' => 8800]);

        $res = $this->report('director-record', ['student_class_id' => $sc->ID, 'invoice_id' => $inv->id, 'payment_date' => '2026-04-28', 'payment_method' => 'cash', 'amount' => 5800])
            ->assertOk();
        $this->confirm($res->json('report_id'))->assertOk();
        $this->assertLedger($inv, 8800, 'paid');
    }

    /** r4242583488 BLOCKED (Founder rule): Paid=1 + stored-paid bill owing by Payment rows is absent from alerts/tuition;
     * fixing it changes AlertController::tuition list conditions (change-controlled, high-risk; #3862 hold). */
    public function test_review_paid_flag_contract_owing_by_payment_rows_is_in_the_queue(): void
    {
        $this->markTestSkipped('Blocked on Founder rule: AlertController::tuition inclusion change (#3888 r4242583488; #3862 hold).');
        [$sc] = $this->storedPaidLedgerPartial();
        $sc->update(['Paid' => 1]);
        $this->queueRow($sc);
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

    // ── shared fixture ──────────────────────────────────────────────────────

    /**
     * Loads one case of the fixture the frontend test generated, maps its id placeholders to the rows just created, and
     * checks the queue row the frontend was given equals what GET alerts/tuition returns now (fields the modal/page read),
     * so the captured request really is what the director's screen would send for this data.
     */
    private function fixtureCase(string $name, array $ids): array
    {
        $all = json_decode((string) file_get_contents(base_path('tests/Fixtures/collection_requests_3875.json')), true, 512, JSON_THROW_ON_ERROR);
        $this->assertArrayHasKey($name, $all, "fixture case $name (regenerate: npx vitest run -u src/components/__tests__/TuitionCollectionBatchInvoiceTarget.test.js)");
        $map = function ($v) use (&$map, $ids) {
            if (is_array($v)) {
                return array_map($map, $v);
            }
            if (is_string($v) && preg_match('/^\{\{(.+)\}\}$/', $v, $m)) {
                $this->assertArrayHasKey($m[1], $ids, "placeholder {$m[1]}");

                return (int) $ids[$m[1]];
            }

            return $v;
        };
        $case = $map($all[$name]);

        $live = $this->queueRow(StudentClass::findOrFail($ids['course']));
        foreach (['id', 'payment_status', 'payable_status', 'charge', 'payable_amount', 'payable_outstanding', 'payable_invoice_id'] as $field) {
            $this->assertEquals($case['row'][$field] ?? null, $live[$field] ?? null, "$name: frontend fixture row.$field differs from the live queue row");
        }

        return $case;
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

    private function monthly(StudentClass $sc, string $period, int $total, int $paid, array $over = []): Invoice
    {
        $inv = Invoice::create(array_merge(['StudentID' => $sc->StudentID, 'StudentClassID' => $sc->ID, 'IssueDate' => "$period-01",
            'TotalAmount' => $total, 'PaidAmount' => $paid, 'Status' => 'partial', 'billing_period' => $period], $over));
        Payment::create(['InvoiceID' => $inv->id, 'Amount' => $paid, 'PaidAt' => "$period-05", 'Method' => 'cash']);
        return $inv;
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
