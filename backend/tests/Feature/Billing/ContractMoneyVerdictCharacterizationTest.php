<?php

namespace Tests\Feature\Billing;

use App\Http\Controllers\PaymentReportController;
use App\Models\ClassSession;
use App\Models\Invoice;
use App\Models\InvoiceItem;
use App\Models\Payment;
use App\Models\Student;
use App\Models\StudentClass;
use App\Services\Billing\ContractMoneyState;
use App\Services\BillingPayableResolver;
use App\Services\InvoiceAmountReconciliationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * ARCH2 PR0: pins what each money caller returns TODAY for the same contract fixtures (no behaviour change).
 * Where columns differ for one fixture, the callers disagree; that table is the evidence for the Verdict module.
 *
 * Columns (one string per caller):
 *   resolver  = BillingPayableResolver::courseStatusesByStudentClassIds  status/total/applied/outstanding/source
 *   payable   = BillingPayableResolver::byStudentClassIds (tuition slip)  status/amount/outstanding
 *   alert     = AlertController ladder: ContractMoneyState::alertStatus fed by SUM(stored PaidAmount) + courseOutstanding
 *   guard     = PaymentReportController::courseAlreadyHasConfirmedPayment (blocks a new payment report)
 *   intake    = PaymentReportController legacy intake guard (no open invoice && (Paid=1 || a paid invoice)) => duplicate
 *   recon     = InvoiceAmountReconciliationService over the course's invoices (Accounting/Coverage): total/applied/outstanding
 */
class ContractMoneyVerdictCharacterizationTest extends TestCase
{
    use RefreshDatabase;

    /** @param list<array{0:string,1:int,2:string,3:int,4:list<array{0:int,1:string}>}> $invoices [period,total,status,storedPaid,[[amt,method]]] */
    private function course(array $invoices, array $attrs = [], array $sessions = [], ?Student $student = null): StudentClass
    {
        $student ??= Student::create(['name' => 'ARCH2', 'CampusID' => 1, 'ClassID' => 1, 'enable' => 1, 'MDT' => now(), 'Notify_Token' => '']);
        $date = ($attrs['ScheduleMode'] ?? 'count') === 'date';
        $course = StudentClass::create($attrs + [
            'StudentID' => $student->id, 'GradeID' => 1, 'SubjectID' => 1, 'TeacherID' => 99, 'by1' => 1, 'Period' => 4,
            'StartDate' => $date ? '2026-07-01' : '2026-08-01', 'EndDate' => $date ? '2026-08-31' : null, 'TotalHours' => 20,
            'Charge' => 10000, 'Paid' => 0, 'Rate' => 1500, 'MDate' => now(), 'Stop' => 0, 'ScheduleMode' => 'count',
            'SessionCount' => $date ? 0 : 10, 'SessionDuration' => 120, 'RemainingSessions' => 8, 'UsedSessions' => 0,
            'ClassType' => 'one_on_one', 'LearnTimeID' => null,
        ]);
        foreach ($sessions as $d) {
            ClassSession::create(['StudentClassID' => $course->ID, 'SessionDate' => $d, 'StartTime' => '18:00', 'EndTime' => '20:00', 'Status' => 'attended']);
        }
        foreach ($invoices as [$period, $total, $status, $stored, $payments]) {
            $this->invoice($course, $student, $period, $total, $status, $stored, $payments);
        }

        return $course;
    }

    private function invoice(StudentClass $course, Student $student, string $period, int $total, string $status, int $stored, array $payments): Invoice
    {
        $invoice = Invoice::create(['StudentID' => $student->id, 'StudentClassID' => $course->ID, 'IssueDate' => "$period-01", 'DueDate' => "$period-15",
            'TotalAmount' => $total, 'PaidAmount' => $stored, 'Status' => $status, 'billing_period' => $period]);
        foreach ($payments as [$amount, $method]) {
            Payment::create(['InvoiceID' => $invoice->id, 'Amount' => $amount, 'PaidAt' => "$period-05", 'Method' => $method]);
        }

        return $invoice;
    }

