<?php

namespace Tests\Feature\Ops;

use App\Models\StudentClass;
use App\Operations\Strategies\MuzhaChenBillingCatchupStrategy;
use App\Services\MonthlyBillingService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

final class MuzhaChenBillingCatchupStrategyTest extends TestCase
{
    use RefreshDatabase;

    private const REF = 'repair-muzha-chen-billing-catchup-20261005';
    private const PARAMS = ['decision_reference' => self::REF];
    private const LESSONS = ['2026-05-06', '2026-06-02', '2026-06-09', '2026-06-16', '2026-07-07', '2026-07-14', '2026-07-21', '2026-08-04'];

    protected function setUp(): void
    {
        parent::setUp();
        foreach ([164, 162] as $id) {
            DB::table('Student')->insert(['id' => $id, 'name' => "s{$id}", 'CampusID' => 16, 'ClassID' => 1, 'enable' => 1]);
        }
        $this->course(1249, 164, '2026-04-01', '2026-08-14', ['SubjectID' => 66, 'TeacherID' => 29, 'Paid' => 1, 'closed_reason' => 'settled', 'Charge' => 6600]);
        $this->invoice(422, 1249, '2026-04', 6600, 'paid'); // April only
        DB::table('InvoiceItem')->insert(['InvoiceID' => 422, 'StudentClassID' => 1249, 'Description' => 'April', 'Amount' => 6600,
            'PeriodStart' => '2026-04-01', 'PeriodEnd' => '2026-04-30']);
        foreach (self::LESSONS as $d) {
            $this->lesson(1249, $d, 'attended');
        }
        $this->lesson(1249, '2026-06-23', 'cancelled'); // not billable
        $this->invoice(1053, 2564, '2026-07', 6000, 'unpaid', 162);
    }

    private function course(int $id, int $student, string $start, string $end, array $over = []): void
    {
        DB::table('StudentClass')->insert(array_merge(['ID' => $id, 'StudentID' => $student, 'GradeID' => 1, 'SubjectID' => 1, 'TeacherID' => 1,
            'by1' => 1, 'Period' => 4, 'Pay' => 0, 'Rate' => 0, 'TotalHours' => 4, 'StartDate' => $start, 'EndDate' => $end,
            'ClassType' => 'one_on_one', 'ScheduleMode' => 'date', 'Stop' => 1, 'Paid' => 0, 'Charge' => 100], $over));
    }

    private function lesson(int $class, string $date, string $status): void
    {
        DB::table('ClassSession')->insert(['StudentClassID' => $class, 'SessionDate' => $date, 'StartTime' => '16:00', 'EndTime' => '18:00', 'Status' => $status]);
    }

    private function invoice(int $id, int $classId, string $period, int $total, string $status, int $student = 164): void
    {
        DB::table('Invoice')->insert(['id' => $id, 'StudentID' => $student, 'StudentClassID' => $classId, 'IssueDate' => '2026-07-01',
            'TotalAmount' => $total, 'PaidAmount' => $status === 'paid' ? $total : 0, 'Status' => $status, 'Note' => '', 'billing_period' => $period,
            'created_at' => now(), 'updated_at' => now()]);
    }

    private function pay(int $invoice): void
    {
        DB::table('Payment')->insert(['InvoiceID' => $invoice, 'Amount' => 100, 'PaidAt' => now(), 'Method' => 'cash', 'created_at' => now()]);
    }

    private function errors(): array
    {
        return (new MuzhaChenBillingCatchupStrategy())->plan(self::PARAMS)['errors'];
    }

    private function applied(): array
    {
        $s = new MuzhaChenBillingCatchupStrategy();
        $plan = $s->plan(self::PARAMS);
        self::assertTrue($plan['ok'], implode(',', $plan['errors']));

        return [$s, $plan, $s->execute($plan, ['operation_id' => 't'])];
    }

    private function catchups()
    {
        return DB::table('StudentClass')->where('Memo', 'like', '%' . self::REF . '%')->orderBy('StartDate')->get();
    }

    public function test_plan_ok_reports_may_info_and_rejects_wrong_reference(): void
    {
        $s = new MuzhaChenBillingCatchupStrategy();
        $plan = $s->plan(self::PARAMS);
        self::assertTrue($plan['ok'], implode(',', $plan['errors']));
        self::assertSame('before', $plan['state']);
        self::assertSame([1, false], [$plan['info']['may_billable_lessons'], $plan['info']['may_covered']]);
        self::assertSame(['case_parameters_mismatch'], $s->plan([])['errors']);
    }

