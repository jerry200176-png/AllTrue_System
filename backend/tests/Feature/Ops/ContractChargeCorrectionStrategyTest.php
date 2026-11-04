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

    /** in-app #472: 2525 was cut 80 -> 76 lessons; its 83600 bill is paid and right, only Charge still says 88000. */
    private function billed2525(int $total = 83600, int $paid = 83600, string $reportStatus = 'confirmed'): void
    {
        $this->course(2525, 2525, rate: 1100, sessions: 76, charge: 88000);
        DB::table('StudentClass')->where('ID', 2525)->update(['Paid' => 1, 'Stop' => 0, 'closed_reason' => null, 'PayDate' => '2026-09-01']);
        DB::table('Invoice')->insert(['id' => 2466, 'StudentID' => 2525, 'StudentClassID' => 2525, 'IssueDate' => '2026-09-01',
            'TotalAmount' => $total, 'PaidAmount' => $paid, 'Status' => $paid >= $total ? 'paid' : 'partial', 'Note' => '', 'created_at' => now(), 'updated_at' => now()]);
        DB::table('Payment')->insert(['InvoiceID' => 2466, 'Amount' => $paid, 'PaidAt' => now(), 'Method' => 'transfer', 'created_at' => now()]);
        DB::table('payment_reports')->insert(['StudentID' => 2525, 'StudentClassID' => 2525, 'InvoiceID' => 2466, 'reported_by_name' => 'p',
            'payment_date' => '2026-09-01', 'payment_method' => 'transfer', 'reported_amount' => $paid, 'status' => $reportStatus,
            'report_token_hash' => str_repeat('b', 64), 'token_expires_at' => now()->addDay(), 'created_at' => now(), 'updated_at' => now()]);
    }

    public function test_472_corrects_a_paid_contract_whose_bill_already_matches_and_reverses(): void
    {
        $this->billed2525();
        $s = new ContractChargeCorrectionStrategy();
        $bill = json_encode([DB::table('Invoice')->get(), DB::table('Payment')->get()]);
        $plan = $s->plan(['decision_reference' => 'repair-contract-charge-472-20261104']);
        self::assertTrue($plan['ok'], implode(',', $plan['errors']));
        self::assertSame('before', $plan['state']);
        $s->execute($plan, ['operation_id' => 'op-472']);
        self::assertSame(83600, (int) DB::table('StudentClass')->where('ID', 2525)->value('Charge'));
        self::assertSame($bill, json_encode([DB::table('Invoice')->get(), DB::table('Payment')->get()]));
        self::assertTrue($s->verify($plan, ['ok' => true, 'snapshot' => $plan['snapshot']])['ok']);
        self::assertTrue($s->rollback($plan['snapshot'], [])['ok']);
        self::assertSame(88000, (int) DB::table('StudentClass')->where('ID', 2525)->value('Charge'));
        // The 3868 contracts are untouched by the 472 manifest.
        self::assertSame([8000, 8800], array_values($this->charges()));
    }

    public function test_472_refuses_when_the_bill_is_not_settled_at_rate_times_quantity(): void
    {
        foreach ([[88000, 88000, 'confirmed'], [83600, 40000, 'confirmed'], [83600, 83600, 'pending']] as [$total, $paid, $report]) {
            foreach (['payment_reports', 'Payment', 'Invoice'] as $t) DB::table($t)->delete();
            DB::table('StudentClass')->where('ID', 2525)->delete();
            DB::table('Student')->where('id', 2525)->delete();
            $this->billed2525($total, $paid, $report);
            $plan = (new ContractChargeCorrectionStrategy())->plan(['decision_reference' => 'repair-contract-charge-472-20261104']);
            self::assertSame(['course_2525_bills_not_settled_at_rate_times_quantity'], $plan['errors'], "$total/$paid/$report");
        }
    }

    public function test_4138_manifest_sets_the_total_from_the_keyed_lesson_price_and_reverses(): void
    {
        // #4138: 455 per lesson keyed as the total of a 20-lesson contract; Rate x 20 = 9100.
        $this->course(4050, 4050, rate: 455, sessions: 20, charge: 455);
        $this->course(4051, 4051, rate: 455, sessions: 20, charge: 455);
        $s = new ContractChargeCorrectionStrategy();
        $repair = ['decision_reference' => 'repair-contract-charge-4138-20261029'];
        $plan = $s->plan($repair);
        self::assertTrue($plan['ok'], implode(',', $plan['errors']));
        self::assertSame(['courses' => 2, 'to_change' => 2], $plan['counts']);
        self::assertSame([4050, 4051], array_keys($plan['courses']), 'the 3868 courses are out of scope');
        self::assertSame(2, $s->execute($plan, [])['updated']);
        $charges = fn () => DB::table('StudentClass')->whereIn('ID', [4050, 4051])->orderBy('ID')->pluck('Charge')->map(fn ($c) => (int) $c)->all();
        self::assertSame([9100, 9100], $charges());
        self::assertSame([8000, 8800], array_values($this->charges()));
        $s->execute($s->plan(['decision_reference' => 'rollback-contract-charge-4138-20261029']), []);
        self::assertSame([455, 455], $charges());
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
            'retained payment on a void item-linked invoice' => ['course_3288_payment_evidence', function (): void {
                DB::table('Invoice')->insert(['id' => 9102, 'StudentID' => 3288, 'StudentClassID' => null, 'IssueDate' => '2026-09-29',
                    'TotalAmount' => 8800, 'PaidAmount' => 0, 'Status' => 'void', 'Note' => '', 'created_at' => now(), 'updated_at' => now()]);
                DB::table('InvoiceItem')->insert(['id' => 9103, 'InvoiceID' => 9102, 'StudentClassID' => 3288, 'Description' => 'm', 'Amount' => 8800,
                    'PeriodStart' => '2026-09-01', 'PeriodEnd' => '2026-09-30', 'created_at' => now(), 'updated_at' => now()]);
                DB::table('Payment')->insert(['InvoiceID' => 9102, 'Amount' => 8800, 'PaidAt' => now(), 'Method' => 'transfer', 'created_at' => now()]);
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
