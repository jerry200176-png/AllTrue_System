<?php

namespace Tests\Feature\Ops;

use App\Operations\Strategies\InvoiceHeaderItemRepairManifest;
use App\Operations\Strategies\InvoiceHeaderItemRepairStrategy;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

final class InvoiceHeaderItemRepairStrategyTest extends TestCase
{
    use RefreshDatabase;

    private const PARAMS = ['decision_reference' => 'repair-invoice-header-item-20261104'];

    protected function setUp(): void
    {
        parent::setUp();
        InvoiceHeaderItemRepairManifest::useCasesForTesting([
            9401 => ['item_id' => 9411, 'course_id' => 9421,
                'before' => ['total' => 0, 'item_amount' => 0, 'item_student_class_id' => null, 'net_paid' => 8000, 'status' => 'paid'],
                'after' => ['total' => 8000, 'item_amount' => 8000, 'item_student_class_id' => 9421]],
            9402 => ['item_id' => 9412, 'course_id' => 9422,
                'before' => ['total' => 4400, 'item_amount' => 4400, 'item_student_class_id' => null, 'net_paid' => 0, 'status' => 'unpaid'],
                'after' => ['total' => 5200, 'item_amount' => 5200, 'item_student_class_id' => 9422]],
        ]);
        DB::table('Student')->insert(['id' => 9431, 'name' => 's', 'CampusID' => 1, 'ClassID' => 1, 'enable' => 1]);
        foreach ([9421, 9422] as $c) {
            DB::table('StudentClass')->insert(['ID' => $c, 'StudentID' => 9431, 'GradeID' => 1, 'SubjectID' => 1, 'TeacherID' => 1,
                'by1' => 1, 'Period' => 4, 'Pay' => 0, 'Rate' => 0, 'TotalHours' => 4, 'StartDate' => '2026-08-01',
                'EndDate' => '2026-08-31', 'ClassType' => 'one_on_one', 'ScheduleMode' => 'date', 'Stop' => 0, 'Paid' => 0]);
        }
        $this->invoice(9401, 9411, 0, 'paid', 8000);
        $this->invoice(9402, 9412, 4400, 'unpaid', 0);
        DB::table('Payment')->insert([
            ['InvoiceID' => 9402, 'Amount' => 4400, 'PaidAt' => now(), 'Method' => 'cash', 'created_at' => now()],
            ['InvoiceID' => 9402, 'Amount' => -4400, 'PaidAt' => now(), 'Method' => 'cash', 'created_at' => now()],
        ]);
    }

    protected function tearDown(): void
    {
        InvoiceHeaderItemRepairManifest::useCasesForTesting(null);
        parent::tearDown();
    }

    private function invoice(int $id, int $itemId, int $total, string $status, int $paid): void
    {
        DB::table('Invoice')->insert(['id' => $id, 'StudentID' => 9431, 'StudentClassID' => null, 'IssueDate' => '2026-09-01',
            'TotalAmount' => $total, 'PaidAmount' => $paid, 'Status' => $status, 'Note' => '', 'created_at' => now(), 'updated_at' => now()]);
        DB::table('InvoiceItem')->insert(['id' => $itemId, 'InvoiceID' => $id, 'StudentClassID' => null, 'Description' => 'm',
            'Amount' => $total, 'created_at' => now(), 'updated_at' => now()]);
        if ($paid > 0) {
            DB::table('Payment')->insert(['InvoiceID' => $id, 'Amount' => $paid, 'PaidAt' => now(), 'Method' => 'cash', 'created_at' => now()]);
        }
    }

    private function rows(): array
    {
        return [
            DB::table('Invoice')->whereIn('id', [9401, 9402])->orderBy('id')->get(['id', 'TotalAmount', 'PaidAmount', 'Status'])->map(fn ($r) => (array) $r)->all(),
            DB::table('InvoiceItem')->whereIn('id', [9411, 9412])->orderBy('id')->get(['id', 'InvoiceID', 'Amount', 'StudentClassID'])->map(fn ($r) => (array) $r)->all(),
            DB::table('Payment')->orderBy('id')->get()->map(fn ($r) => (array) $r)->all(),
        ];
    }

    public function test_before_applies_header_and_item_only_then_second_apply_is_noop(): void
    {
        $s = new InvoiceHeaderItemRepairStrategy();
        $plan = $s->plan(self::PARAMS);
        self::assertTrue($plan['ok'], implode(',', $plan['errors']));
        self::assertSame('before', $plan['state']);
        self::assertSame([8000, 0], [$plan['manifest'][9401]['unchanged']['net_paid'], $plan['manifest'][9402]['unchanged']['net_paid']]);
        [$invBefore, , $payBefore] = $this->rows();

        $result = $s->execute($plan, ['operation_id' => 't']);
        self::assertSame(2, $result['updated']);
        [$inv, $items, $pay] = $this->rows();
        self::assertSame([8000, 5200], array_column($inv, 'TotalAmount'));
        self::assertSame([8000, 5200], array_column($items, 'Amount'));
        self::assertSame([9421, 9422], array_column($items, 'StudentClassID'));
        self::assertSame(array_column($invBefore, 'PaidAmount'), array_column($inv, 'PaidAmount'));
        self::assertSame(array_column($invBefore, 'Status'), array_column($inv, 'Status'));
        self::assertSame($payBefore, $pay);
        self::assertTrue($s->verify($plan, $result)['ok']);
        self::assertSame(1, DB::table('security_audit_events')->where('event_type', 'pop.invoice_header_item_repair')->count());

        $again = $s->plan(self::PARAMS);
        self::assertTrue($again['ok']);
        self::assertSame('after', $again['state']);
        $before = $this->rows();
        self::assertSame(0, $s->execute($again, ['operation_id' => 't2'])['updated']);
        self::assertSame($before, $this->rows());
    }

