<?php

namespace Tests\Feature\Ops;

use App\Operations\Strategies\ContractChargeCorrectionStrategy;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use RuntimeException;
use Tests\TestCase;

final class ContractChargeCorrectionStrategyTest extends TestCase
{
    use RefreshDatabase;

    private const REPAIR = ['decision_reference' => 'repair-contract-charge-3868-20261029'];
    private const ROLLBACK = ['decision_reference' => 'rollback-contract-charge-3868-20261029'];

    protected function setUp(): void
    {
        parent::setUp();
        // Rate x sessions is 4000 and 1100 (Founder 2026-10-29); both Charges still hold the pre-amendment value.
        $this->course(2569, 2569, rate: 1000, sessions: 4, charge: 8000);
        $this->course(3288, 3288, rate: 1100, sessions: 1, charge: 8800);
    }

    private function course(int $id, int $studentId, int $rate, int $sessions, int $charge): void
    {
        DB::table('Student')->insert(['id' => $studentId, 'name' => 's', 'CampusID' => 1, 'ClassID' => 1, 'enable' => 1]);
        DB::table('StudentClass')->insert(['ID' => $id, 'StudentID' => $studentId, 'GradeID' => 1, 'SubjectID' => 1, 'TeacherID' => 1,
            'by1' => 1, 'Period' => 4, 'Pay' => 0, 'Rate' => $rate, 'TotalHours' => 4, 'StartDate' => '2026-09-01',
            'EndDate' => '2026-09-30', 'ClassType' => 'one_on_one', 'ScheduleMode' => 'count', 'SessionCount' => $sessions,
            'Stop' => 1, 'Paid' => 0, 'Charge' => $charge, 'closed_reason' => 'contract_amended']);
    }

    private function charges(): array
    {
        return DB::table('StudentClass')->whereIn('ID', [2569, 3288])->orderBy('ID')->pluck('Charge', 'ID')->map(fn ($c) => (int) $c)->all();
    }

    private function others(): string
    {
        return json_encode([DB::table('StudentClass')->orderBy('ID')->get()->map(fn ($r) => array_diff_key((array) $r, ['Charge' => 1]))->all(),
            DB::table('Invoice')->count(), DB::table('Payment')->count()]);
    }

    public function test_plan_is_read_only_and_reports_the_precheck_facts(): void
    {
        $s = new ContractChargeCorrectionStrategy();
        $plan = $s->plan(self::REPAIR);
        self::assertTrue($plan['ok'], implode(',', $plan['errors']));
        self::assertSame('before', $plan['state']);
        self::assertSame(['courses' => 2, 'to_change' => 2], $plan['counts']);
        self::assertSame([8000, 4000, 4000, 0], [$plan['courses'][2569]['charge'], $plan['courses'][2569]['charge_target'][1],
            $plan['courses'][2569]['rate_times_quantity'], $plan['courses'][2569]['preserved_delta_if_applied']]);
        self::assertSame([0, 0, 0, 0], [$plan['courses'][3288]['invoices'], $plan['courses'][3288]['payment_evidence'],
            $plan['courses'][3288]['pricing_amendments'], $plan['courses'][3288]['session_charges']]);
        self::assertSame([8000, 8800], array_values($this->charges()));
        self::assertSame(['case_parameters_mismatch'], $s->plan(['decision_reference' => 'x'])['errors']);
    }

    public function test_execute_writes_only_the_two_charges_audits_and_verifies(): void
    {
        $s = new ContractChargeCorrectionStrategy();
        $before = $this->others();
        $plan = $s->plan(self::REPAIR);
        $result = $s->execute($plan, ['operation_id' => 't']);
        self::assertSame(2, $result['updated']);
        self::assertSame([2569 => 4000, 3288 => 1100], $this->charges());
        self::assertSame($before, $this->others(), 'nothing but Charge is written');
        $v = $s->verify($plan, $result);
        self::assertTrue($v['ok'], implode(',', $v['errors']));
        self::assertSame('after', $s->plan(self::REPAIR)['state']);
        self::assertSame(2, DB::table('security_audit_events')->where('event_type', 'pop.contract_charge_correction')->count());
    }

    public function test_retry_is_idempotent_and_the_reverse_manifest_restores_the_old_charges(): void
    {
        $s = new ContractChargeCorrectionStrategy();
        $s->execute($s->plan(self::REPAIR), []);
        $again = $s->plan(self::REPAIR);
        self::assertSame('after', $again['state']);
        self::assertTrue($s->execute($again, [])['already_applied']);
        $back = $s->plan(self::ROLLBACK);
        self::assertTrue($back['ok'], implode(',', $back['errors']));
        self::assertSame('before', $back['state']);
        $s->execute($back, []);
        self::assertSame([2569 => 8000, 3288 => 8800], $this->charges());
        self::assertSame('after', $s->plan(self::ROLLBACK)['state']);
    }

