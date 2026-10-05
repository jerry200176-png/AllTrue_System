<?php

namespace Tests\Feature\Ops;

use App\Operations\Strategies\MuzhaChenBillingCatchupStrategy;
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
        DB::table('StudentClass')->insert([
            'ID' => 1249, 'StudentID' => 164, 'GradeID' => 1, 'SubjectID' => 66, 'TeacherID' => 29, 'by1' => 1,
            'Period' => 4, 'Pay' => 0, 'Rate' => 0, 'TotalHours' => 4, 'StartDate' => '2026-04-01', 'EndDate' => '2026-08-14',
            'ClassType' => 'one_on_one', 'ScheduleMode' => 'date', 'Stop' => 1, 'Paid' => 1, 'closed_reason' => 'settled', 'Charge' => 6600,
        ]);
        // April invoice 422 covers April only.
        $this->invoice(422, 1249, '2026-04', 6600, 'paid');
        DB::table('InvoiceItem')->insert(['InvoiceID' => 422, 'StudentClassID' => 1249, 'Description' => 'April', 'Amount' => 6600,
            'PeriodStart' => '2026-04-01', 'PeriodEnd' => '2026-04-30']);
        foreach (self::LESSONS as $d) {
            $this->lesson($d, 'attended');
        }
        $this->lesson('2026-06-23', 'cancelled'); // not billable
        $this->invoice(1053, 2564, '2026-07', 6000, 'unpaid', 162);
    }

    private function lesson(string $date, string $status): void
    {
        DB::table('ClassSession')->insert(['StudentClassID' => 1249, 'SessionDate' => $date, 'StartTime' => '16:00', 'EndTime' => '18:00', 'Status' => $status]);
    }

    private function invoice(int $id, int $classId, string $period, int $total, string $status, int $student = 164): void
    {
        DB::table('Invoice')->insert(['id' => $id, 'StudentID' => $student, 'StudentClassID' => $classId, 'IssueDate' => '2026-07-01',
            'TotalAmount' => $total, 'PaidAmount' => $status === 'paid' ? $total : 0, 'Status' => $status, 'Note' => '', 'billing_period' => $period,
            'created_at' => now(), 'updated_at' => now()]);
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

    private function catchupId(): ?int
    {
        $id = DB::table('StudentClass')->where('Memo', 'like', '%' . self::REF . '%')->value('ID');

        return $id === null ? null : (int) $id;
    }

    public function test_plan_ok_reports_may_info_and_rejects_wrong_reference(): void
    {
        $s = new MuzhaChenBillingCatchupStrategy();
        $plan = $s->plan(self::PARAMS);
        self::assertTrue($plan['ok'], implode(',', $plan['errors']));
        self::assertSame('before', $plan['state']);
        self::assertSame(1, $plan['info']['may_billable_lessons']);
        self::assertFalse($plan['info']['may_covered']);
        self::assertSame(['case_parameters_mismatch'], $s->plan([])['errors']);
    }

    public function test_source_contract_and_lesson_drift_have_distinct_codes(): void
    {
        $this->lesson('2026-06-30', 'completed');
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
        // Item-based coverage on a different contract of the same student.
        DB::table('StudentClass')->insert(['ID' => 1250, 'StudentID' => 164, 'GradeID' => 1, 'SubjectID' => 66, 'TeacherID' => 29, 'by1' => 1,
            'Period' => 4, 'Pay' => 0, 'Rate' => 0, 'TotalHours' => 4, 'StartDate' => '2026-06-01', 'EndDate' => '2026-06-30',
            'ClassType' => 'one_on_one', 'ScheduleMode' => 'date', 'Stop' => 0, 'Paid' => 0, 'Charge' => 100]);
        $this->invoice(900, 1250, '2026-06', 4950, 'unpaid');
        DB::table('InvoiceItem')->insert(['InvoiceID' => 900, 'StudentClassID' => 1250, 'Description' => 'x', 'Amount' => 4950,
            'PeriodStart' => '2026-06-01', 'PeriodEnd' => '2026-06-30']);
        self::assertSame(['lesson_already_invoiced_2026-06'], $this->errors());
        DB::table('Invoice')->where('id', 900)->update(['Status' => 'void']); // void invoices do not cover
        self::assertSame([], $this->errors());
        // Item-less invoice: billing_period equals the lesson month.
        $this->invoice(901, 1249, '2026-07', 4950, 'unpaid');
        self::assertSame(['lesson_already_invoiced_2026-07'], $this->errors());
    }

    public function test_orphan_invoice_drift_and_existing_catchup_block_the_plan(): void
    {
        DB::table('Invoice')->where('id', 1053)->update(['Status' => 'paid', 'PaidAmount' => 6000]);
        self::assertEqualsCanonicalizing(['orphan_invoice_not_unpaid', 'orphan_invoice_paid_amount'], $this->errors());
        DB::table('Invoice')->where('id', 1053)->update(['Status' => 'unpaid', 'PaidAmount' => 0]);
        DB::table('Payment')->insert(['InvoiceID' => 1053, 'Amount' => 100, 'PaidAt' => now(), 'Method' => 'cash', 'created_at' => now()]);
        self::assertSame(['orphan_invoice_has_payments'], $this->errors());
        DB::table('Payment')->where('InvoiceID', 1053)->delete();
        DB::table('payment_reports')->insert(['StudentID' => 162, 'StudentClassID' => 2564, 'InvoiceID' => null, 'reported_by_name' => 'x',
            'payment_date' => '2026-10-01', 'payment_method' => 'cash', 'reported_amount' => 1, 'status' => 'rejected',
            'report_token_hash' => 'h', 'token_expires_at' => now()->addDay(), 'created_at' => now(), 'updated_at' => now()]);
        self::assertSame(['orphan_invoice_has_reports'], $this->errors());
        DB::table('payment_reports')->delete();
        DB::table('StudentClass')->insert(['ID' => 2564, 'StudentID' => 162, 'GradeID' => 1, 'SubjectID' => 1, 'TeacherID' => 1, 'by1' => 1,
            'Period' => 4, 'Pay' => 0, 'Rate' => 0, 'TotalHours' => 4, 'StartDate' => '2026-07-01', 'EndDate' => '2026-07-31',
            'ClassType' => 'one_on_one', 'ScheduleMode' => 'date', 'Stop' => 0, 'Paid' => 0, 'Charge' => 6000]);
        self::assertSame(['orphan_contract_exists'], $this->errors());
        DB::table('StudentClass')->where('ID', 2564)->delete();
        DB::table('StudentClass')->insert(['ID' => 3000, 'StudentID' => 164, 'GradeID' => 1, 'SubjectID' => 66, 'TeacherID' => 29, 'by1' => 1,
            'Period' => 4, 'Pay' => 0, 'Rate' => 0, 'TotalHours' => 4, 'StartDate' => '2026-06-01', 'EndDate' => '2026-06-30',
            'ClassType' => 'one_on_one', 'ScheduleMode' => 'date', 'Stop' => 1, 'Paid' => 0, 'Charge' => 1, 'Memo' => self::REF]);
        self::assertSame(['catchup_exists'], $this->errors());
    }

    public function test_execute_creates_one_contract_three_invoices_three_items_and_voids_1053(): void
    {
        $this->travelTo(now()->setDate(2026, 10, 6)->setTime(12, 0));
        [$s, $plan, $result] = $this->applied();
        $id = $this->catchupId();
        self::assertNotNull($id);
        self::assertSame(1, DB::table('StudentClass')->where('Memo', 'like', '%' . self::REF . '%')->count());
        $c = DB::table('StudentClass')->where('ID', $id)->first();
        self::assertSame([164, 66, 29, 11550, 0, 1, 'settled_pending', 0, 'date', '2026-06-01', '2026-08-31'],
            [(int) $c->StudentID, (int) $c->SubjectID, (int) $c->TeacherID, (int) $c->Charge, (int) $c->Paid, (int) $c->Stop,
                $c->closed_reason, (int) $c->SessionCount, $c->ScheduleMode, substr((string) $c->StartDate, 0, 10), substr((string) $c->EndDate, 0, 10)]);
        $inv = DB::table('Invoice')->where('StudentClassID', $id)->orderBy('billing_period')->get();
        self::assertSame(['2026-06' => 4950, '2026-07' => 4950, '2026-08' => 1650], $inv->pluck('TotalAmount', 'billing_period')->map(fn ($v) => (int) $v)->all());
        self::assertSame(['unpaid'], $inv->pluck('Status')->unique()->all());
        self::assertSame([0], $inv->pluck('PaidAmount')->map(fn ($v) => (int) $v)->unique()->all());
        self::assertSame([self::REF], $inv->pluck('Note')->unique()->all());
        self::assertSame(['2026-10-06'], $inv->pluck('IssueDate')->map(fn ($d) => substr((string) $d, 0, 10))->unique()->all());
        $items = DB::table('InvoiceItem')->whereIn('InvoiceID', $inv->pluck('id'))->orderBy('PeriodStart')->get();
        self::assertCount(3, $items);
        self::assertSame(['2026-06-30', '2026-07-31', '2026-08-31'], $items->pluck('PeriodEnd')->map(fn ($d) => substr((string) $d, 0, 10))->all());
        self::assertSame([$id], $items->pluck('StudentClassID')->map(fn ($v) => (int) $v)->unique()->all());
        $void = DB::table('Invoice')->where('id', 1053)->first();
        self::assertSame(['void', self::REF], [$void->Status, $void->Note]);
        // Untouched: 1249, its sessions, invoice 422.
        self::assertSame(1, (int) DB::table('StudentClass')->where('ID', 1249)->value('Paid'));
        self::assertSame(9, DB::table('ClassSession')->where('StudentClassID', 1249)->count());
        self::assertSame('paid', DB::table('Invoice')->where('id', 422)->value('Status'));
        self::assertSame(1, DB::table('security_audit_events')->where('event_type', 'pop.muzha_chen_billing_catchup')->count());
        self::assertSame(['unpaid', ''], [$result['snapshot']['orphan_invoice']['status'], $result['snapshot']['orphan_invoice']['note']]);
        self::assertTrue($s->verify($plan, $result)['ok']);
    }

    public function test_idempotent_after_state_rebuilds_snapshot_and_verifies(): void
    {
        [$s, , $first] = $this->applied();
        $plan = $s->plan(self::PARAMS);
        self::assertTrue($plan['ok']);
        self::assertSame('after', $plan['state']);
        $again = $s->execute($plan, []);
        self::assertTrue($again['already_applied']);
        self::assertSame($first['snapshot'], $again['snapshot']);
        self::assertSame(1, DB::table('StudentClass')->where('Memo', 'like', '%' . self::REF . '%')->count());
        self::assertSame(3, DB::table('Invoice')->where('Note', self::REF)->where('Status', 'unpaid')->count());
        self::assertTrue($s->verify($plan, $again)['ok']);
        // A broken after-state fails verify.
        DB::table('Invoice')->where('id', 1053)->update(['Status' => 'unpaid']);
        self::assertContains('orphan_invoice_not_void', $s->verify($plan, $again)['errors']);
    }

    public function test_rollback_removes_untouched_rows_and_restores_1053(): void
    {
        [$s, , $result] = $this->applied();
        $out = $s->rollback($result['snapshot'], []);
        self::assertTrue($out['ok']);
        self::assertSame(4, $out['deleted']);
        self::assertNull($this->catchupId());
        self::assertSame(0, DB::table('Invoice')->where('Note', self::REF)->count());
        self::assertSame(0, DB::table('InvoiceItem')->where('Description', 'like', '%補收%')->count());
        $o = DB::table('Invoice')->where('id', 1053)->first();
        self::assertSame(['unpaid', ''], [$o->Status, $o->Note]);
        self::assertTrue($s->plan(self::PARAMS)['ok']);
        self::assertTrue($s->rollback($result['snapshot'], [])['ok']); // repeat is a no-op
    }

    public function test_rollback_skips_touched_rows_and_reports_partial(): void
    {
        [$s, , $result] = $this->applied();
        $touched = $result['snapshot']['invoice_ids'][0];
        DB::table('Payment')->insert(['InvoiceID' => $touched, 'Amount' => 100, 'PaidAt' => now(), 'Method' => 'cash', 'created_at' => now()]);
        DB::table('Payment')->insert(['InvoiceID' => 1053, 'Amount' => 100, 'PaidAt' => now(), 'Method' => 'cash', 'created_at' => now()]);
        $out = $s->rollback($result['snapshot'], []);
        self::assertFalse($out['ok']);
        self::assertTrue($out['partial']);
        self::assertEqualsCanonicalizing(["invoice_{$touched}", 'contract_' . $result['snapshot']['contract_id'], 'invoice_1053'], $out['skipped_ids']);
        self::assertSame(2, $out['deleted']);
        self::assertSame(1, DB::table('Invoice')->where('id', $touched)->count());
        self::assertNotNull($this->catchupId());
        self::assertSame('void', DB::table('Invoice')->where('id', 1053)->value('Status'));
    }
}
