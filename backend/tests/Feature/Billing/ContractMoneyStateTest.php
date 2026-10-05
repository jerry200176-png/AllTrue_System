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

    public function test_list_status(): void
    {
        $this->assertSame('paid', ContractMoneyState::listStatus(true, 0, 100, true), 'paid wins over older pending report (#249)');
        $this->assertSame('paid', ContractMoneyState::listStatus(false, 100, 100, false));
        $this->assertSame('pending_report', ContractMoneyState::listStatus(false, 40, 100, true));
        $this->assertSame('unpaid', ContractMoneyState::listStatus(false, 40, 100, false));
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

    /**
     * Known divergences between the legacy copies, preserved on purpose (report in PR):
     *  - alert ladder has no waived/free value; a waived-unpaid course reads `unpaid`, the parent card reads `waived`.
     *  - list status ignores partial payments (`unpaid`), the alert ladder says `partial`.
     *  - parent card `paid` = flag/package OR any invoice payment; parent record `paid` = Pay >= Charge (true for Charge 0).
     */
    public function test_documented_divergences_between_copies(): void
    {
        $waived = $this->course(['closed_reason' => 'waived']);
        $this->assertSame('unpaid', ContractMoneyState::alertStatus($waived, 0, 100, false));
        $this->assertSame('waived', ContractMoneyState::parentCardStatus(false, true, false)[0]);

        $this->assertSame('unpaid', ContractMoneyState::listStatus(false, 40, 100, false));
        $this->assertSame('partial', ContractMoneyState::alertStatus($this->course(), 40, 100, false));

        $this->assertSame('paid', ContractMoneyState::parentRecordStatus(false, 0, 0));
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
    }
}
