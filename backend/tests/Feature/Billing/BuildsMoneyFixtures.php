<?php

namespace Tests\Feature\Billing;

use App\Models\ClassSession;
use App\Models\Invoice;
use App\Models\Payment;
use App\Models\Student;
use App\Models\StudentClass;

/** Shared contract/invoice fixture builders for the Contract Money Verdict tests (ARCH2). */
trait BuildsMoneyFixtures
{
    /** @param list<array{0:string,1:int,2:string,3:int,4:list<array{0:int,1:string}>}> $invoices [period,total,status,storedPaid,[[amt,method]]] */
    protected function course(array $invoices, array $attrs = [], array $sessions = [], ?Student $student = null): StudentClass
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

    protected function invoice(StudentClass $course, Student $student, string $period, int $total, string $status, int $stored, array $payments): Invoice
    {
        $invoice = Invoice::create(['StudentID' => $student->id, 'StudentClassID' => $course->ID, 'IssueDate' => "$period-01", 'DueDate' => "$period-15",
            'TotalAmount' => $total, 'PaidAmount' => $stored, 'Status' => $status, 'billing_period' => $period]);
        foreach ($payments as [$amount, $method]) {
            Payment::create(['InvoiceID' => $invoice->id, 'Amount' => $amount, 'PaidAt' => "$period-05", 'Method' => $method]);
        }

        return $invoice;
    }

    /**
     * Seeded random contract (count|date mode, 0..3 invoices, unpaid/partial/full/over/void-payment/void-invoice,
     * $0 trial, Paid flag, package member). Shared by the Verdict invariants and the cross-caller property.
     *
     * @return array{course: StudentClass, date: bool, perMonth: array<string,int>, trial: bool, paidFlag: int, package: bool, nInvoices: int}
     */
    protected function randomContract(int $seed): array
    {
        $rate = 1500;
        mt_srand($seed);
        $date = mt_rand(0, 1) === 1;
        $trial = mt_rand(1, 10) === 1;
        $paidFlag = mt_rand(1, 4) === 1 ? 1 : 0;
        $package = mt_rand(1, 12) === 1;
        $months = array_slice(['2026-06', '2026-07', '2026-08'], 0, mt_rand(0, 3) === 0 ? 3 : 2);
        $nInvoices = $trial ? 0 : mt_rand(0, 3);

        // Lessons: date mode = 0..4 held lessons per month; count mode = 0..12 past lessons in Aug.
        $sessions = [];
        $perMonth = [];
        if ($date) {
            foreach (['2026-06', '2026-07', '2026-08'] as $m) {
                $perMonth[$m] = mt_rand(0, 4);
                for ($d = 1; $d <= $perMonth[$m]; $d++) {
                    $sessions[] = sprintf('%s-%02d', $m, $d * 6);
                }
            }
        } else {
            for ($d = 1; $d <= mt_rand(0, 12); $d++) {
                $sessions[] = sprintf('2026-08-%02d', $d);
            }
        }

        $attrs = ['Paid' => $paidFlag];
        if ($date) {
            $attrs += ['ScheduleMode' => 'date', 'Rate' => $rate, 'Charge' => 6000];
        }
        if ($trial) {
            $attrs = ['Charge' => 0, 'Rate' => 0, 'Paid' => $paidFlag] + ($date ? ['ScheduleMode' => 'date'] : []);
        }
        if ($package) {
            $attrs['PackageID'] = 1;
        }
        $course = $this->course([], $attrs, $sessions);
        $student = Student::findOrFail($course->StudentID);

        $kinds = ['unpaid', 'partial', 'full', 'over', 'voided_payment', 'void_invoice'];

        $shuffled = $months;
        for ($i = 0; $i < $nInvoices; $i++) {
            if ($i >= count($shuffled)) {
                break; // one invoice per month
            }
            $period = $shuffled[$i];
            $kind = $kinds[mt_rand(0, 5)];
            $total = $date && ($perMonth[$period] ?? 0) > 0 ? $perMonth[$period] * $rate : mt_rand(1, 20) * 500;
            $pay = match ($kind) {
                'partial' => [[mt_rand(1, $total - 1 ?: 1), 'cash']],
                'full', 'void_invoice' => [[$total, 'cash']],
                'over' => [[$total + 500, 'cash']],
                'voided_payment' => [[$total, 'cash'], [$total, 'void']],
                default => [],
            };
            $status = $kind === 'void_invoice' ? 'void' : ['unpaid' => 'unpaid', 'partial' => 'partial', 'full' => 'paid', 'over' => 'paid', 'voided_payment' => 'unpaid'][$kind];
            $this->invoice($course, $student, $period, $total, $status, mt_rand(0, 1) * $total, $pay);
        }

        return ['course' => $course->fresh(), 'date' => $date, 'perMonth' => $perMonth, 'trial' => $trial,
            'paidFlag' => $paidFlag, 'package' => $package, 'nInvoices' => $nInvoices];
    }
}