    /** @return array<string, string> */
    private function probe(StudentClass $course): array
    {
        $id = (int) $course->ID;
        $course = StudentClass::query()->where('ID', $id)->firstOrFail();
        $resolver = app(BillingPayableResolver::class);
        $s = $resolver->courseStatusesByStudentClassIds([$id], [$course])[$id];
        $p = $resolver->byStudentClassIds([$id], [$course])[$id];

        // AlertController ~349-373 (count-mode charge = Charge here).
        $agg = $resolver->invoiceAggregateByStudentClassIds([$id])[$id] ?? null;
        $paid = $agg ? (int) $agg['paid_amount'] : 0;
        $charge = (int) $course->Charge;
        $isPaid = StudentClass::isFullyPaid((int) $course->Paid === 1 || $course->isEffectivelyPaid(), $paid, $charge);
        $alertOut = ($s['source'] === 'invoice') ? (int) $s['outstanding'] : ($isPaid ? 0 : max(0, $charge - $paid));
        $alert = ContractMoneyState::alertStatus($course, $paid, $charge, false) . "/$alertOut";

        $ctl = app(PaymentReportController::class);
        $m = new \ReflectionMethod($ctl, 'courseAlreadyHasConfirmedPayment');
        $guard = $m->invoke($ctl, $id, (int) $course->Paid) ? 'block' : 'allow';
        $open = Invoice::where('StudentClassID', $id)->where(fn ($q) => $q->whereNull('Status')->orWhereNotIn('Status', ['paid', 'void']))->exists();
        $paidInv = Invoice::where('StudentClassID', $id)->where('Status', 'paid')->exists();
        $intake = (!$open && ((int) $course->Paid === 1 || $paidInv)) ? 'duplicate' : 'ok';

        $recon = [0, 0, 0];
        foreach (Invoice::with(['payments', 'items'])->where('StudentClassID', $id)->where('Status', '!=', 'void')->get() as $inv) {
            $r = app(InvoiceAmountReconciliationService::class)->resolve($inv, $course);
            $recon[0] += $r['total_amount'];
            $recon[1] += $r['applied_amount'];
            $recon[2] += $r['outstanding_amount'];
        }

        return [
            'resolver' => "{$s['status']}/{$s['payable_total']}/{$s['applied']}/{$s['outstanding']}/{$s['source']}",
            'payable' => "{$p['payable_status']}/" . ($p['payable_amount'] ?? 'null') . '/' . ($p['payable_outstanding'] ?? 'null'),
            'alert' => $alert,
            'guard' => $guard,
            'intake' => $intake,
            'recon' => implode('/', $recon),
        ];
    }

    public function test_fixture_table(): void
    {
        $fx = [];
        $fx['paid1_unpaid_invoice'] = $this->course([['2026-08', 10000, 'unpaid', 0, []]], ['Paid' => 1]);
        $fx['paid1_no_invoice'] = $this->course([], ['Paid' => 1]);
        $fx['paid_invoice_flag0'] = $this->course([['2026-08', 10000, 'paid', 10000, [[10000, 'cash']]]]);
        $fx['partial_monthly'] = $this->course([['2026-08', 6000, 'partial', 2000, [[2000, 'cash']]]],
            ['ScheduleMode' => 'date', 'Charge' => 6000, 'Rate' => 1500], ['2026-08-03', '2026-08-10', '2026-08-17', '2026-08-24']);
        $fx['stopped_part_paid'] = $this->course([['2026-08', 10000, 'partial', 4000, [[4000, 'cash']]]],
            ['Stop' => 1, 'closed_reason' => 'settled_pending']);
        $fx['ended_monthly_no_invoice'] = $this->course([], ['ScheduleMode' => 'date', 'Charge' => 6000, 'EndDate' => '2026-07-31'], ['2026-07-06', '2026-07-13']);
        $fx['zero_dollar_trial'] = $this->course([], ['Charge' => 0, 'Rate' => 0]);
        // One invoice owned by course A whose item also covers course B.
        $student = Student::create(['name' => 'ARCH2 multi', 'CampusID' => 1, 'ClassID' => 1, 'enable' => 1, 'MDT' => now(), 'Notify_Token' => '']);
        $a = $this->course([], ['Charge' => 5000], [], $student);
        $b = $this->course([], ['Charge' => 5000], [], $student);
        $inv = $this->invoice($a, $student, '2026-08', 10000, 'partial', 3000, [[3000, 'cash']]);
        InvoiceItem::create(['InvoiceID' => $inv->id, 'StudentClassID' => $a->ID, 'Amount' => 5000, 'Description' => 'A']);
        InvoiceItem::create(['InvoiceID' => $inv->id, 'StudentClassID' => $b->ID, 'Amount' => 5000, 'Description' => 'B']);
        $fx['multi_course_invoice_owner'] = $a;
        $fx['multi_course_invoice_member'] = $b;

        $actual = array_map(fn ($c) => $this->probe($c), $fx);
        $this->assertSame(self::EXPECTED, $actual);
    }

