<?php

namespace Tests\Feature\Billing;

use App\Models\Student;
use App\Models\StudentClass;
use App\Services\Billing\ContractRenewal;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/** Direct tests of the renewal module interface (ARCH2-C3 slice 1). */
class ContractRenewalTest extends TestCase
{
    use RefreshDatabase;

    private function course(array $over = []): StudentClass
    {
        $student = Student::create([
            'name' => '續報模組', 'CampusID' => 1, 'ClassID' => 1, 'enable' => 1, 'MDT' => now(), 'Notify_Token' => '',
        ]);

        return StudentClass::create(array_merge([
            'StudentID' => $student->id, 'GradeID' => 1, 'SubjectID' => 1, 'TeacherID' => 99, 'by1' => 1, 'Period' => 4,
            'StartDate' => '2032-03-01', 'TotalHours' => 20, 'Charge' => 4000, 'Paid' => 0, 'Rate' => 500, 'MDate' => now(),
            'Stop' => 0, 'ScheduleMode' => 'count', 'SessionCount' => 8, 'SessionDuration' => 120, 'RemainingSessions' => 8,
            'ClassType' => 'one_on_one', 'UsedSessions' => 0, 'week' => 2, 'time' => '20:00:00',
        ], $over));
    }

    public function test_purchase_batch_preview_prices_sessions_and_lists_schedule(): void
    {
        $r = app(ContractRenewal::class)->previewPurchaseBatch(
            $this->course(), ['sessions' => 6, 'start_date' => '2032-04-06'], 1, 'director'
        );

        $this->assertSame([], $r['blockers']);
        $this->assertSame(3000, $r['proposed_course']['charge']);
        $this->assertSame(6, $r['schedule']['created_sessions']);
        $this->assertSame('2032-04-06', $r['schedule']['first_session_date']);
        $this->assertSame('unpaid', $r['billing']['payment_status_after_confirm']);
        $this->assertSame(3000, $r['billing']['amount_due']);
    }

    public function test_purchase_batch_preview_blocks_missing_input_and_monthly_course(): void
    {
        $codes = fn (array $r) => array_column($r['blockers'], 'code');
        $svc = app(ContractRenewal::class);

        $this->assertSame(['sessions_required', 'start_date_required'], $codes($svc->previewPurchaseBatch($this->course(), [], 1, 'director')));
        $this->assertContains(
            'monthly_course_purchase_batch',
            $codes($svc->previewPurchaseBatch($this->course(['ScheduleMode' => 'date']), ['sessions' => 2, 'start_date' => '2032-04-06'], 1, 'director'))
        );
    }

    public function test_duplicate_finders_and_redaction(): void
    {
        $svc = app(ContractRenewal::class);
        $source = $this->course();
        $twin = StudentClass::create(array_merge($source->getAttributes(), ['ID' => null, 'StartDate' => '2032-05-04', 'SessionCount' => 6]));
        unset($twin);

        $this->assertNull($svc->findDuplicatePurchaseBatch($source, '2032-05-04', 7));
        $this->assertNotNull($svc->findDuplicatePurchaseBatch($source, '2032-05-04', 6));

        $p = ['billing' => ['discount' => ['x' => 1], 'k' => 2], 'payload' => ['discount' => 1]];
        $this->assertSame(['billing' => ['k' => 2], 'payload' => []], $svc->redactRenewalDiscount($p, false));
        $this->assertSame($p, $svc->redactRenewalDiscount($p, true));
    }
}
