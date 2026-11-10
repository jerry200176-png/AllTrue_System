<?php

namespace Tests\Feature\Ops;

use App\Operations\Strategies\BillingPeriodRekeyStrategy;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

final class BillingPeriodRekeyStrategyTest extends TestCase
{
    use RefreshDatabase;

    private const REF = 'repair-billing-period-rekey-20261107';

    protected function setUp(): void
    {
        parent::setUp();
        foreach ([[1, 16], [2, 16], [3, 9], [4, 16], [5, 9]] as [$id, $campus]) {
            DB::table('Student')->insert(['id' => $id, 'name' => "s{$id}", 'CampusID' => $campus, 'ClassID' => 1, 'enable' => 1]);
        }
        // 100: cycle 04-29 ~ 05-27, lessons in May, stored 2026-04 (the #514 case) -> 2026-05, paid report follows.
        $this->cycle(100, 1, 1, '2026-04-29', '2026-05-27', '2026-04', ['2026-05-05', '2026-05-12']);
        DB::table('payment_reports')->insert(['id' => 1, 'StudentID' => 1, 'StudentClassID' => 100, 'InvoiceID' => 1, 'billing_period' => '2026-04',
            'reported_by_name' => 'x', 'payment_date' => '2026-05-01', 'reported_amount' => 100, 'report_token_hash' => 'h', 'token_expires_at' => now()]);
        $this->cycle(101, 2, 1, '2026-05-01', '2026-05-31', '2026-05', ['2026-05-04']);        // already right
        $this->cycle(102, 3, 1, '2026-04-29', '2026-05-27', '2026-04', ['2026-05-05']);        // other campus
        $this->cycle(106, 3, 1, '2026-05-01', '2026-05-31', '2026-05', ['2026-05-04']);        // holds May for student 3 -> 102 is refused
        $this->cycle(107, 5, 1, '2026-04-29', '2026-05-27', '2026-04', ['2026-05-05']);        // other campus, ready
        $this->cycle(103, 4, 1, '2026-04-29', '2026-05-27', '2026-04', ['2026-05-05']);        // same student+subject already bills May
        DB::table('StudentClass')->where('ID', 103)->update(['StudentID' => 4]);
        $this->cycle(104, 4, 1, '2026-05-28', '2026-06-26', '2026-05', ['2026-06-02']);
        $this->cycle(105, 2, 2, '2026-04-29', '2026-05-27', '2026-04', []);                    // no lessons: start month, already right
    }

    private function cycle(int $class, int $student, int $subject, string $start, string $end, string $period, array $lessons): void
    {
        DB::table('StudentClass')->insert(['ID' => $class, 'StudentID' => $student, 'GradeID' => 1, 'SubjectID' => $subject, 'TeacherID' => 1, 'by1' => 1, 'Period' => 4,
            'Pay' => 0, 'Rate' => 0, 'TotalHours' => 4, 'StartDate' => $start, 'EndDate' => $end, 'ClassType' => 'one_on_one', 'ScheduleMode' => 'date',
            'Stop' => 0, 'Paid' => 0, 'Charge' => 7500]);
        $id = $class - 99;
        DB::table('Invoice')->insert(['id' => $id, 'StudentID' => $student, 'StudentClassID' => $class, 'IssueDate' => $start, 'TotalAmount' => 7500, 'PaidAmount' => 0,
            'Status' => 'unpaid', 'Note' => '', 'billing_period' => $period, 'created_at' => now(), 'updated_at' => now()]);
        DB::table('InvoiceItem')->insert(['InvoiceID' => $id, 'StudentClassID' => $class, 'Description' => 'x', 'Amount' => 7500, 'PeriodStart' => $start, 'PeriodEnd' => $end]);
        foreach ($lessons as $d) {
            DB::table('ClassSession')->insert(['StudentClassID' => $class, 'SessionDate' => $d, 'StartTime' => '16:00', 'EndTime' => '18:00', 'Status' => 'attended']);
        }
    }

    private function params(array|string $campuses, string $digest = ''): array
    {
        return ['campus_ids' => $campuses, 'decision_reference' => self::REF, 'expected_digest' => $digest];
    }