    public function test_strategy_rollback_restores_while_unchanged_and_skips_after_drift(): void
    {
        $s = new ContractChargeCorrectionStrategy();
        $result = $s->execute($s->plan(self::REPAIR), []);
        DB::table('StudentClass')->where('ID', 3288)->update(['Charge' => 1200]);
        self::assertFalse($s->rollback($result['snapshot'], [])['ok']);
        self::assertSame([2569 => 4000, 3288 => 1200], $this->charges());
        DB::table('StudentClass')->where('ID', 3288)->update(['Charge' => 1100]);
        self::assertTrue($s->rollback($result['snapshot'], [])['ok']);
        self::assertSame([2569 => 8000, 3288 => 8800], $this->charges());
        $this->expectException(RuntimeException::class);
        $s->rollback(['manifest' => [2569 => [1, 2, 3]]], []);
    }

    /** @dataProvider conflicts */
    public function test_any_conflict_fails_the_whole_plan_and_execute_writes_nothing(string $code, callable $arrange): void
    {
        $arrange();
        $charges = $this->charges();
        $s = new ContractChargeCorrectionStrategy();
        $plan = $s->plan(self::REPAIR);
        self::assertFalse($plan['ok']);
        self::assertContains($code, $plan['errors']);
        try {
            $s->execute($plan, []);
            self::fail('expected abort');
        } catch (RuntimeException) {
            self::assertSame($charges, $this->charges());
        }
    }

    public static function conflicts(): array
    {
        $invoice = function (string $status = 'unpaid', int $paid = 0): void {
            DB::table('Invoice')->insert(['id' => 9100, 'StudentID' => 3288, 'StudentClassID' => 3288, 'IssueDate' => '2026-09-29',
                'TotalAmount' => 8800, 'PaidAmount' => $paid, 'Status' => $status, 'Note' => '', 'created_at' => now(), 'updated_at' => now()]);
        };

        return [
            'issued invoice' => ['course_3288_invoice_present', $invoice],
            'invoice paid' => ['course_3288_payment_evidence', fn () => $invoice('paid', 8800)],
            'payment on a void invoice' => ['course_3288_payment_evidence', function () use ($invoice): void {
                $invoice('void');
                DB::table('Payment')->insert(['InvoiceID' => 9100, 'Amount' => 100, 'PaidAt' => now(), 'Method' => 'cash', 'created_at' => now()]);
            }],
            'paid flag' => ['course_2569_paid_flag', fn () => DB::table('StudentClass')->where('ID', 2569)->update(['Paid' => 1])],
            'charge drifted' => ['course_2569_charge_drifted', fn () => DB::table('StudentClass')->where('ID', 2569)->update(['Charge' => 7000])],
            'rate changed' => ['course_3288_rate_quantity_drifted', fn () => DB::table('StudentClass')->where('ID', 3288)->update(['Rate' => 1000])],
            'package member' => ['course_2569_package', fn () => DB::table('StudentClass')->where('ID', 2569)->update(['PackageID' => 5])],
            'not count mode' => ['course_2569_schedule_mode', fn () => DB::table('StudentClass')->where('ID', 2569)->update(['ScheduleMode' => 'date'])],
            'mixed state' => ['mixed_state', fn () => DB::table('StudentClass')->where('ID', 2569)->update(['Charge' => 4000])],
            'missing course' => ['course_3288_missing', fn () => DB::table('StudentClass')->where('ID', 3288)->delete()],
            'active pricing amendment' => ['course_3288_pricing_amendment_active', fn () => DB::table('student_class_pricing_amendments')->insert(
                ['student_class_id' => 3288, 'effective_from' => '2026-09-15', 'rate' => 1100, 'source_reference' => 't', 'reason' => 't'])],
            'per-session charge' => ['course_2569_session_charge_adjustments', function (): void {
                DB::table('ClassSession')->insert(['id' => 9101, 'StudentClassID' => 2569, 'SessionDate' => '2026-09-02', 'StartTime' => '18:00:00',
                    'EndTime' => '20:00:00', 'Status' => 'attended', 'session_charge' => 900, 'created_at' => now(), 'updated_at' => now()]);
            }],
        ];
    }
}