    public function test_source_contract_and_lesson_drift_have_distinct_codes(): void
    {
        $this->lesson(1249, '2026-06-30', 'completed');
        self::assertSame(['lessons_mismatch'], $this->errors());
        DB::table('ClassSession')->where('SessionDate', '2026-06-30')->delete();
        DB::table('ClassSession')->where('SessionDate', '2026-08-04')->update(['SessionDate' => '2026-08-20']);
        self::assertSame(['august_lesson_after_cutoff'], $this->errors());
        DB::table('ClassSession')->where('SessionDate', '2026-08-20')->update(['SessionDate' => '2026-08-04']);
        DB::table('StudentClass')->where('ID', 1249)->update(['ScheduleMode' => 'weekly', 'ClassType' => 'Tutoring']);
        self::assertEqualsCanonicalizing(['source_contract_not_date_mode', 'source_contract_tutoring'], $this->errors());
        DB::table('StudentClass')->where('ID', 1249)->update(['ScheduleMode' => 'date', 'ClassType' => 'one_on_one']);
        DB::table('Student')->where('id', 164)->update(['CampusID' => 9]);
        self::assertSame(['source_contract_campus'], $this->errors());
    }

    public function test_existing_covering_invoice_blocks_by_item_or_by_billing_period(): void
    {
        $this->course(1250, 164, '2026-06-01', '2026-06-30', ['Stop' => 0]);
        $this->invoice(900, 1250, '2026-06', 4950, 'unpaid');
        DB::table('InvoiceItem')->insert(['InvoiceID' => 900, 'StudentClassID' => 1250, 'Description' => 'x', 'Amount' => 4950,
            'PeriodStart' => '2026-06-01', 'PeriodEnd' => '2026-06-30']);
        self::assertSame(['lesson_already_invoiced_2026-06'], $this->errors());
        DB::table('Invoice')->where('id', 900)->update(['Status' => 'void']); // void invoices do not cover
        self::assertSame([], $this->errors());
        $this->invoice(901, 1249, '2026-07', 4950, 'unpaid'); // item-less: billing_period decides
        self::assertSame(['lesson_already_invoiced_2026-07'], $this->errors());
    }

    public function test_orphan_invoice_drift_and_existing_catchup_block_the_plan(): void
    {
        DB::table('Invoice')->where('id', 1053)->update(['Status' => 'paid', 'PaidAmount' => 6000]);
        self::assertEqualsCanonicalizing(['orphan_invoice_not_unpaid', 'orphan_invoice_paid_amount'], $this->errors());
        DB::table('Invoice')->where('id', 1053)->update(['Status' => 'unpaid', 'PaidAmount' => 0]);
        $this->pay(1053);
        self::assertSame(['orphan_invoice_has_payments'], $this->errors());
        DB::table('Payment')->delete();
        DB::table('payment_reports')->insert(['StudentID' => 162, 'StudentClassID' => 2564, 'reported_by_name' => 'x', 'payment_date' => '2026-10-01',
            'payment_method' => 'cash', 'reported_amount' => 1, 'status' => 'rejected', 'report_token_hash' => 'h',
            'token_expires_at' => now()->addDay(), 'created_at' => now(), 'updated_at' => now()]);
        self::assertSame(['orphan_invoice_has_reports'], $this->errors());
        DB::table('payment_reports')->delete();
        $this->lesson(2564, '2026-07-01', 'scheduled');
        self::assertSame(['orphan_sessions_exist'], $this->errors());
        DB::table('ClassSession')->where('StudentClassID', 2564)->delete();
        $this->course(2564, 162, '2026-07-01', '2026-07-31');
        self::assertSame(['orphan_contract_exists'], $this->errors());
        DB::table('StudentClass')->where('ID', 2564)->delete();
        $this->course(3000, 164, '2026-06-01', '2026-06-30', ['Memo' => self::REF]);
        self::assertSame(['catchup_exists'], $this->errors());
    }

