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

/** #3875: bodies the REAL modal/page sent (Fixtures/collection_requests_3875.json, TuitionCollectionBatchInvoiceTarget.test.js)
 * → live alerts/tuition row check → intake → confirm → ledger → receipt. */
class CollectionQueueRequestShapeTest extends TestCase
{
    use RefreshDatabase;
    private const D = ['payment_date' => '2026-04-28', 'payment_method' => 'cash'];
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

    /** Item 1: the queue row the modal received is the backend's real row; its captured body books the owing bill. */
    public function test_fixture_item1_modal_body_books_the_bill_that_owes(): void
    {
        [$sc, $older, $newer] = $this->newerCoveredOlderOwing();
        $case = $this->fixtureCase('item1_modal_owing_bill', ['course' => $sc->ID, 'invoice:older' => $older->id, 'invoice:newer' => $newer->id]);
        $res = $this->report($case['endpoint'], $case['request'])->assertOk()->assertJsonPath('invoice_id', $older->id);
        $this->confirm($res->json('report_id'))->assertOk();
        // Idempotent: a second confirm and a resubmitted body write nothing more.
        $this->confirm($res->json('report_id'))->assertStatus(422);
        $this->report($case['endpoint'], $case['request'])->assertStatus(422)->assertJsonPath('code', 'course_already_paid');
        $this->assertLedger($older, 3000, 'paid');
        $this->assertSame(5000, (int) Payment::where('InvoiceID', $newer->id)->sum('Amount'), 'covered bill untouched');
        $this->assertSame(0, Payment::where('Amount', 0)->count(), 'no NT$0 Payment row');
    }