    public function test_dry_run_lists_every_misfiled_cycle_by_ids_and_refuses_collisions(): void
    {
        $plan = (new BillingPeriodRekeyStrategy)->plan($this->params('all'));
        $this->assertSame('unpinned', $plan['state']);
        $byInvoice = array_column($plan['manifest'], null, 'invoice_id');
        $this->assertSame(['ready', '2026-04', '2026-05'], [$byInvoice[1]['status'], $byInvoice[1]['from'], $byInvoice[1]['to']]);
        $this->assertSame(['skipped', 'period_taken'], [$byInvoice[3]['status'], $byInvoice[3]['reason']], 'never two bills in one period');
        $this->assertSame('ready', $byInvoice[8]['status'], 'other campus is covered by all');
        $this->assertSame('ready', $byInvoice[4]['status'], 'May is freed by the next cycle moving to June in the same run');
        $this->assertSame('2026-06', $byInvoice[5]['to']);
        $this->assertArrayNotHasKey(2, $byInvoice);
        $this->assertArrayNotHasKey(6, $byInvoice);
        $this->assertSame(['9' => 1, '16' => 3], array_map('intval', $plan['totals']['ready_by_campus']) + [], 'counts per campus');
        $one = (new BillingPeriodRekeyStrategy)->plan($this->params([9]));
        $this->assertSame([3, 8], array_column($one['manifest'], 'invoice_id'));
    }

    public function test_execute_rekeys_period_and_reports_keeps_amounts_audits_and_restores(): void
    {
        $s = new BillingPeriodRekeyStrategy;
        $digest = $s->plan($this->params('all'))['digest'];
        $plan = $s->plan($this->params('all', $digest));
        $this->assertSame('pinned', $plan['state']);
        $result = $s->execute($plan, ['operation_id' => 'req-1']);
        $inv = DB::table('Invoice')->where('id', 1)->first();
        $this->assertSame(['2026-05', 7500], [$inv->billing_period, (int) $inv->TotalAmount]);
        $this->assertStringContainsString('[invno_period:2026-04]', $inv->Note);
        $this->assertSame('2026-05', DB::table('payment_reports')->where('id', 1)->value('billing_period'));
        $this->assertSame(4, DB::table('security_audit_events')->where('event_type', 'pop.billing_period_rekey')->count());
        $this->assertTrue($s->verify($plan, $result)['ok']);
        $audit = DB::table('security_audit_events')->where('event_type', 'pop.billing_period_rekey')->orderBy('id')->first();
        $meta = is_string($audit->metadata) ? json_decode($audit->metadata, true) : (array) $audit->metadata;
        $this->assertSame(['req-1', '2026-04', '2026-05'], [$meta['pop_operation_id'], $meta['period_from'], $meta['period_to']]);

        // later note text (a void / correction audit) must survive the restore; a voided bill is left alone
        DB::table('Invoice')->where('id', 1)->update(['Note' => $inv->Note . ' [更正自 #x]']);
        DB::table('Invoice')->where('id', 4)->update(['Status' => 'void']);
        $restore = $s->rollback($result['snapshot'], ['operation_id' => 'req-1']);
        $this->assertSame(['ok' => false, 'restored' => 3, 'skipped' => [4]], ['ok' => $restore['ok'], 'restored' => $restore['restored'], 'skipped' => $restore['skipped_ids']]);
        $this->assertSame('[更正自 #x]', DB::table('Invoice')->where('id', 1)->value('Note'));
        DB::table('Invoice')->where('id', 1)->update(['Note' => '']);
        $restore = ['ok' => true, 'restored' => 4];
        $this->assertSame('2026-04', DB::table('Invoice')->where('id', 1)->value('billing_period'));
        $this->assertSame('2026-05', DB::table('Invoice')->where('id', 4)->value('billing_period'), 'voided bill not restored');
        $this->assertSame('2026-04', DB::table('payment_reports')->where('id', 1)->value('billing_period'));
    }