    private const EXPECTED = [
        'paid1_unpaid_invoice' => [
            'resolver' => 'unpaid/10000/0/10000/invoice',
            'payable' => 'invoiced/10000/10000',
            'alert' => 'paid/10000',
            'guard' => 'block',
            'intake' => 'ok',
            'recon' => '10000/0/10000',
        ],
        'paid1_no_invoice' => [
            'resolver' => 'paid/10000/10000/0/legacy_flag',
            'payable' => 'unbilled/null/null',
            'alert' => 'paid/0',
            'guard' => 'block',
            'intake' => 'duplicate',
            'recon' => '0/0/0',
        ],
        'paid_invoice_flag0' => [
            'resolver' => 'paid/10000/10000/0/invoice',
            'payable' => 'invoiced/10000/0',
            'alert' => 'paid/0',
            'guard' => 'block',
            'intake' => 'duplicate',
            'recon' => '10000/10000/0',
        ],
        'partial_monthly' => [
            'resolver' => 'review_required/6000/2000/4000/invoice',
            'payable' => 'invoiced/6000/4000',
            'alert' => 'partial/4000',
            'guard' => 'allow',
            'intake' => 'ok',
            'recon' => '6000/2000/4000',
        ],
        'stopped_part_paid' => [
            'resolver' => 'partial/10000/4000/6000/invoice',
            'payable' => 'invoiced/10000/6000',
            'alert' => 'pending_reconciliation/6000',
            'guard' => 'allow',
            'intake' => 'ok',
            'recon' => '10000/4000/6000',
        ],
        'ended_monthly_no_invoice' => [
            'resolver' => 'unbilled/6000/0/6000/none',
            'payable' => 'unbilled/null/null',
            'alert' => 'unpaid/6000',
            'guard' => 'allow',
            'intake' => 'ok',
            'recon' => '0/0/0',
        ],
        'zero_dollar_trial' => [
            'resolver' => 'free/0/0/0/none',
            'payable' => 'unbilled/null/null',
            'alert' => 'unpaid/0',
            'guard' => 'block',
            'intake' => 'ok',
            'recon' => '0/0/0',
        ],
        'multi_course_invoice_owner' => [
            'resolver' => 'partial/10000/3000/7000/invoice',
            'payable' => 'invoiced/10000/7000',
            'alert' => 'partial/7000',
            'guard' => 'allow',
            'intake' => 'ok',
            'recon' => '10000/3000/7000',
        ],
        'multi_course_invoice_member' => [
            'resolver' => 'unbilled/5000/0/5000/none',
            'payable' => 'unbilled/null/null',
            'alert' => 'unpaid/5000',
            'guard' => 'allow',
            'intake' => 'ok',
            'recon' => '0/0/0',
        ],
    ];
}