    public function test_execute_creates_three_contracts_invoices_items_and_voids_1053(): void
    {
        $this->travelTo(now()->setDate(2026, 10, 6)->setTime(12, 0));
        [$s, $plan, $result] = $this->applied();
        $contracts = $this->catchups();
        self::assertCount(3, $contracts);
        self::assertSame([164, 66, 29, 1, 'settled_pending', 0, 'date'], [(int) $contracts[0]->StudentID, (int) $contracts[0]->SubjectID,
            (int) $contracts[0]->TeacherID, (int) $contracts[0]->Stop, $contracts[0]->closed_reason, (int) $contracts[0]->SessionCount, $contracts[0]->ScheduleMode]);
        self::assertSame([4950, 4950, 1650], $contracts->pluck('Charge')->map(fn ($v) => (int) $v)->all());
        self::assertSame(['2026-06-01', '2026-07-01', '2026-08-01'], $contracts->pluck('StartDate')->map(fn ($d) => substr((string) $d, 0, 10))->all());
        self::assertSame(['2026-06-30', '2026-07-31', '2026-08-31'], $contracts->pluck('EndDate')->map(fn ($d) => substr((string) $d, 0, 10))->all());
        $ids = $contracts->pluck('ID')->all();
        $inv = DB::table('Invoice')->whereIn('StudentClassID', $ids)->orderBy('billing_period')->get();
        self::assertSame(['2026-06' => 4950, '2026-07' => 4950, '2026-08' => 1650], $inv->pluck('TotalAmount', 'billing_period')->map(fn ($v) => (int) $v)->all());
        self::assertSame([['unpaid'], [0], [self::REF], ['2026-10-06']], [$inv->pluck('Status')->unique()->all(),
            $inv->pluck('PaidAmount')->map(fn ($v) => (int) $v)->unique()->all(), $inv->pluck('Note')->unique()->all(),
            $inv->pluck('IssueDate')->map(fn ($d) => substr((string) $d, 0, 10))->unique()->all()]);
        $items = DB::table('InvoiceItem')->whereIn('InvoiceID', $inv->pluck('id'))->orderBy('PeriodStart')->get();
        self::assertSame([4950, 4950, 1650], $items->pluck('Amount')->map(fn ($v) => (int) $v)->all());
        self::assertEqualsCanonicalizing($ids, $items->pluck('StudentClassID')->map(fn ($v) => (int) $v)->all());
        self::assertSame(['void', self::REF], [DB::table('Invoice')->where('id', 1053)->value('Status'), DB::table('Invoice')->where('id', 1053)->value('Note')]);
        self::assertSame([1, 9, 'paid'], [(int) DB::table('StudentClass')->where('ID', 1249)->value('Paid'),
            DB::table('ClassSession')->where('StudentClassID', 1249)->count(), DB::table('Invoice')->where('id', 422)->value('Status')]);
        $audit = DB::table('security_audit_events')->where('event_type', 'pop.muzha_chen_billing_catchup')->first();
        self::assertNotNull($audit);
        self::assertSame(['row_count' => 3, 'outstanding_amount' => 11550], array_intersect_key(json_decode($audit->metadata, true), ['row_count' => 1, 'outstanding_amount' => 1]));
        self::assertNotNull($audit->subject_ref);
        self::assertTrue($s->verify($plan, $result)['ok']);
    }

    public function test_each_catchup_contract_bills_its_own_amount_for_the_director_flow(): void
    {
        $this->applied();
        $svc = app(MonthlyBillingService::class);
        foreach ($this->catchups() as $c) {
            $month = substr((string) $c->StartDate, 0, 7);
            $course = StudentClass::query()->findOrFail($c->ID);
            self::assertSame((int) $c->Charge, $svc->summarizePeriod($course, $month)['charge']);
        }
    }

    public function test_idempotent_after_state_rebuilds_snapshot_and_verifies(): void
    {
        [$s, , $first] = $this->applied();
        $plan = $s->plan(self::PARAMS);
        self::assertSame(['after', true], [$plan['state'], $plan['ok']]);
        $again = $s->execute($plan, []);
        self::assertTrue($again['already_applied']);
        self::assertSame($first['snapshot'], $again['snapshot']);
        self::assertSame([3, 3], [$this->catchups()->count(), DB::table('Invoice')->where('Note', self::REF)->where('Status', 'unpaid')->count()]);
        self::assertTrue($s->verify($plan, $again)['ok']);
        DB::table('Invoice')->where('id', 1053)->update(['Status' => 'unpaid']);
        self::assertContains('orphan_invoice_not_void', $s->verify($plan, $again)['errors']);
    }

    public function test_rollback_removes_untouched_rows_and_restores_1053(): void
    {
        [$s, , $result] = $this->applied();
        $out = $s->rollback($result['snapshot'], []);
        self::assertSame([true, 6], [$out['ok'], $out['deleted']]);
        self::assertSame([0, 0], [$this->catchups()->count(), DB::table('Invoice')->where('Note', self::REF)->count()]);
        self::assertSame(0, DB::table('InvoiceItem')->where('Description', 'like', '%補收%')->count());
        self::assertSame(['unpaid', ''], [DB::table('Invoice')->where('id', 1053)->value('Status'), DB::table('Invoice')->where('id', 1053)->value('Note')]);
        self::assertTrue($s->plan(self::PARAMS)['ok']);
        self::assertTrue($s->rollback($result['snapshot'], [])['ok']); // repeat is a no-op
    }

    public function test_rollback_skips_anything_changed_since_and_reports_partial(): void
    {
        [$s, , $result] = $this->applied();
        $rows = $result['snapshot']['rows'];
        $this->pay($rows['2026-06']['invoice_id']);                                                  // payment added
        DB::table('InvoiceItem')->where('id', $rows['2026-07']['item_id'])->update(['Amount' => 1]);   // item edited
        $this->pay(1053);                                                                           // 1053 got a payment
        $out = $s->rollback($result['snapshot'], []);
        self::assertSame([false, true, ['month_2026-06', 'month_2026-07', 'invoice_1053'], 2], [$out['ok'], $out['partial'], $out['skipped_ids'], $out['deleted']]);
        self::assertSame([2, 'void'], [$this->catchups()->count(), DB::table('Invoice')->where('id', 1053)->value('Status')]);
    }
}