    public function test_changed_state_or_wrong_digest_never_executes(): void
    {
        $s = new BillingPeriodRekeyStrategy;
        $plan = $s->plan($this->params('all', str_repeat('a', 64)));
        $this->assertFalse($plan['ok']);
        $this->expectException(\RuntimeException::class);
        $s->execute($plan, []);
    }

    public function test_a_long_chain_of_consecutive_cycles_settles_in_one_plan_so_verify_passes(): void
    {
        // seven monthly-ish cycles of one student+subject, each labelled by its start month but taught in the next month
        DB::table('Student')->insert(['id' => 9, 'name' => 's9', 'CampusID' => 9, 'ClassID' => 1, 'enable' => 1]);
        foreach (range(0, 6) as $i) {
            $m = sprintf('2027-%02d', $i + 1);
            $next = sprintf('2027-%02d', $i + 2);
            $this->cycle(200 + $i, 9, 7, "{$m}-28", "{$next}-27", $m, ["{$next}-05"]);
        }
        $s = new BillingPeriodRekeyStrategy;
        $plan = $s->plan($this->params([9]));
        $ready = array_filter($plan['manifest'], fn ($r) => $r['status'] === 'ready' && $r['class_id'] >= 200);
        $this->assertCount(7, $ready, 'every link of the chain moves in one run');
        $pinned = $s->plan($this->params([9], $plan['digest']));
        $result = $s->execute($pinned, ['operation_id' => 'req-2']);
        $this->assertSame([], $s->verify($pinned, $result)['errors']);
    }

    public function test_recovery_path_recognises_the_strategy(): void
    {
        $m = new \ReflectionMethod(\App\Operations\PopOperationService::class, 'atomicFinancialRepair');
        $svc = new \App\Operations\PopOperationService(new \App\Operations\PopOperationCatalog(dirname(__DIR__, 4) . '/operations/catalog.yaml'));
        $this->assertTrue($m->invoke($svc, BillingPeriodRekeyStrategy::class));
    }

    public function test_rerekey_keeps_the_first_tag_and_rollback_refuses_a_month_another_contract_holds(): void
    {
        $s = new BillingPeriodRekeyStrategy;
        $first = $s->plan($this->params('all'));
        $r1 = $s->execute($s->plan($this->params('all', $first['digest'])), ['operation_id' => 'req-3']);
        // invoice 1 (class 100) was April -> May with tag 2026-04; an April lesson appears, so the next run moves it back to April
        DB::table('ClassSession')->insert(['StudentClassID' => 100, 'SessionDate' => '2026-04-30', 'StartTime' => '16:00', 'EndTime' => '18:00', 'Status' => 'scheduled']);
        $plan = $s->plan($this->params([16]));
        $row = collect($plan['manifest'])->firstWhere('invoice_id', 1);
        $this->assertSame(['ready', '2026-05', '2026-04'], [$row['status'], $row['from'], $row['to']]);
        $pinned = $s->plan($this->params([16], $plan['digest']));
        $r2 = $s->execute($pinned, ['operation_id' => 'req-4']);
        $this->assertStringContainsString('[invno_period:2026-04]', DB::table('Invoice')->where('id', 1)->value('Note'));
        $this->assertStringNotContainsString('invno_period:2026-05', DB::table('Invoice')->where('id', 1)->value('Note'));
        $this->assertSame([], $s->verify($pinned, $r2)['errors']);
        // another contract of the same student+subject now holds the original April -> restoring would double up: skipped
        $this->cycle(300, 1, 1, '2026-04-01', '2026-04-28', '2026-05', ['2026-04-07']);
        $back = $s->rollback($r2['snapshot'], ['operation_id' => 'req-4']);
        $this->assertSame([1], $back['skipped_ids'] ?? []);
    }

