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

    /** @return array{status: string, outstanding: int} */
    private function courseRow(StudentClass $sc): array
    {
        $row = app(BillingPayableResolver::class)->courseStatusesByStudentClassIds([(int) $sc->ID])[(int) $sc->ID];

        return ['status' => $row['status'], 'outstanding' => (int) $row['outstanding']];
    }
}