    /** Item 2: positive amount typed against the NT$0 bill the queue shows → refused at intake and at confirm. */
    public function test_fixture_item2_typed_amount_on_zero_bill_is_refused_at_intake_and_confirm(): void
    {
        [$sc, $zero] = $this->zeroTotalInvoice();
        $case = $this->fixtureCase('item2_modal_typed_amount_on_zero_bill', ['course' => $sc->ID, 'invoice:zero' => $zero->id]);
        $this->report($case['endpoint'], $case['request'])->assertStatus(422)->assertJsonPath('code', 'amount_exceeds_outstanding')->assertJsonPath('invoice_id', $zero->id);
        $this->assertSame(0, PaymentReport::count());
        $report = $this->pendingReport($sc, Invoice::findOrFail($case['request']['invoice_id']), (int) $case['request']['amount']);
        $this->confirm($report->id)->assertStatus(422)->assertJsonPath('code', 'amount_exceeds_outstanding');
        $this->assertSame('pending', $report->fresh()->status);
        $this->assertSame(0, Payment::count(), 'no Payment row on the NT$0 bill');
        // Zero amount: for the same real NT$0 row the modal sends nothing (blocked), so no report exists.
        $blocked = $this->fixtureCase('item2_modal_zero_amount_blocked', ['course' => $sc->ID, 'invoice:zero' => $zero->id]);
        $this->assertNull($blocked['request'], 'the modal sent no request for NT$0');
        $this->assertNotEmpty($blocked['blocked_message']);
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

    /** Item 4: two historical open bills, queue shows the newer; the batch body pays it even with an amount that fits the older. */
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

    /** Founder 2026-10-13: a record that names no bill lands on the bill the list shows (here the newer), never another. */
    public function test_record_without_invoice_id_targets_the_bill_the_list_shows(): void
    {
        [$sc, $older, $newer] = $this->twoHistoricalOpen(olderOwes: 1000, newerOwes: 800);
        $this->assertSame($newer->id, $this->queueRow($sc)['payable_invoice_id'], 'the list shows the newer bill');
        $res = $this->report('director-record', ['student_class_id' => $sc->ID, ...self::D, 'amount' => 800])->assertOk()->assertJsonPath('invoice_id', $newer->id);
        $this->confirm($res->json('report_id'))->assertOk();
        $this->assertLedger($older, 0, 'unpaid');
    }

    public function test_item1_zero_report_against_a_bill_that_owes_is_refused_at_intake_and_confirm(): void
    {
        [$sc, $older] = $this->newerCoveredOlderOwing();
        $this->report('director-record', ['student_class_id' => $sc->ID, ...self::D, 'amount' => 0])->assertStatus(422)->assertJsonPath('code', 'zero_amount_with_balance')->assertJsonPath('invoice_id', $older->id);
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
        foreach ([0.5, 0.99, 1.5] as $amount) { // intake, batch, and a pending report recorded before this fix
            $this->report('director-record', ['student_class_id' => $sc->ID, 'invoice_id' => $older->id, ...self::D, 'amount' => $amount])->assertStatus(422)->assertJsonValidationErrors('amount');
            $this->report('director-record-batch', [...self::D, 'entries' => [['student_class_id' => $sc->ID, 'invoice_id' => $older->id, 'amount' => $amount]]])->assertStatus(422)->assertJsonValidationErrors('entries.0.amount');
            $report = $this->pendingReport($sc, $older, $amount);
            $this->confirm($report->id)->assertStatus(422)->assertJsonPath('code', 'fractional_amount');
            $this->assertSame('pending', $report->fresh()->status);
        }
        $this->assertSame(3, PaymentReport::count(), 'only the 3 seeded reports: none created at intake');
        $this->assertSame(0, Payment::where('InvoiceID', $older->id)->count(), 'no truncated Payment row');
        $this->assertLedger($older, 0, 'unpaid');
    }

    /** r4242583482: a monthly course's queue target is its earliest unsettled period, not the newest. */
    public function test_review_monthly_queue_target_is_the_oldest_owing_period(): void
    {
        $sc = $this->course(['ScheduleMode' => 'date', 'Charge' => 6000]);
        [$feb, $mar] = [$this->monthly($sc, '2026-02', 6000, 1000), $this->monthly($sc, '2026-03', 6000, 1000)];
        $row = app(\App\Services\BillingPayableResolver::class)->byStudentClassIds([$sc->ID], [$sc])[$sc->ID];
        $this->assertSame([$feb->id, 5000], [$row['payable_invoice_id'], $row['payable_outstanding']], "not March #{$mar->id}");
    }

    /** r4244111099: NT$0 against a stored NT$0 bill of a course that charges is refused; a free course is not this case. */
    public function test_review_zero_report_on_an_erroneous_zero_bill_is_refused(): void
    {
        [$sc, $zero] = $this->zeroTotalInvoice();
        $this->report('director-record', ['student_class_id' => $sc->ID, 'invoice_id' => $zero->id, ...self::D, 'amount' => 0])->assertStatus(422)->assertJsonPath('code', 'zero_amount_with_balance');
        $this->confirm($this->pendingReport($sc, $zero, 0)->id)->assertStatus(422)->assertJsonPath('code', 'zero_amount_with_balance');
        $this->assertSame(0, Payment::count());
        $free = $this->course(['Charge' => 0, 'Rate' => 0]);
        $this->report('director-record', ['student_class_id' => $free->ID, ...self::D, 'amount' => 0])->assertJsonMissing(['code' => 'zero_amount_with_balance']);
    }

    /** r4242583490: an unbilled charge-bearing contract (no invoice) refuses NT$0 at intake, batch and confirm. */
    public function test_review_zero_report_on_an_unbilled_contract_is_refused(): void
    {
        $sc = $this->course(['Charge' => 6000]);
        $this->report('director-record', ['student_class_id' => $sc->ID, ...self::D, 'amount' => 0])->assertStatus(422)->assertJsonPath('code', 'zero_amount_with_balance');
        $this->report('director-record-batch', [...self::D, 'entries' => [['student_class_id' => $sc->ID, 'amount' => 0]]])->assertStatus(207)->assertJsonPath('results.0.code', 'zero_amount_with_balance');
        $this->confirm($this->pendingReport($sc, null, 0)->id)->assertStatus(422)->assertJsonPath('code', 'zero_amount_with_balance');
        $this->assertSame([0, 0], [Invoice::count(), Payment::count()]);
    }

    /** r4242583492: stored-paid monthly bill, Payment rows 3,000/8,800, sessions now price the month at 0 → owes 5,800. */
    public function test_review_fixed_total_monthly_bill_collects_its_projected_outstanding(): void
    {
        $sc = $this->course(['ScheduleMode' => 'date', 'Charge' => 8800, 'Rate' => 1500, 'StartDate' => '2026-03-01', 'EndDate' => '2026-04-30']);
        foreach ([['2026-03-10', 'attended'], ['2026-03-31', 'scheduled'], ['2026-04-09', 'attended']] as [$day, $st]) {
            \App\Models\ClassSession::create(['StudentClassID' => $sc->ID, 'SessionDate' => $day, 'StartTime' => '18:00', 'EndTime' => '20:00', 'Status' => $st]);
        }
        $inv = $this->monthly($sc, '2026-03', 8800, 3000, ['Status' => 'paid', 'PaidAmount' => 8800]); // March bill, paid 2026-04-28
        $res = $this->report('director-record', ['student_class_id' => $sc->ID, 'invoice_id' => $inv->id, ...self::D, 'amount' => 5800])->assertOk();
        $this->confirm($res->json('report_id'))->assertOk();
        $this->assertLedger($inv, 8800, 'paid');
        $this->assertNull($inv->fresh()->billing_snapshot, 'r4245816754: no snapshot of today\'s sessions on a fixed bill');
        // r4246114454: the receipt prints the bill's March service period and its sessions, not the payment month (April).
        $this->withHeaders($this->headers)->getJson('/api/v1/payment-reports/' . $res->json('report_id') . '/receipt')
            ->assertJsonPath('period_start', '2026/03/01')->assertJsonPath('period_end', '2026/03/31')->assertJsonPath('attended_dates', ['2026/03/10']);
    }

    public function test_item3_void_and_other_course_invoices_stay_refused(): void
    {
        [$sc, $inv] = $this->storedPaidLedgerPartial();
        $inv->update(['Status' => 'void']);
        $this->report('director-record', ['student_class_id' => $sc->ID, 'invoice_id' => $inv->id, ...self::D, 'amount' => 100])->assertStatus(422)->assertJsonPath('code', 'void_invoice_cannot_record');
        [$other, $otherInv] = $this->storedPaidLedgerPartial();
        $this->report('director-record', ['student_class_id' => $sc->ID, 'invoice_id' => $otherInv->id, ...self::D, 'amount' => 100])->assertStatus(404);
        $this->assertSame(0, PaymentReport::count());
    }

    /** Founder 2026-10-13: a bill settled by Payment rows is refused (422) at record/batch/confirm, never redirected, no row written. */
    public function test_settled_target_bill_is_refused_and_never_redirected(): void
    {
        [$sc, $older, $newer] = $this->newerCoveredOlderOwing();
        $this->report('director-record', ['student_class_id' => $sc->ID, 'invoice_id' => $newer->id, ...self::D, 'amount' => 3000])
            ->assertStatus(422)->assertJsonPath('code', 'course_already_paid')->assertJsonPath('invoice_id', $newer->id);
        $this->report('director-record-batch', [...self::D, 'entries' => [['student_class_id' => $sc->ID, 'invoice_id' => $newer->id, 'amount' => 3000]]])
            ->assertStatus(207)->assertJsonPath('results.0.http_status', 422)->assertJsonPath('results.0.code', 'course_already_paid');
        $this->assertSame(0, PaymentReport::count(), 'no report at intake');
        $report = $this->pendingReport($sc, $newer, 3000); // e.g. recorded before the bill was settled
        $this->confirm($report->id)->assertStatus(422)->assertJsonPath('code', 'course_already_paid')->assertJsonPath('invoice_id', $newer->id);
        $this->assertSame('pending', $report->fresh()->status);
        $this->assertSame(1, Payment::count(), 'only the original 5,000 row on the settled bill');
        $this->assertLedger($older, 0, 'unpaid');
    }

    /** Founder 2026-10-13 + r4244111096: monthly targets the oldest period not settled by Payment rows (a stored-paid one
     * that still owes beats a newer stored-unpaid one), also when the caller names no bill (replaces FR-006's current period). */
    public function test_monthly_record_without_invoice_id_targets_the_oldest_owing_period(): void
    {
        $sc = $this->course(['ScheduleMode' => 'date', 'Charge' => 6000]);
        $feb = $this->monthly($sc, '2026-02', 6000, 1000, ['Status' => 'paid', 'PaidAmount' => 6000]); // stale paid, owes 5,000
        $apr = $this->invoice($sc, '2026-04-01', 6000, 'unpaid', ['billing_period' => '2026-04']);
        $row = app(\App\Services\BillingPayableResolver::class)->byStudentClassIds([$sc->ID], [$sc])[$sc->ID];
        $this->assertSame([$feb->id, 5000], [$row['payable_invoice_id'], $row['payable_outstanding']]);
        $res = $this->report('director-record', ['student_class_id' => $sc->ID, ...self::D, 'amount' => 5000])->assertOk()->assertJsonPath('invoice_id', $feb->id);
        $this->confirm($res->json('report_id'))->assertOk();
        $this->assertLedger($feb, 6000, 'paid');
        $this->assertSame(0, Payment::where('InvoiceID', $apr->id)->count(), 'current period untouched');
    }

    /** #3923 P1: a paid package member's stale stored-paid bill (rows 2,000/6,000) is not charged here (as Invoice::mayOweSql). */
    public function test_paid_package_member_stale_paid_bill_is_refused_at_record_and_confirm(): void
    {
        [$sc, $inv] = $this->storedPaidLedgerPartial();
        $pkg = \App\Models\CoursePackage::create(['student_id' => $sc->StudentID, 'campus_id' => 1, 'name' => 'P', 'billing_mode' => 'count', 'total_sessions' => 8, 'remaining_sessions' => 8, 'used_sessions' => 0, 'rate' => 750, 'rate_unit' => 'session', 'class_type' => 'one_on_one', 'paid' => true, 'stop' => false, 'enabled' => true, 'paid_at' => '2026-04-10']);
        $sc->update(['PackageID' => $pkg->id]);
        $this->report('director-record', ['student_class_id' => $sc->ID, 'invoice_id' => $inv->id, ...self::D, 'amount' => 4000])->assertStatus(422)->assertJsonPath('code', 'course_already_paid');
        $this->report('director-record', ['student_class_id' => $sc->ID, ...self::D, 'amount' => 4000])->assertStatus(422)->assertJsonPath('code', 'course_already_paid');
        $this->confirm($this->pendingReport($sc, $inv, 4000)->id)->assertStatus(422)->assertJsonPath('code', 'course_already_paid');
        $this->assertSame([1, 2000], [Payment::count(), (int) Payment::sum('Amount')], 'only the original 2,000 row');
    }

    /**
     * #3888+#3891 integration (Founder 2026-10-13, P1 r4242583488 / r4254222922): course and BOTH bills stored paid,
     * the older bill short by Payment rows, the newer one fully covered. Queue → record → confirm must target the
     * older bill for exactly what it still owes and leave the newer bill's Payment rows untouched.
     */
    public function test_integration_stale_paid_older_bill_is_collected_and_covered_newer_bill_is_untouched(): void
    {
        $sc = $this->course(['Charge' => 8000, 'Paid' => 1]);
        $older = $this->invoice($sc, '2026-02-01', 3000, 'paid', ['PaidAmount' => 3000]);
        Payment::create(['InvoiceID' => $older->id, 'Amount' => 1000, 'PaidAt' => '2026-02-05', 'Method' => 'cash']); // owes 2,000
        $newer = $this->invoice($sc, '2026-03-01', 5000, 'paid', ['PaidAmount' => 5000]);
        Payment::create(['InvoiceID' => $newer->id, 'Amount' => 5000, 'PaidAt' => '2026-03-05', 'Method' => 'cash']);
        $newerRows = Payment::where('InvoiceID', $newer->id)->orderBy('id')->get(['id', 'Amount', 'PaidAt'])->toArray();

        // 1. Collection queue: the course is listed, targeting the older bill for exactly what it still owes.
        $row = $this->queueRow($sc);
        $this->assertSame($older->id, (int) $row['payable_invoice_id'], 'queue targets the owing older bill, not the covered newer one');
        $this->assertSame(2000, (int) $row['payable_outstanding']);

        // 2. A settled target is refused (no switch to another bill) and writes nothing.
        $this->report('director-record', ['student_class_id' => $sc->ID, 'invoice_id' => $newer->id, ...self::D, 'amount' => 2000])
            ->assertStatus(422)->assertJsonPath('code', 'course_already_paid')->assertJsonPath('invoice_id', $newer->id);
        $this->assertSame(0, PaymentReport::count());

        // 3. Record exactly what the queue row carries (what the modal sends), then confirm.
        $res = $this->report('director-record', ['student_class_id' => $sc->ID, 'invoice_id' => $row['payable_invoice_id'],
            ...self::D, 'amount' => $row['payable_outstanding']])->assertOk()->assertJsonPath('invoice_id', $older->id);
        $this->confirm($res->json('report_id'))->assertOk();

        // 4. Ledger: older bill now fully paid by Payment rows; newer bill's rows, PaidAmount and Status unchanged.
        $this->assertLedger($older, 3000, 'paid');
        $this->assertSame($newerRows, Payment::where('InvoiceID', $newer->id)->orderBy('id')->get(['id', 'Amount', 'PaidAt'])->toArray(),
            'newer bill Payment rows untouched');
        $this->assertSame([5000, 'paid'], [(int) $newer->fresh()->PaidAmount, (string) $newer->fresh()->Status]);
        $this->assertSame(1, PaymentReport::count());
    }

    /** One fixture case with id placeholders mapped; asserts its row equals the live alerts/tuition row the UI would get. */
    private function fixtureCase(string $name, array $ids): array
    {
        $all = json_decode((string) file_get_contents(base_path('tests/Fixtures/collection_requests_3875.json')), true, 512, JSON_THROW_ON_ERROR);
        $this->assertArrayHasKey($name, $all, "fixture case $name (regenerate: npx vitest run -u src/components/__tests__/TuitionCollectionBatchInvoiceTarget.test.js)");
        $case = $all[$name];
        array_walk_recursive($case, function (&$v) use ($ids) {
            if (is_string($v) && preg_match('/^\{\{(.+)\}\}$/', $v, $m)) {
                $this->assertArrayHasKey($m[1], $ids, "placeholder {$m[1]}");
                $v = (int) $ids[$m[1]];
            }
        });
        $live = $this->queueRow(StudentClass::findOrFail($ids['course']));
        foreach (['id', 'payment_status', 'payable_status', 'charge', 'payable_amount', 'payable_outstanding', 'payable_invoice_id'] as $field) {
            $this->assertEquals($case['row'][$field] ?? null, $live[$field] ?? null, "$name: frontend fixture row.$field differs from the live queue row");
        }
        return $case;
    }

    private function newerCoveredOlderOwing(): array
    {
        $sc = $this->course(['Charge' => 8000]);
        [$older, $newer] = [$this->invoice($sc, '2026-02-01', 3000, 'unpaid'), $this->invoice($sc, '2026-03-01', 5000, 'unpaid')];
        Payment::create(['InvoiceID' => $newer->id, 'Amount' => 5000, 'PaidAt' => '2026-03-05', 'Method' => 'cash']);
        return [$sc, $older, $newer];
    }

    private function zeroTotalInvoice(): array
    {
        return [$sc = $this->course(['Charge' => 6000]), $this->invoice($sc, '2026-04-01', 0, 'unpaid')];
    }

    private function storedPaidLedgerPartial(): array
    {
        $sc = $this->course(['Charge' => 6000]);
        $inv = $this->invoice($sc, '2026-04-01', 6000, 'paid', ['PaidAmount' => 6000]);
        Payment::create(['InvoiceID' => $inv->id, 'Amount' => 2000, 'PaidAt' => '2026-04-02', 'Method' => 'cash']); // rows 2,000 of 6,000
        return [$sc, $inv];
    }

    private function twoHistoricalOpen(int $olderOwes, int $newerOwes): array
    {
        return [$sc = $this->course(['Charge' => $olderOwes + $newerOwes]), $this->invoice($sc, '2026-01-01', $olderOwes, 'unpaid'), $this->invoice($sc, '2026-02-01', $newerOwes, 'unpaid')];
    }

    private function monthly(StudentClass $sc, string $period, int $total, int $paid, array $over = []): Invoice
    {
        $inv = Invoice::create(array_merge(['StudentID' => $sc->StudentID, 'StudentClassID' => $sc->ID, 'IssueDate' => "$period-01",
            'TotalAmount' => $total, 'PaidAmount' => $paid, 'Status' => 'partial', 'billing_period' => $period], $over));
        Payment::create(['InvoiceID' => $inv->id, 'Amount' => $paid, 'PaidAt' => "$period-05", 'Method' => 'cash']);
        return $inv;
    }

    private function invoice(StudentClass $sc, string $issueDate, int $total, string $status, array $over = []): Invoice
    {
        return Invoice::create(array_merge(['StudentID' => $sc->StudentID, 'StudentClassID' => $sc->ID, 'IssueDate' => $issueDate, 'TotalAmount' => $total, 'PaidAmount' => 0, 'Status' => $status], $over));
    }

    private function queueRow(StudentClass $sc): array
    {
        $rows = $this->withHeaders($this->headers)->getJson('/api/v1/alerts/tuition?branch_id=1')->assertOk()->json();
        return collect($rows)->first(fn ($r) => (int) $r['id'] === (int) $sc->ID && empty($r['package_id'])) ?? $this->fail('the course is not in the collection queue');
    }

    private function report(string $path, array $body)
    {
        return $this->withHeaders($this->headers)->postJson("/api/v1/payment-reports/$path", $body);
    }

    /** confirm; on success the chain continues to the receipt, which must show the confirmed amount (收據). */
    private function confirm($reportId)
    {
        $res = $this->withHeaders($this->headers)->putJson("/api/v1/payment-reports/$reportId/confirm");
        if ($res->status() === 200) {
            $this->withHeaders($this->headers)->getJson("/api/v1/payment-reports/$reportId/receipt")->assertOk()->assertJsonPath('amount', (int) PaymentReport::findOrFail($reportId)->reported_amount);
        }
        return $res;
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

    private function pendingReport(StudentClass $sc, ?Invoice $invoice, int|float $amount): PaymentReport
    {
        return PaymentReport::create(['StudentID' => $sc->StudentID, 'StudentClassID' => $sc->ID, 'InvoiceID' => $invoice?->id, 'reported_by_name' => 'x', ...self::D, 'reported_amount' => $amount,
            'status' => 'pending', 'report_token_hash' => hash('sha256', uniqid('r', true)), 'token_expires_at' => now()->addDay()]);
    }

    private function course(array $overrides): StudentClass
    {
        $student = Student::create(['name' => 's' . uniqid(), 'CampusID' => 1, 'ClassID' => 1, 'enable' => 1, 'Phone' => '0912345678', 'MDT' => now(), 'Notify_Token' => '']);
        return StudentClass::create(array_merge(['StudentID' => $student->id, 'GradeID' => 1, 'SubjectID' => 1, 'TeacherID' => 99, 'by1' => 1, 'Period' => 4,
            'StartDate' => now(), 'TotalHours' => 20, 'Charge' => 8800, 'Paid' => 0, 'MDate' => now(), 'Stop' => 0, 'ScheduleMode' => 'count', 'SessionCount' => 8, 'SessionDuration' => 120, 'RemainingSessions' => 5,
            'ClassType' => 'one_on_one', 'UsedSessions' => 0], $overrides));
    }
}