    public function test_a_partial_rollback_can_be_retried_to_completion(): void
    {
        $s = new BillingPeriodRekeyStrategy;
        $first = $s->plan($this->params('all'));
        $result = $s->execute($s->plan($this->params('all', $first['digest'])), ['operation_id' => 'req-5']);
        $this->cycle(300, 1, 1, '2026-04-01', '2026-04-28', '2026-04', ['2026-04-07']); // holds April for student 1 -> invoice 1 cannot go back
        $one = $s->rollback($result['snapshot'], ['operation_id' => 'req-5']);
        $this->assertSame([1], $one['skipped_ids']);
        DB::table('Invoice')->where('id', 201)->update(['Status' => 'void']);
        $two = $s->rollback($result['snapshot'], ['operation_id' => 'req-5']);
        $this->assertSame([true, []], [$two['ok'], $two['skipped_ids']], 'rows restored by the first pass are recognised, the freed one restores');
        $this->assertSame('2026-04', DB::table('Invoice')->where('id', 1)->value('billing_period'));
    }

    public function test_rollback_also_moves_a_report_registered_after_the_run(): void
    {
        $s = new BillingPeriodRekeyStrategy;
        $first = $s->plan($this->params('all'));
        $result = $s->execute($s->plan($this->params('all', $first['digest'])), ['operation_id' => 'req-6']);
        DB::table('payment_reports')->insert(['id' => 2, 'StudentID' => 1, 'StudentClassID' => 100, 'InvoiceID' => 1, 'billing_period' => '2026-05',
            'reported_by_name' => 'x', 'payment_date' => '2026-05-02', 'reported_amount' => 100, 'report_token_hash' => 'h2', 'token_expires_at' => now()]);
        $s->rollback($result['snapshot'], ['operation_id' => 'req-6']);
        $this->assertSame(['2026-04', '2026-04'], [DB::table('payment_reports')->where('id', 1)->value('billing_period'), DB::table('payment_reports')->where('id', 2)->value('billing_period')]);
    }

    public function test_a_single_item_bill_that_is_not_the_whole_cycle_is_never_a_candidate(): void
    {
        // mid-cycle split: the one item covers only part of the contract (04-29 ~ 05-10 of 04-29 ~ 05-27), lessons in May, stored April
        DB::table('Student')->insert(['id' => 6, 'name' => 's6', 'CampusID' => 16, 'ClassID' => 1, 'enable' => 1]);
        $this->cycle(400, 6, 1, '2026-04-29', '2026-05-27', '2026-04', ['2026-05-05']);
        DB::table('InvoiceItem')->where('InvoiceID', 301)->update(['PeriodEnd' => '2026-05-10']);
        $ids = array_column((new BillingPeriodRekeyStrategy)->plan($this->params('all'))['manifest'], 'invoice_id');
        $this->assertNotContains(301, $ids, 'a bill whose item is not the whole cycle is out of scope, ready or skipped');
    }

    public function test_a_cancelled_first_lesson_does_not_decide_the_month(): void
    {
        // cycle 04-29 ~ 05-27 stored April; the 04-30 lesson is cancelled, the next lesson (05-05) decides: May
        DB::table('Student')->insert(['id' => 7, 'name' => 's7', 'CampusID' => 16, 'ClassID' => 1, 'enable' => 1]);
        $this->cycle(500, 7, 1, '2026-04-29', '2026-05-27', '2026-04', ['2026-05-05']);
        DB::table('ClassSession')->insert(['StudentClassID' => 500, 'SessionDate' => '2026-04-30', 'StartTime' => '16:00', 'EndTime' => '18:00', 'Status' => 'cancelled']);
        $row = collect((new BillingPeriodRekeyStrategy)->plan($this->params([16]))['manifest'])->firstWhere('invoice_id', 401);
        $this->assertSame(['ready', '2026-05'], [$row['status'], $row['to']]);
    }

    public function test_a_voided_session_does_not_decide_the_target_month(): void
    {
        DB::table('Student')->insert(['id' => 8, 'name' => 's8', 'CampusID' => 16, 'ClassID' => 1, 'enable' => 1]);
        $this->cycle(600, 8, 1, '2026-04-29', '2026-05-27', '2026-04', ['2026-05-05']);
        DB::table('ClassSession')->insert(['StudentClassID' => 600, 'SessionDate' => '2026-04-29', 'StartTime' => '16:00', 'EndTime' => '18:00', 'Status' => 'voided']);
        $row = collect((new BillingPeriodRekeyStrategy)->plan($this->params([16]))['manifest'])->firstWhere('invoice_id', 501);
        $this->assertSame(['ready', '2026-05'], [$row['status'], $row['to']]);
    }

