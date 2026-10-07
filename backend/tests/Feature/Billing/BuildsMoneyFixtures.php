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
}
