<?php

namespace Tests\Feature;

use App\Models\PaymentReport;
use App\Models\StudentSignIn;
use App\Operations\Strategies\CourseContractRepairStrategy;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

final class CourseContractRepairStrategySchemaTest extends TestCase
{
    use RefreshDatabase;

    public function test_plan_runs_against_the_migrated_payment_schema(): void
    {
        self::assertTrue(Schema::hasTable('payment_reports'));
        self::assertSame('payment_reports', (new PaymentReport())->getTable());
        self::assertTrue(Schema::hasTable('StudentSingIn'));
        self::assertSame('StudentSingIn', (new StudentSignIn())->getTable());

        DB::table('StudentClass')->insert([
            $this->course(2531, 4400, 2),
            $this->course(3379, 5200, 5),
        ]);
        DB::table('Invoice')->insert([
            'id' => 1601,
            'StudentID' => 30,
            'StudentClassID' => 3379,
            'IssueDate' => '2026-07-01',
            'TotalAmount' => 4400,
            'PaidAmount' => 0,
            'Status' => 'unpaid',
            'Note' => '',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $sessions = [
            [24712, '2026-06-13', 'completed'],
            [21907, '2026-07-04', 'attended'],
            [26552, '2026-08-01', 'attended'],
            [21910, '2026-08-08', 'attended'],
            [24805, '2026-08-15', 'attended'],
            [26006, '2026-08-22', 'attended'],
            [29478, '2026-08-29', 'attended'],
        ];
        foreach ($sessions as [$id, $date, $status]) {
            DB::table('ClassSession')->insert([
                'id' => $id,
                'StudentClassID' => 2531,
                'SessionDate' => $date,
                'StartTime' => '10:00:00',
                'EndTime' => '12:00:00',
                'Status' => $status,
                'Note' => '',
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }

        $plan = app(CourseContractRepairStrategy::class)->plan([
            'student_id' => 30,
            'campus_id' => 9,
            'subject_id' => 66,
            'source_student_class_id' => 2531,
            'target_student_class_id' => 3379,
            'preserve_session_ids' => [24712, 21907],
            'transfer_session_ids' => [26552, 21910, 24805, 26006, 29478],
            'session_expectations' => array_map(
                static fn (array $session): array => [
                    'id' => $session[0],
                    'date' => $session[1],
                    'start_time' => '10:00',
                    'end_time' => '12:00',
                    'status' => $session[2],
                ],
                $sessions,
            ),
            'expected_source_charge' => 4400,
            'expected_target_charge' => 5200,
            'source_charge' => 2200,
            'target_charge' => 6500,
            'source_invoice_id' => null,
            'target_invoice_id' => 1601,
            'expected_source_invoice_total' => 0,
            'expected_target_invoice_total' => 4400,
            'reason' => 'huang-yikui-contract-repair',
            'decision_reference' => 'issue-2318',
        ]);

        self::assertTrue($plan['ok'], implode('; ', $plan['errors']));
        self::assertSame([], $plan['errors']);
        self::assertSame([26552, 21910, 24805, 26006, 29478], $plan['transfer_session_ids']);
    }

    /** @return array<string, int|string> */
    private function course(int $id, int $charge, int $sessionCount): array
    {
        return [
            'ID' => $id,
            'StudentID' => 30,
            'GradeID' => 1,
            'SubjectID' => 66,
            'TeacherID' => 1,
            'by1' => 9,
            'StartDate' => '2026-06-01 00:00:00',
            'TotalHours' => $sessionCount * 2,
            'Charge' => $charge,
            'Paid' => 0,
            'Stop' => 0,
            'ScheduleMode' => 'date',
            'SessionCount' => $sessionCount,
            'SessionDuration' => 120,
            'MDate' => now(),
        ];
    }
}