    public function test_an_item_owned_by_another_course_is_not_this_contracts_renewal(): void
    {
        DB::table('Student')->insert(['id' => 9, 'name' => 's9', 'CampusID' => 16, 'ClassID' => 1, 'enable' => 1]);
        $this->cycle(700, 9, 1, '2026-04-29', '2026-05-27', '2026-04', ['2026-05-05']);
        $this->cycle(701, 9, 2, '2026-04-29', '2026-05-27', '2026-04', ['2026-04-30']);
        DB::table('InvoiceItem')->where('InvoiceID', 601)->update(['StudentClassID' => 701]); // invoice 601 is anchored on 700, its only item belongs to 701
        $ids = array_column((new BillingPeriodRekeyStrategy)->plan($this->params([16]))['manifest'], 'invoice_id');
        $this->assertNotContains(601, $ids);
    }


    private function executed(callable $mutate): array
    {
        $s = new BillingPeriodRekeyStrategy;
        $dry = $s->plan($this->params('all'));
        $pinned = $s->plan($this->params('all', $dry['digest']));
        $mutate(); // commits after the dry-run, before the bill's lock is taken
        $result = $s->execute($pinned, ['operation_id' => 'req-x']);

        return [$s, $pinned, $result];
    }

    public function test_a_bill_voided_after_the_dry_run_is_skipped_and_counted(): void
    {
        [, , $result] = $this->executed(fn () => DB::table('Invoice')->where('id', 1)->update(['Status' => 'void', 'Note' => '[void: x]']));
        $inv = DB::table('Invoice')->where('id', 1)->first();
        $this->assertSame(['2026-04', '[void: x]'], [$inv->billing_period, $inv->Note], 'the voided bill and its audit text are untouched');
        $this->assertSame('drifted', $result['skipped'][1]);
        $this->assertNotContains(1, array_column($result['snapshot']['rows'], 'invoice_id'));
        $this->assertSame('2026-05', DB::table('Invoice')->where('id', 8)->value('billing_period'), 'other bills still ran');
    }

    public function test_a_lesson_added_after_the_dry_run_skips_that_bill_only(): void
    {
        [$s, $pinned, $result] = $this->executed(fn () => DB::table('ClassSession')->insert(['StudentClassID' => 100, 'SessionDate' => '2026-04-30', 'StartTime' => '16:00', 'EndTime' => '18:00', 'Status' => 'scheduled']));
        $this->assertSame('2026-04', DB::table('Invoice')->where('id', 1)->value('billing_period'), 'no different value is written');
        $this->assertSame('drifted', $result['skipped'][1]);
        $this->assertSame('2026-05', DB::table('Invoice')->where('id', 8)->value('billing_period'));
        $this->assertSame([], $s->verify($pinned, $result)['errors'], 'a skipped bill is reported, not a verification failure');
    }

    public function test_a_lesson_cancelled_after_the_dry_run_skips_the_bill(): void
    {
        // invoice 1 moves April -> May because its only lesson is in May; cancel that lesson and the cycle has none: start month = stored
        [, , $result] = $this->executed(fn () => DB::table('ClassSession')->where('StudentClassID', 100)->update(['Status' => 'cancelled']));
        $this->assertSame('2026-04', DB::table('Invoice')->where('id', 1)->value('billing_period'));
        $this->assertSame('drifted', $result['skipped'][1]);
    }

    public function test_an_item_changed_after_the_dry_run_skips_the_bill(): void
    {
        [, , $result] = $this->executed(fn () => DB::table('InvoiceItem')->where('InvoiceID', 1)->update(['PeriodEnd' => '2026-05-10']));
        $this->assertSame('2026-04', DB::table('Invoice')->where('id', 1)->value('billing_period'));
        $this->assertSame('drifted', $result['skipped'][1]);
    }

