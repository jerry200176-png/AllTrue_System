<?php

namespace Tests\Feature\Billing;

use App\Models\AuthToken;
use App\Models\Invoice;
use App\Models\Payment;
use App\Models\Student;
use App\Models\StudentClass;
use App\Models\User;
use App\Models\UserCampus;
use App\Services\BillingPayableResolver;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * The ledger's per-invoice 繳費日 (standing_payment) is the resolver's last-paid netting (latestStandingReceipt), the rule
 * lastPaidAtByStudentClassIds dates with. These were the screen's own copy (courseMoneyState.latestStandingPayment) before.
 */
class LatestStandingReceiptTest extends TestCase
{
    use RefreshDatabase;

    private function standing(array $rows): ?array
    {
        return app(BillingPayableResolver::class)->latestStandingReceipt(collect($rows)->map(fn (array $r) => (object) ($r + [
            'Method' => 'cash', 'payment_report_id' => null, 'report_payment_id' => null,
        ])));
    }

    public function test_reversal_netting_matches_the_rule_the_screen_used(): void
    {
        // a reversal sharing the receipt's report cancels it: nothing stands
        $this->assertNull($this->standing([
            ['id' => 1, 'PaidAt' => '2026-09-06', 'Amount' => 6000, 'payment_report_id' => 5],
            ['id' => 2, 'PaidAt' => '2026-09-07', 'Amount' => -6000, 'Method' => 'void', 'payment_report_id' => 5],
        ]));
        // reversed, then repaid: the repayment stands
        $this->assertSame(['paid_at' => '2026-09-20', 'amount' => 6000], $this->standing([
            ['id' => 1, 'PaidAt' => '2026-09-06', 'Amount' => 6000],
            ['id' => 2, 'PaidAt' => '2026-09-07', 'Amount' => -6000, 'Method' => 'void'],
            ['id' => 3, 'PaidAt' => '2026-09-20', 'Amount' => 6000],
        ]));
        // an orphan reversal (no earlier receipt) cannot reverse a later repayment's date away, but still nets the invoice to zero
        $this->assertNull($this->standing([
            ['id' => 1, 'PaidAt' => '2026-09-07', 'Amount' => -6000, 'Method' => 'void'],
            ['id' => 2, 'PaidAt' => '2026-09-20', 'Amount' => 6000],
        ]));
        // the reversal takes its own report's receipt, the other one stands
        $this->assertSame(['paid_at' => '2026-09-01', 'amount' => 3000], $this->standing([
            ['id' => 1, 'PaidAt' => '2026-09-01', 'Amount' => 3000],
            ['id' => 2, 'PaidAt' => '2026-09-05', 'Amount' => 3000, 'payment_report_id' => 8],
            ['id' => 3, 'PaidAt' => '2026-09-06', 'Amount' => -3000, 'Method' => 'void', 'payment_report_id' => 8],
        ]));
        $this->assertNull($this->standing([]));
        // a legacy-backfilled receipt is linked one way (its report's payment_id); a partial reversal leaves the rest standing
        $this->assertSame(['paid_at' => '2026-09-01', 'amount' => 2000], $this->standing([
            ['id' => 1, 'PaidAt' => '2026-09-01', 'Amount' => 5000],
            ['id' => 2, 'PaidAt' => '2026-09-02', 'Amount' => -3000, 'Method' => 'void', 'payment_report_id' => 9, 'report_payment_id' => 1],
        ]));
        // same date: the later-written receipt stands (the date is the same either way)
        $this->assertSame(['paid_at' => '2026-09-05', 'amount' => 2000], $this->standing([
            ['id' => 1, 'PaidAt' => '2026-09-05', 'Amount' => 1000],
            ['id' => 2, 'PaidAt' => '2026-09-05', 'Amount' => 2000],
        ]));
        // rows arrive in any order: reversals still apply in write (id) order
        $this->assertSame(['paid_at' => '2026-09-20', 'amount' => 6000], $this->standing([
            ['id' => 3, 'PaidAt' => '2026-09-20', 'Amount' => 6000],
            ['id' => 2, 'PaidAt' => '2026-09-07', 'Amount' => -6000, 'Method' => 'void'],
            ['id' => 1, 'PaidAt' => '2026-09-06', 'Amount' => 6000],
        ]));
    }

