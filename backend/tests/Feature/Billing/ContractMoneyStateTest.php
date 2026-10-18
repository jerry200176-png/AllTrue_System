<?php

namespace Tests\Feature\Billing;

use App\Models\Student;
use App\Models\StudentClass;
use App\Services\Billing\ContractMoneyState;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Characterization of the contract money-state rules lifted out of AlertController /
 * ParentPortalController / StudentClassController. Expected values are what the pre-refactor
 * controllers returned; the "divergence" test documents where the copies disagree on purpose.
 */
class ContractMoneyStateTest extends TestCase
{
    use RefreshDatabase;

    private function course(array $attrs = []): StudentClass
    {
        return (new StudentClass())->forceFill(array_merge(
            ['Paid' => 0, 'ScheduleMode' => 'count', 'RemainingSessions' => 8, 'closed_reason' => null], $attrs));
    }

    /** @return array<string, array{array, int, int, bool, string}> */
    public static function alertLadder(): array
    {
        return [
            'no course' => [null, 0, 0, false, 'unpaid'],
            'pending report beats everything' => [['Paid' => 1], 0, 100, true, 'pending_report'],
            'closed unpaid settled_pending' => [['closed_reason' => 'settled_pending'], 0, 100, false, 'pending_reconciliation'],
            'closed unpaid contract_amended' => [['closed_reason' => 'contract_amended'], 50, 100, false, 'pending_reconciliation'],
            'partial' => [[], 40, 100, false, 'partial'],
            'unpaid' => [[], 0, 100, false, 'unpaid'],
            'free charge 0 unpaid flag' => [[], 0, 0, false, 'unpaid'],
            'paid flag, plenty left' => [['Paid' => 1], 0, 100, false, 'paid'],
            'paid by invoice amount, flag never flipped' => [[], 100, 100, false, 'paid'],
            'renew needed (count, <=2 left)' => [['Paid' => 1, 'RemainingSessions' => 2], 0, 100, false, 'renew_needed'],
            'monthly (date mode)' => [['Paid' => 1, 'ScheduleMode' => 'date'], 0, 100, false, 'monthly_due_soon'],
        ];
    }

    /** @dataProvider alertLadder */
    public function test_alert_status_ladder(?array $attrs, int $paid, int $charge, bool $pending, string $expected): void
    {
        $sc = $attrs === null ? null : $this->course($attrs);
        $this->assertSame($expected, ContractMoneyState::alertStatus($sc, $paid, $charge, $pending));
    }

    public function test_alert_status_follows_the_verdict_over_flag_and_stored_sum(): void
    {
        $flagged = $this->course(['Paid' => 1, 'RemainingSessions' => 8]);
        $this->assertSame('unpaid', ContractMoneyState::alertStatus($flagged, 100, 100, false, 'unpaid'), 'Paid=1 + unpaid invoices reads unpaid');
        $this->assertSame('partial', ContractMoneyState::alertStatus($flagged, 0, 100, false, 'partial'));
        $unflagged = $this->course(['Paid' => 0, 'RemainingSessions' => 8]);
        $this->assertSame('paid', ContractMoneyState::alertStatus($unflagged, 0, 100, false, 'paid'), 'paid by Payment rows although the stored sum says 0');
        $this->assertSame('pending_reconciliation', ContractMoneyState::alertStatus($this->course(['closed_reason' => 'settled_pending']), 0, 100, false, 'unpaid'));
        $this->assertSame('paid', ContractMoneyState::alertStatus($this->course(['Paid' => 1, 'RemainingSessions' => 8]), 0, 100, false, 'review_required'), 'no verdict keeps the legacy inputs');
    }

    public function test_list_status(): void
    {
        $this->assertSame('paid', ContractMoneyState::listStatus(true, 0, 100, true), 'paid wins over older pending report (#249)');
        $this->assertSame('paid', ContractMoneyState::listStatus(false, 100, 100, false));
        $this->assertSame('pending_report', ContractMoneyState::listStatus(false, 40, 100, true));
        $this->assertSame('partial', ContractMoneyState::listStatus(false, 40, 100, false), 'part-paid reads partial like the alert ladder');
        $this->assertSame('unpaid', ContractMoneyState::listStatus(false, 0, 100, false));
    }

    public function test_parent_card_and_record_status(): void
    {
        $this->assertSame(['free', '免費（不適用）'], ContractMoneyState::parentCardStatus(true, true, true));
        $this->assertSame(['waived', '已確認不收'], ContractMoneyState::parentCardStatus(false, true, true));
        $this->assertSame(['paid', '已繳費'], ContractMoneyState::parentCardStatus(false, false, true));
        $this->assertSame(['unpaid', '未繳費'], ContractMoneyState::parentCardStatus(false, false, false));

        $this->assertSame('waived', ContractMoneyState::parentRecordStatus(true, 100, 100));
        $this->assertSame('paid', ContractMoneyState::parentRecordStatus(false, 100, 100));
        $this->assertSame('partial', ContractMoneyState::parentRecordStatus(false, 40, 100));
        $this->assertSame('unpaid', ContractMoneyState::parentRecordStatus(false, 0, 100));
    }