    public function test_a_bill_created_after_the_dry_run_blocks_the_target_month(): void
    {
        [, , $result] = $this->executed(function () {
            DB::table('StudentClass')->insert(['ID' => 800, 'StudentID' => 1, 'GradeID' => 1, 'SubjectID' => 1, 'TeacherID' => 1, 'by1' => 1, 'Period' => 4, 'Pay' => 0, 'Rate' => 0,
                'TotalHours' => 4, 'StartDate' => '2026-05-28', 'EndDate' => '2026-05-31', 'ClassType' => 'one_on_one', 'ScheduleMode' => 'date', 'Stop' => 1, 'Paid' => 0, 'Charge' => 1]);
            DB::table('Invoice')->insert(['id' => 900, 'StudentID' => 1, 'StudentClassID' => 800, 'IssueDate' => '2026-05-28', 'TotalAmount' => 1, 'PaidAmount' => 0, 'Status' => 'unpaid',
                'Note' => '', 'billing_period' => '2026-05', 'created_at' => now(), 'updated_at' => now()]);
        });
        $this->assertSame('2026-04', DB::table('Invoice')->where('id', 1)->value('billing_period'));
        $this->assertSame('period_taken', $result['skipped'][1]);
    }

    /** @return list<string> the locking statements taken while $run executes, in order */
    private function lockLog(callable $run): array
    {
        $log = [];
        DB::listen(function ($q) use (&$log) { if (preg_match('/for update|lock in share mode/i', $q->sql)) $log[] = $q->sql; });
        $run();

        return $log;
    }

    private function assertOnlyContractThenInvoiceLocks(array $log): void
    {
        $this->assertNotSame([], $log);
        foreach ($log as $sql) {
            $this->assertDoesNotMatchRegularExpression('/ClassSession|InvoiceItem|lock in share mode/i', $sql, 'lessons and items are plain reads: no lock-order conflict with leave / undo-leave');
        }
        $firstContract = $firstInvoice = null;
        foreach ($log as $i => $sql) {
            if ($firstContract === null && preg_match('/from `StudentClass`/i', $sql)) $firstContract = $i;
            if ($firstInvoice === null && preg_match('/from `Invoice`/i', $sql)) $firstInvoice = $i;
        }
        $this->assertNotNull($firstContract, 'contracts are locked');
        $this->assertLessThan($firstInvoice, $firstContract, 'contract before invoice, the voidInvoice order');
    }

    public function test_execute_takes_only_contract_then_invoice_locks(): void
    {
        $s = new BillingPeriodRekeyStrategy;
        $dry = $s->plan($this->params('all'));
        $pinned = $s->plan($this->params('all', $dry['digest']));
        $this->assertOnlyContractThenInvoiceLocks($this->lockLog(fn () => $s->execute($pinned, ['operation_id' => 'req-lock'])));
    }

    public function test_rollback_takes_only_contract_then_invoice_locks(): void
    {
        $s = new BillingPeriodRekeyStrategy;
        $dry = $s->plan($this->params('all'));
        $result = $s->execute($s->plan($this->params('all', $dry['digest'])), ['operation_id' => 'req-rb']);
        $this->assertOnlyContractThenInvoiceLocks($this->lockLog(fn () => $s->rollback($result['snapshot'], ['operation_id' => 'req-rb'])));
    }