    public function test_ledger_invoice_carries_the_same_answer_as_the_last_paid_date(): void
    {
        [$sc, $bill, $token] = $this->paidBill();
        Payment::create(['InvoiceID' => $bill->id, 'Amount' => 6000, 'PaidAt' => '2026-09-06', 'Method' => 'cash']);
        Payment::create(['InvoiceID' => $bill->id, 'Amount' => -6000, 'PaidAt' => '2026-09-07', 'Method' => 'void']);
        Payment::create(['InvoiceID' => $bill->id, 'Amount' => 6000, 'PaidAt' => '2026-09-20', 'Method' => 'transfer']);

        $invoice = $this->ledgerInvoice($sc, $bill, $token);
        $this->assertSame(['paid_at' => '2026-09-20', 'amount' => 6000], $invoice['standing_payment']);
        $this->assertSame($invoice['standing_payment']['paid_at'], app(BillingPayableResolver::class)->lastPaidAtByStudentClassIds([(int) $sc->ID])[(int) $sc->ID]);
    }

    /** Paid only by moved credit (轉課): the card shows the transfer date; the list's 最後繳費 (cash receipts only) stays empty. */
    public function test_a_bill_paid_only_by_moved_credit_shows_the_transfer_date_on_the_ledger_only(): void
    {
        [$sc, $bill, $token] = $this->paidBill();
        Payment::create(['InvoiceID' => $bill->id, 'Amount' => 6000, 'PaidAt' => '2026-09-12', 'Method' => 'transfer_in']);

        $this->assertSame(['paid_at' => '2026-09-12', 'amount' => 6000, 'method' => 'transfer_in'], $this->ledgerInvoice($sc, $bill, $token)['standing_payment']);
        $this->assertArrayNotHasKey((int) $sc->ID, app(BillingPayableResolver::class)->lastPaidAtByStudentClassIds([(int) $sc->ID]));

        // moved back out: nothing stands
        Payment::create(['InvoiceID' => $bill->id, 'Amount' => -6000, 'PaidAt' => '2026-09-13', 'Method' => 'transfer_reversal_out']);
        $this->assertNull($this->ledgerInvoice($sc, $bill, $token)['standing_payment']);
    }

    /** @return array{0: StudentClass, 1: Invoice, 2: string} */
    private function paidBill(): array
    {
        $user = User::create(['LoginName' => 'standing@test.com', 'Name' => 'd', 'PSW' => 'x', 'type' => 'A', 'phone' => 912345678]);
        UserCampus::create(['CampusID' => 1, 'UserID' => $user->id, 'Admin' => 1, 'Approved' => 1]);
        $token = bin2hex(random_bytes(16));
        AuthToken::create(['user_id' => $user->id, 'token' => $token, 'expires_at' => now()->addDay()]);
        $student = Student::create(['name' => 's' . uniqid(), 'CampusID' => 1, 'ClassID' => 1, 'enable' => 1, 'MDT' => now(), 'Notify_Token' => '']);
        $sc = StudentClass::create([
            'StudentID' => $student->id, 'GradeID' => 1, 'SubjectID' => 1, 'TeacherID' => 99, 'by1' => 1, 'Period' => 4,
            'StartDate' => '2026-09-01', 'TotalHours' => 0, 'Charge' => 6000, 'Paid' => 0, 'Rate' => 1500, 'MDate' => now(), 'Stop' => 0,
            'ScheduleMode' => 'count', 'SessionCount' => 4, 'SessionDuration' => 60, 'RemainingSessions' => 4, 'ClassType' => 'one_on_one', 'UsedSessions' => 0,
        ]);
        $bill = Invoice::create(['StudentID' => $student->id, 'StudentClassID' => $sc->ID, 'IssueDate' => '2026-09-01', 'DueDate' => '2026-09-15',
            'TotalAmount' => 6000, 'PaidAmount' => 6000, 'Status' => 'paid']);

        return [$sc, $bill, $token];
    }

    private function ledgerInvoice(StudentClass $sc, Invoice $bill, string $token): array
    {
        return collect($this->withHeaders(['Authorization' => "Bearer {$token}", 'Accept' => 'application/json'])
            ->getJson("/api/v1/accounting/ledger?branch_id=1&student_class_id={$sc->ID}")->assertOk()->json('invoices'))->firstWhere('id', $bill->id);
    }
}