    /** Founder-approved 2026-10-06: one answer per case across the classifiers (waived, partial, free). */
    public function test_unified_waived_partial_and_free(): void
    {
        $waived = $this->course(['closed_reason' => 'waived']);
        $this->assertSame('waived', ContractMoneyState::alertStatus($waived, 0, 100, false));
        $this->assertSame('waived', ContractMoneyState::alertStatus($waived, 0, 100, true));
        $this->assertSame('waived', ContractMoneyState::parentCardStatus(false, true, false)[0]);

        $this->assertSame('partial', ContractMoneyState::listStatus(false, 40, 100, false));
        $this->assertSame('partial', ContractMoneyState::alertStatus($this->course(), 40, 100, false));
        $this->assertSame('partial', ContractMoneyState::parentRecordStatus(false, 40, 100));
        $this->assertSame('pending_report', ContractMoneyState::listStatus(false, 40, 100, true));
        $this->assertSame('unpaid', ContractMoneyState::listStatus(false, 0, 100, false));

        $this->assertSame('free', ContractMoneyState::parentRecordStatus(false, 0, 0));
        $this->assertSame('free', ContractMoneyState::parentRecordStatus(false, 50, 0));
    }

    public function test_waived_guard(): void
    {
        $this->assertFalse(ContractMoneyState::isWaived(null));
        $this->assertFalse(ContractMoneyState::isWaived($this->course()));
        $this->assertNull(ContractMoneyState::waivedRefusal($this->course(), 'x'));

        $waived = $this->course(['closed_reason' => 'waived']);
        $plain = ContractMoneyState::waivedRefusal($waived, '訊息');
        $this->assertSame(422, $plain->getStatusCode());
        $this->assertSame(['message' => '訊息'], $plain->getData(true));
        $this->assertSame(['message' => '訊息', 'code' => 'course_waived'], ContractMoneyState::waivedRefusal($waived, '訊息', 'course_waived')->getData(true));
    }

    private function seedCourse(): int
    {
        $student = Student::create(['name' => '金額狀態測試生', 'CampusID' => 1, 'ClassID' => 1, 'SchoolName' => 'T', 'enable' => 1, 'MDT' => now(), 'Notify_Token' => '']);

        return (int) StudentClass::create([
            'StudentID' => $student->id, 'GradeID' => 1, 'SubjectID' => 1, 'TeacherID' => 99, 'by1' => 1, 'Period' => 4,
            'StartDate' => '2026-04-01', 'TotalHours' => 20, 'Charge' => 1000, 'Paid' => 0, 'Rate' => 0, 'MDate' => now(),
            'Stop' => 0, 'ScheduleMode' => 'count', 'SessionCount' => 8, 'SessionDuration' => 60, 'RemainingSessions' => 8,
            'ClassType' => 'one_on_one', 'UsedSessions' => 0,
        ])->ID;
    }

    private function invoice(int $classId, string $status, int $total, int $paidAmount): int
    {
        return DB::table('Invoice')->insertGetId(['StudentID' => 1, 'StudentClassID' => $classId, 'IssueDate' => '2026-09-01',
            'TotalAmount' => $total, 'PaidAmount' => $paidAmount, 'Status' => $status]);
    }

    private function payment(int $invoiceId, int $amount, ?string $method, string $paidAt): void
    {
        DB::table('Payment')->insert(['InvoiceID' => $invoiceId, 'Amount' => $amount, 'Method' => $method, 'PaidAt' => $paidAt]);
    }

    public function test_has_active_payment_matrix(): void
    {
        $none = $this->seedCourse();
        $this->assertFalse(ContractMoneyState::hasActivePayment($none));

        $unpaidInvoice = $this->seedCourse();
        $this->invoice($unpaidInvoice, 'unpaid', 1000, 0);
        $this->assertFalse(ContractMoneyState::hasActivePayment($unpaidInvoice));

        $paidAmountOnly = $this->seedCourse();
        $this->invoice($paidAmountOnly, 'partial', 1000, 300);
        $this->assertTrue(ContractMoneyState::hasActivePayment($paidAmountOnly));

        $realPayment = $this->seedCourse();
        $this->payment($this->invoice($realPayment, 'unpaid', 1000, 0), 500, 'cash', '2026-09-02 10:00:00');
        $this->assertTrue(ContractMoneyState::hasActivePayment($realPayment));

        $voidPaymentOnly = $this->seedCourse();
        $this->payment($this->invoice($voidPaymentOnly, 'unpaid', 1000, 0), 500, 'void', '2026-09-02 10:00:00');
        $this->assertFalse(ContractMoneyState::hasActivePayment($voidPaymentOnly));

        $voidInvoice = $this->seedCourse();
        $this->payment($this->invoice($voidInvoice, 'void', 1000, 500), 500, 'cash', '2026-09-02 10:00:00');
        $this->assertFalse(ContractMoneyState::hasActivePayment($voidInvoice));
    }