    public function test_a_crash_after_two_rows_is_undoable_from_the_journal_and_resumable(): void
    {
        $crashing = new class extends BillingPeriodRekeyStrategy {
            public int $dieAt = 2;
            protected function beforeRow(int $index, array $row): void { if ($index === $this->dieAt) throw new \LogicException('process died'); }
        };
        $dry = $crashing->plan($this->params('all'));
        $ready = array_values(array_filter($dry['manifest'], fn ($r) => $r['status'] === 'ready'));
        $this->assertGreaterThanOrEqual(3, count($ready));
        $pinned = $crashing->plan($this->params('all', $dry['digest']));
        try {
            $crashing->execute($pinned, ['operation_id' => 'req-crash']);
            $this->fail('expected the simulated crash');
        } catch (\LogicException) {
        }
        $moved = fn () => collect([$ready[0], $ready[1]])->filter(fn ($r) => DB::table('Invoice')->where('id', $r['invoice_id'])->value('billing_period') === $r['to'])->count();
        $this->assertSame(2, $moved(), 'rows 1 and 2 committed before the crash');
        $this->assertSame($ready[2]['from'], DB::table('Invoice')->where('id', $ready[2]['invoice_id'])->value('billing_period'), 'row 3 never ran');

        // (b) re-execute with the same approved digest finishes row 3 (and the rest) only; rows 1-2 are not touched twice
        $s = new BillingPeriodRekeyStrategy;
        $resume = $s->plan($this->params('all', $dry['digest']));
        $this->assertSame('pinned', $resume['state'], 'the approved digest still matches after a partial run');
        $audits = fn () => DB::table('security_audit_events')->where('event_type', 'pop.billing_period_rekey')->count();
        $before = $audits();
        $result = $s->execute($resume, ['operation_id' => 'req-crash']);
        $this->assertSame(count($ready), count($result['snapshot']['rows']), 'journal rows plus the resumed ones');
        $this->assertSame(count($ready) - 2, $audits() - $before, 'only the unfinished rows wrote');
        foreach ($ready as $r) $this->assertSame($r['to'], DB::table('Invoice')->where('id', $r['invoice_id'])->value('billing_period'));
        $this->assertSame([], $s->verify($resume, $result)['errors']);
    }

    public function test_rollback_after_a_crash_restores_exactly_the_committed_rows(): void
    {
        $crashing = new class extends BillingPeriodRekeyStrategy {
            protected function beforeRow(int $index, array $row): void { if ($index === 2) throw new \LogicException('process died'); }
        };
        $dry = $crashing->plan($this->params('all'));
        $ready = array_values(array_filter($dry['manifest'], fn ($r) => $r['status'] === 'ready'));
        try {
            $crashing->execute($crashing->plan($this->params('all', $dry['digest'])), ['operation_id' => 'req-crash2']);
        } catch (\LogicException) {
        }
        // no end-of-run snapshot exists: rollback reads the journal
        $res = (new BillingPeriodRekeyStrategy)->rollback(['rows' => []], ['operation_id' => 'req-crash2']);
        $this->assertSame([true, 2], [$res['ok'], $res['restored']]);
        foreach ($ready as $r) $this->assertSame($r['from'], DB::table('Invoice')->where('id', $r['invoice_id'])->value('billing_period'));
        $again = (new BillingPeriodRekeyStrategy)->rollback(['rows' => []], ['operation_id' => 'req-crash2']);
        $this->assertSame(0, $again['restored'], 'journal entries are marked undone: nothing is restored twice');
    }

    public function test_a_failing_bill_transaction_is_an_error_that_fails_verify_but_leaves_rollback_reachable(): void
    {
        $s = new BillingPeriodRekeyStrategy;
        $dry = $s->plan($this->params('all'));
        $pinned = $s->plan($this->params('all', $dry['digest']));
        // force the journal insert of bill 1 to fail (same request + bill key already taken): its whole transaction rolls back
        $id = (string) \Illuminate\Support\Str::uuid();
        DB::table('pop_execution_records')->insert(['id' => $id, 'execution_id' => $id, 'operation_id' => 'req-err', 'phase' => 'other', 'result' => 'succeeded',
            'idempotency_key' => 'req-err:rekey-row:1', 'attempt_key' => 'req-err:rekey-row:1', 'correlation_id' => $id, 'snapshot' => '{}', 'payload' => '{}', 'actor' => 'x', 'created_at' => now()]);
        $result = $s->execute($pinned, ['operation_id' => 'req-err']);
        $this->assertTrue($result['ok'], 'execute still returns, so rollback stays reachable');
        $this->assertSame('error', $result['skipped'][1]);
        $this->assertSame('2026-04', DB::table('Invoice')->where('id', 1)->value('billing_period'), 'the failed bill wrote nothing');
        $this->assertContains('rekey_error_1', $s->verify($pinned, $result)['errors']);
        $this->assertNotContains(1, array_column($result['snapshot']['rows'], 'invoice_id'));
        $this->assertGreaterThan(0, count($result['snapshot']['rows']), 'the other bills went through');
    }
}