    public function test_drifted_value_fails_plan_and_apply_writes_nothing(): void
    {
        $s = new InvoiceHeaderItemRepairStrategy();
        $plan = $s->plan(self::PARAMS);
        DB::table('Invoice')->where('id', 9402)->update(['TotalAmount' => 100]);
        $before = $this->rows();

        self::assertContains('invoice_9402_total', $s->plan(self::PARAMS)['errors']);
        try {
            $s->execute($plan, ['operation_id' => 't']);
            self::fail('apply must abort on drift');
        } catch (\RuntimeException $e) {
            self::assertStringContainsString('invoice_9402_total', $e->getMessage());
        }
        self::assertSame($before, $this->rows());
    }

    public function test_item_on_wrong_invoice_fails_with_clear_error(): void
    {
        DB::table('InvoiceItem')->where('id', 9411)->update(['InvoiceID' => 9402]);
        $plan = (new InvoiceHeaderItemRepairStrategy())->plan(self::PARAMS);
        self::assertFalse($plan['ok']);
        self::assertContains('invoice_9401_item_on_other_invoice', $plan['errors']);
    }

    public function test_extra_item_wrong_reference_and_net_paid_drift_fail(): void
    {
        $s = new InvoiceHeaderItemRepairStrategy();
        self::assertSame(['case_parameters_mismatch'], $s->plan([])['errors']);
        DB::table('InvoiceItem')->insert(['InvoiceID' => 9401, 'Description' => 'x', 'Amount' => 1, 'created_at' => now(), 'updated_at' => now()]);
        DB::table('Payment')->insert(['InvoiceID' => 9402, 'Amount' => 100, 'PaidAt' => now(), 'Method' => 'cash', 'created_at' => now()]);
        $errors = $s->plan(self::PARAMS)['errors'];
        self::assertContains('invoice_9401_item_count', $errors);
        self::assertContains('invoice_9402_net_paid', $errors);
    }

    public function test_rollback_restores_before_values(): void
    {
        $s = new InvoiceHeaderItemRepairStrategy();
        $plan = $s->plan(self::PARAMS);
        $original = $this->rows();
        $result = $s->execute($plan, ['operation_id' => 't']);
        self::assertTrue($s->rollback($result['snapshot'], ['operation_id' => 't'])['ok']);
        self::assertSame($original, $this->rows());
    }

    public function test_mixed_state_rollback_restores_only_rows_this_run_changed(): void
    {
        DB::table('Invoice')->where('id', 9401)->update(['TotalAmount' => 8000]);
        DB::table('InvoiceItem')->where('id', 9411)->update(['Amount' => 8000, 'StudentClassID' => 9421]);
        $s = new InvoiceHeaderItemRepairStrategy();
        $plan = $s->plan(self::PARAMS);
        self::assertTrue($plan['ok'], implode(',', $plan['errors']));
        $result = $s->execute($plan, ['operation_id' => 't']);
        self::assertSame(1, $result['updated']);
        self::assertSame([9402], array_keys($result['snapshot']['rows']));

        self::assertTrue($s->rollback($result['snapshot'], ['operation_id' => 't'])['ok']);
        [$inv, $items] = $this->rows();
        self::assertSame([8000, 4400], array_column($inv, 'TotalAmount'));
        self::assertSame([8000, 4400], array_column($items, 'Amount'));
        self::assertSame([9421, null], array_column($items, 'StudentClassID'));
    }

    public function test_already_applied_run_audits_and_its_rollback_is_a_noop(): void
    {
        $s = new InvoiceHeaderItemRepairStrategy();
        $s->execute($s->plan(self::PARAMS), ['operation_id' => 't']);
        $after = $this->rows();
        $again = $s->execute($s->plan(self::PARAMS), ['operation_id' => 't2']);
        self::assertTrue($again['already_applied']);
        self::assertSame(['rows' => []], $again['snapshot']);
        self::assertSame(2, DB::table('security_audit_events')->where('event_type', 'pop.invoice_header_item_repair')->count());

        self::assertSame(0, $s->rollback($again['snapshot'], ['operation_id' => 't2'])['restored']);
        self::assertSame($after, $this->rows());
    }

    public function test_failed_execution_receipt_rolls_back_the_repair(): void
    {
        $pop = new \App\Operations\PopOperationService(new \App\Operations\PopOperationCatalog(dirname(base_path()) . '/operations/catalog.yaml'));
        $id = $pop->createDraft('invoice-header-item-repair-20261104', self::PARAMS, (string) \Illuminate\Support\Str::uuid(), 'synthetic-pop', 'super_admin', [])['id'];
        self::assertTrue($pop->runDryRun($id, 'synthetic-pop', null, 'super_admin', [])['ok']);
        $sha = str_repeat('a', 40);
        $approval = $pop->approve($id, 'founder-go-invoice-header-item-repair-20261104', 'reviewer-founder', 'super_admin', $sha, 901);
        $before = $this->rows();
        $fault = (object) ['armed' => true];
        DB::listen(function ($q) use ($fault): void {
            if ($fault->armed && str_starts_with($q->sql, 'insert ignore into `pop_execution_records`') && in_array('execute', $q->bindings, true)) {
                $fault->armed = false;
                throw new \RuntimeException('synthetic_outer_execution_receipt_failure');
            }
        });
        $failed = $pop->run($id, 'execute', $approval['token'], $sha, 'synthetic-pop');
        self::assertFalse($fault->armed, 'The fault must reach the execution receipt writer');
        self::assertFalse($failed['ok']);
        self::assertSame($before, $this->rows());
    }
}
