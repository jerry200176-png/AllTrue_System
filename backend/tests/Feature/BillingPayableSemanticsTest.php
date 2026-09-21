<?php

namespace Tests\Feature;

use App\Models\Invoice;
use App\Models\Student;
use App\Models\StudentClass;
use App\Services\BillingPayableResolver;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class BillingPayableSemanticsTest extends TestCase
{
    use RefreshDatabase;

    /**
     * The payable resolver must remain stable across the billing shapes that
     * have historically exposed StudentClass.Charge as a false payable.
     */
    public function test_payable_matrix_uses_invoice_and_not_course_charge(): void
    {
        $student = Student::create([
            'name' => '應繳語義回歸', 'CampusID' => 1, 'ClassID' => 1,
            'enable' => 1, 'MDT' => now(), 'Notify_Token' => '',
        ]);

        $cases = [
            ['label' => 'course charge equals invoice total', 'mode' => 'count', 'charge' => 10000, 'invoice' => 10000, 'status' => 'unpaid'],
            ['label' => 'course charge below invoice total', 'mode' => 'count', 'charge' => 11000, 'invoice' => 16500, 'status' => 'unpaid'],
            ['label' => 'course charge above invoice total', 'mode' => 'count', 'charge' => 16500, 'invoice' => 11000, 'status' => 'unpaid'],
            ['label' => 'invoice exists', 'mode' => 'count', 'charge' => 9000, 'invoice' => 12000, 'status' => 'partial'],
            ['label' => 'no invoice', 'mode' => 'count', 'charge' => 9000, 'invoice' => null, 'status' => null],
            ['label' => 'monthly mode', 'mode' => 'date', 'charge' => 12000, 'invoice' => 15000, 'status' => 'paid'],
            ['label' => 'per-session mode', 'mode' => 'count', 'charge' => 8250, 'invoice' => 8250, 'status' => 'paid'],
            ['label' => 'stopped course', 'mode' => 'count', 'charge' => 8000, 'invoice' => 8000, 'status' => 'paid', 'stop' => 1],
            ['label' => 'multiple billing periods', 'mode' => 'count', 'charge' => 9000, 'invoice' => 16500, 'status' => 'unpaid', 'multiple' => true],
            ['label' => 'extra attended sessions beyond original count', 'mode' => 'count', 'charge' => 11000, 'invoice' => 16500, 'status' => 'paid', 'used' => 6, 'sessions' => 4],
        ];

        foreach ($cases as $index => $case) {
            $course = StudentClass::create([
                'StudentID' => $student->id,
                'GradeID' => 1,
                'SubjectID' => 1,
                'TeacherID' => 99,
                'by1' => 1,
                'Period' => 4,
                'StartDate' => '2026-08-01',
                'EndDate' => '2026-08-31',
                'TotalHours' => 20,
                'Charge' => $case['charge'],
                'Paid' => 0,
                'Pay' => null,
                'PayDate' => null,
                'Rate' => 2750,
                'Disconunt' => null,
                'LearnTimeID' => null,
                'MDate' => now(),
                'Stop' => $case['stop'] ?? 0,
                'ScheduleMode' => $case['mode'],
                'SessionCount' => $case['sessions'] ?? 4,
                'SessionDuration' => 120,
                'RemainingSessions' => 0,
                'UsedSessions' => $case['used'] ?? 0,
                'ClassType' => 'one_on_one',
                'settlement_day' => 15,
            ]);

            if ($case['invoice'] !== null) {
                Invoice::create([
                    'StudentID' => $student->id,
                    'StudentClassID' => $course->ID,
                    'IssueDate' => '2026-08-01',
                    'DueDate' => '2026-08-15',
                    'TotalAmount' => $case['invoice'],
                    'PaidAmount' => $case['status'] === 'paid' ? $case['invoice'] : 0,
                    'Status' => $case['status'],
                    'billing_period' => '2026-08',
                ]);
            }

            if (!empty($case['multiple'])) {
                Invoice::create([
                    'StudentID' => $student->id,
                    'StudentClassID' => $course->ID,
                    'IssueDate' => '2026-07-01',
                    'DueDate' => '2026-07-15',
                    'TotalAmount' => 9000,
                    'PaidAmount' => 9000,
                    'Status' => 'paid',
                    'billing_period' => '2026-07',
                ]);
            }

            $resolved = app(BillingPayableResolver::class)
                ->byStudentClassIds([$course->ID], [$course])[$course->ID];

            if ($case['invoice'] === null) {
                $this->assertSame('unbilled', $resolved['payable_status'], $case['label']);
                $this->assertNull($resolved['payable_amount'], $case['label']);
                continue;
            }

            $expected = !empty($case['multiple']) ? 16500 : $case['invoice'];
            $this->assertSame('invoiced', $resolved['payable_status'], $case['label']);
            $this->assertSame($expected, $resolved['payable_amount'], $case['label']);
            $this->assertSame('invoice', $resolved['payable_source'], $case['label']);
            $this->assertSame('2026-08', $resolved['payable_billing_period'], $case['label']);
        }
    }
}
