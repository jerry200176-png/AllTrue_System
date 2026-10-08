<?php

namespace Tests\Feature\Ops;

use App\Operations\Strategies\Invoice1997StaleValuesStrategy;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

final class Invoice1997StaleValuesStrategyTest extends TestCase
{
    use RefreshDatabase;

    private const PARAMS = ['decision_reference' => 'repair-invoice-1997-stale-values-20261008'];
    private const ADDED = ['class_session_id' => 9305, 'date' => '2026-09-30', 'start_time' => '18:00',
        'end_time' => '20:00', 'subject' => 'Math', 'lesson' => 5, 'status' => 'attended'];

    protected function setUp(): void
    {
        parent::setUp();
        Invoice1997StaleValuesStrategy::useCaseForTesting([
            'invoice_id' => 9300, 'item_id' => 9301, 'course_id' => 9302, 'student_id' => 9302,
            'rate' => 1500, 'session_count' => 5, 'old_amount' => 6000, 'new_amount' => 7500,
            'total' => 7500, 'payment_ids' => [9303], 'period' => ['2026-09-01', '2026-09-30'],
            'session_ids' => [9301, 9302, 9303, 9304, 9305], 'old_snapshot_sessions' => [9301, 9302, 9303, 9304],
            'added_session' => self::ADDED,
        ]);
        DB::table('Student')->insert(['id' => 9302, 'name' => 's', 'CampusID' => 1, 'ClassID' => 1, 'enable' => 1]);
        DB::table('StudentClass')->insert(['ID' => 9302, 'StudentID' => 9302, 'GradeID' => 1, 'SubjectID' => 1, 'TeacherID' => 1,
            'by1' => 1, 'Period' => 4, 'Pay' => 0, 'Rate' => 1500, 'TotalHours' => 4, 'StartDate' => '2026-09-01',
            'EndDate' => '2026-09-30', 'ClassType' => 'one_on_one', 'ScheduleMode' => 'date', 'SessionCount' => 5,
            'Stop' => 1, 'Paid' => 1, 'Charge' => 6000, 'closed_reason' => 'settled']);
        $old = ['charge' => 6000, 'period_sessions' => 4, 'period_start' => '2026-09-01', 'period_end' => '2026-09-30',
            'source' => 'billable_sessions', 'sessions' => array_map(fn ($i) => ['class_session_id' => 9300 + $i,
                'date' => '2026-09-0' . $i, 'start_time' => '18:00', 'end_time' => '20:00', 'subject' => 'Math',
                'lesson' => $i, 'status' => 'attended'], [1, 2, 3, 4])];
        DB::table('Invoice')->insert(['id' => 9300, 'StudentID' => 9302, 'StudentClassID' => 9302, 'IssueDate' => '2026-09-29',
            'TotalAmount' => 7500, 'PaidAmount' => 7500, 'Status' => 'paid', 'Note' => '', 'billing_period' => '2026-09',
            'billing_snapshot' => json_encode($old), 'created_at' => now(), 'updated_at' => now()]);
        DB::table('InvoiceItem')->insert(['id' => 9301, 'InvoiceID' => 9300, 'StudentClassID' => 9302, 'Description' => 'm',
            'Amount' => 6000, 'PeriodStart' => '2026-09-01', 'PeriodEnd' => '2026-09-30', 'created_at' => now(), 'updated_at' => now()]);
        DB::table('Payment')->insert(['id' => 9303, 'InvoiceID' => 9300, 'Amount' => 7500, 'PaidAt' => now(), 'Method' => 'transfer', 'created_at' => now()]);
        foreach ([1, 2, 3, 4, 5] as $i) {
            DB::table('ClassSession')->insert(['id' => 9300 + $i, 'StudentClassID' => 9302, 'SessionDate' => '2026-09-0' . $i,
                'StartTime' => '18:00:00', 'EndTime' => '20:00:00', 'Status' => 'attended', 'created_at' => now(), 'updated_at' => now()]);
        }
        DB::table('ClassSession')->where('id', 9305)->update(['SessionDate' => '2026-09-30']);
    }

    protected function tearDown(): void
    {
        Invoice1997StaleValuesStrategy::useCaseForTesting(null);
        parent::tearDown();
    }