    public function test_correction_refuses_case_or_space_ambiguous_void_methods(): void
    {
        foreach (['VOID', 'void '] as $method) {
            $course = $this->seedCourse();
            $invoice = $this->invoice($course, 'unpaid', 8000, 0);
            $this->payment($invoice, 8000, 'cash', '2026-09-02 10:00:00');
            $this->payment($invoice, 8000, $method, '2026-09-03 10:00:00');
            $this->assertTrue(ContractMoneyState::blocksUnpaidCorrection($course));
        }
    }

    public function test_invoice_aggregate_and_last_paid_at(): void
    {
        $id = $this->seedCourse();
        $a = $this->invoice($id, 'partial', 1000, 300);
        $this->invoice($id, 'unpaid', 500, 0);
        $this->invoice($id, 'void', 9999, 9999);
        $this->payment($a, 300, 'cash', '2026-09-02 10:00:00');
        $this->payment($a, 100, 'cash', '2026-09-05 09:00:00');
        $voided = $this->invoice($id, 'void', 100, 100);
        $this->payment($voided, 100, 'cash', '2026-10-01 09:00:00');
        $other = $this->seedCourse();

        $this->assertSame([$id => ['paid_amount' => 300, 'total_amount' => 1500, 'active_invoice_count' => 2, 'outstanding_amount' => 1200]],
            ContractMoneyState::invoiceAggregateByStudentClassIds([$id, $other]));
        $this->assertSame([$id => '2026-09-05'], ContractMoneyState::lastPaidAtByStudentClassIds([$id, $other]));
        $this->assertSame([], ContractMoneyState::invoiceAggregateByStudentClassIds([]));
        $this->assertSame([], ContractMoneyState::lastPaidAtByStudentClassIds([]));

        // ARCH2-C2 S1: the aggregates moved to the resolver; the static is a thin delegate (same numbers).
        $resolver = app(\App\Services\BillingPayableResolver::class);
        $this->assertSame(ContractMoneyState::invoiceAggregateByStudentClassIds([$id, $other]), $resolver->invoiceAggregateByStudentClassIds([$id, $other]));
        $this->assertSame([$id => '2026-09-05'], $resolver->lastPaidAtByStudentClassIds([$id, $other]));
        $this->assertSame([], $resolver->invoiceAggregateByStudentClassIds([]));
    }

    public function test_last_paid_at_ignores_void_and_negative_payment_rows(): void
    {
        $id = $this->seedCourse();
        $a = $this->invoice($id, 'partial', 1000, 300);
        $this->payment($a, 300, 'cash', '2026-09-02 10:00:00');
        $this->payment($a, -100, 'void', '2026-09-10 10:00:00');
        $this->payment($a, 100, 'void', '2026-09-11 10:00:00');
        $this->payment($a, -50, 'cash', '2026-09-12 10:00:00');
        $this->payment($a, 0, 'cash', '2026-09-13 10:00:00');
        $onlyReversals = $this->seedCourse();
        $this->payment($this->invoice($onlyReversals, 'unpaid', 1000, 0), -1000, 'void', '2026-09-20 10:00:00');

        $resolver = app(\App\Services\BillingPayableResolver::class);
        $this->assertSame([$id => '2026-09-02'], $resolver->lastPaidAtByStudentClassIds([$id, $onlyReversals]));
    }

    public function test_last_paid_at_is_empty_when_receipts_are_fully_reversed_without_a_report_link(): void
    {
        // PaidStatusParityTest fixtures void_negative_cash and void_method_positive: net applied is 0, so no date.
        $negativeCash = $this->seedCourse();
        $a = $this->invoice($negativeCash, 'unpaid', 1000, 0);
        $this->payment($a, 1000, 'cash', '2026-09-02 10:00:00');
        $this->payment($a, -1000, 'cash', '2026-09-03 10:00:00');
        $positiveVoid = $this->seedCourse();
        $b = $this->invoice($positiveVoid, 'unpaid', 1000, 0);
        $this->payment($b, 1000, 'cash', '2026-09-02 10:00:00');
        $this->payment($b, 1000, 'void', '2026-09-03 10:00:00');
        // a partial reversal cancels the newest receipt first; the older one still stands
        $partial = $this->seedCourse();
        $c = $this->invoice($partial, 'partial', 1000, 300);
        $this->payment($c, 300, 'cash', '2026-09-02 10:00:00');
        $this->payment($c, 100, 'cash', '2026-09-05 10:00:00');
        $this->payment($c, -100, 'cash', '2026-09-06 10:00:00');

        $resolver = app(\App\Services\BillingPayableResolver::class);
        $this->assertSame([$partial => '2026-09-02'], $resolver->lastPaidAtByStudentClassIds([$negativeCash, $positiveVoid, $partial]));
    }