    private function money(): array
    {
        $i = DB::table('Invoice')->where('id', 9300)->first();

        return [(int) $i->TotalAmount, (int) $i->PaidAmount, (string) $i->Status, (int) DB::table('Payment')->sum('Amount'),
            (int) DB::table('StudentClass')->where('ID', 9302)->value('Paid')];
    }

    public function test_plan_is_read_only_and_reports_from_to_pairs(): void
    {
        $plan = (new Invoice1997StaleValuesStrategy())->plan(self::PARAMS);
        self::assertTrue($plan['ok'], implode(',', $plan['errors']));
        self::assertSame('before', $plan['state']);
        self::assertSame([6000, 7500], $plan['manifest']['item_amount']);
        self::assertSame([6000, 7500], $plan['manifest']['course_charge']);
        self::assertSame(6000, (int) DB::table('InvoiceItem')->where('id', 9301)->value('Amount'));
        self::assertSame(['case_parameters_mismatch'], (new Invoice1997StaleValuesStrategy())->plan([])['errors']);
    }

    public function test_execute_updates_exactly_three_values_and_leaves_money_untouched(): void
    {
        $s = new Invoice1997StaleValuesStrategy();
        $moneyBefore = $this->money();
        $plan = $s->plan(self::PARAMS);
        $result = $s->execute($plan, ['operation_id' => 't']);
        self::assertSame(3, $result['updated']);
        self::assertSame(7500, (int) DB::table('InvoiceItem')->where('id', 9301)->value('Amount'));
        $course = DB::table('StudentClass')->where('ID', 9302)->first();
        self::assertSame(0, (int) $course->Charge - (int) $course->Rate * (int) $course->SessionCount, 'no preserved delta left');
        $snap = json_decode((string) DB::table('Invoice')->where('id', 9300)->value('billing_snapshot'), true);
        self::assertSame([7500, 5, 9305], [$snap['charge'], $snap['period_sessions'], end($snap['sessions'])['class_session_id']]);
        self::assertSame($moneyBefore, $this->money());
        $v = $s->verify($plan, $result);
        self::assertTrue($v['ok'], implode(',', $v['errors']));
        self::assertSame('after', $s->plan(self::PARAMS)['state']);
        self::assertSame(1, DB::table('security_audit_events')->where('event_type', 'pop.invoice1997_stale_values')->count());
    }

    public function test_any_drift_aborts_the_plan(): void
    {
        $s = new Invoice1997StaleValuesStrategy();
        DB::table('InvoiceItem')->where('id', 9301)->update(['Amount' => 6500]);
        DB::table('Payment')->insert(['InvoiceID' => 9300, 'Amount' => 100, 'PaidAt' => now(), 'Method' => 'cash', 'created_at' => now()]);
        $plan = $s->plan(self::PARAMS);
        self::assertFalse($plan['ok']);
        self::assertEqualsCanonicalizing(['item_amount', 'payments'], $plan['errors']);
        try {
            $s->execute($plan, []);
            self::fail('expected abort');
        } catch (\RuntimeException) {
            self::assertSame(6500, (int) DB::table('InvoiceItem')->where('id', 9301)->value('Amount'));
        }
    }

    public function test_retry_is_idempotent_and_rollback_restores_the_old_values(): void
    {
        $s = new Invoice1997StaleValuesStrategy();
        $s->execute($s->plan(self::PARAMS), []);
        $again = $s->plan(self::PARAMS);
        self::assertSame('after', $again['state']);
        $result = $s->execute($again, []);
        self::assertTrue($result['already_applied']);
        self::assertTrue($s->verify($again, $result)['ok']);
        $out = $s->rollback($result['snapshot'], []);
        self::assertTrue($out['ok']);
        self::assertSame('before', $s->plan(self::PARAMS)['state']);
        self::assertSame(6000, (int) DB::table('StudentClass')->where('ID', 9302)->value('Charge'));
    }

    public function test_rollback_skips_when_payments_changed_after_the_repair(): void
    {
        $s = new Invoice1997StaleValuesStrategy();
        $result = $s->execute($s->plan(self::PARAMS), []);
        DB::table('Payment')->insert(['InvoiceID' => 9300, 'Amount' => 100, 'PaidAt' => now(), 'Method' => 'cash', 'created_at' => now()]);
        self::assertFalse($s->rollback($result['snapshot'], [])['ok']);
        self::assertSame(7500, (int) DB::table('StudentClass')->where('ID', 9302)->value('Charge'));
    }
}