    public function test_last_paid_at_follows_the_repayment_after_a_legacy_receipt_is_voided(): void
    {
        // #4149 review: BackfillLegacyPayments links the receipt only via PaymentReport.payment_id (no payment_report_id);
        // PaymentReportController::void then writes a reversal carrying the report id. A later repayment must give the date.
        $legacy = $this->seedCourse();
        $a = $this->invoice($legacy, 'paid', 1000, 1000);
        $receiptA = $this->ledgerRow($a, 1000, 'cash', '2026-03-01');
        $reportA = $this->confirmedReport($legacy, $a, 1000, '2026-03-01', $receiptA);
        $this->ledgerRow($a, -1000, 'void', '2026-04-20', $reportA);
        $reportB = $this->confirmedReport($legacy, $a, 1000, '2026-05-05');
        $this->ledgerRow($a, 1000, 'transfer', '2026-05-05', $reportB);

        // a reversal with no link at all can only reverse a receipt that already existed when it was written
        $unlinked = $this->seedCourse();
        $b = $this->invoice($unlinked, 'paid', 1000, 1000);
        $this->ledgerRow($b, 1000, 'cash', '2026-03-01');
        $this->ledgerRow($b, -1000, 'cash', '2026-04-20');
        $this->ledgerRow($b, 1000, 'cash', '2026-05-05');

        // an unlinked reversal that does not identify one receipt fails safe to the newest standing receipt
        $ambiguous = $this->seedCourse();
        $c = $this->invoice($ambiguous, 'partial', 1000, 300);
        $this->ledgerRow($c, 300, 'cash', '2026-09-02');
        $this->ledgerRow($c, 300, 'cash', '2026-09-05');
        $this->ledgerRow($c, -300, 'void', '2026-09-06');

        $resolver = app(\App\Services\BillingPayableResolver::class);
        $this->assertSame(
            [$legacy => '2026-05-05', $unlinked => '2026-05-05', $ambiguous => '2026-09-05'],
            $resolver->lastPaidAtByStudentClassIds([$legacy, $unlinked, $ambiguous])
        );
    }

    private function ledgerRow(int $invoiceId, int $amount, string $method, string $paidAt, ?int $reportId = null): int
    {
        return (int) DB::table('Payment')->insertGetId(['InvoiceID' => $invoiceId, 'Amount' => $amount, 'Method' => $method,
            'PaidAt' => $paidAt, 'payment_report_id' => $reportId]);
    }

    private function confirmedReport(int $classId, int $invoiceId, int $amount, string $date, ?int $paymentId = null): int
    {
        return (int) \App\Models\PaymentReport::create(['StudentID' => 1, 'StudentClassID' => $classId, 'InvoiceID' => $invoiceId,
            'reported_by_name' => 'x', 'payment_date' => $date, 'payment_method' => 'cash', 'reported_amount' => $amount,
            'status' => 'confirmed', 'payment_id' => $paymentId, 'report_token_hash' => hash('sha256', "r{$invoiceId}-{$date}"),
            'token_expires_at' => now()])->id;
    }

    public function test_include_voided_scopes_retained_void_history_without_changing_legacy_default(): void
    {
        // Live unpaid invoice alone: neither default nor includeVoided blocks on empty money.
        $liveOnly = $this->seedCourse();
        $this->invoice($liveOnly, 'unpaid', 8000, 0);
        $this->assertFalse(ContractMoneyState::blocksUnpaidCorrection($liveOnly));
        $this->assertFalse(ContractMoneyState::blocksUnpaidCorrection($liveOnly, true));

        // Void invoice with +cash/-void and no payment_report_id on the reversal: default
        // (#4217 live-invoice filter) ignores void history; includeVoided=true must see it and block.
        $course = $this->seedCourse();
        $this->invoice($course, 'unpaid', 8000, 0); // live bill remains open
        $voided = $this->invoice($course, 'void', 500, 0);
        $this->payment($voided, 500, 'cash', '2026-09-01 10:00:00');
        $this->payment($voided, -500, 'void', '2026-09-02 10:00:00');
        $this->assertFalse(ContractMoneyState::blocksUnpaidCorrection($course), 'legacy default excludes void invoices');
        $this->assertTrue(ContractMoneyState::blocksUnpaidCorrection($course, true), 'includeVoided inspects retained void history');
    }

}
