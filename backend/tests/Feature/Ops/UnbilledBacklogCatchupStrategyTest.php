<?php

namespace Tests\Feature\Ops;

use App\Operations\Strategies\UnbilledBacklogCatchupStrategy;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

final class UnbilledBacklogCatchupStrategyTest extends TestCase
{
    use RefreshDatabase;

    private const REF = 'repair-unbilled-backlog-catchup-20261006';

    protected function setUp(): void
    {
        parent::setUp();
        foreach ([[1, 9], [2, 16], [3, 9], [4, 9], [5, 9], [6, 9]] as [$id, $campus]) {
            DB::table('Student')->insert(['id' => $id, 'name' => "s{$id}", 'CampusID' => $campus, 'ClassID' => 1, 'enable' => 1]);
        }
        // 100: Rate 1000, ended 06-15, lessons 06-10 (inside), 06-20, 06-27, 07-04 (outside) -> Jun 16-30 = 2000, Jul = 1000.
        $this->course(100, 1, ['Rate' => 1000]);
        foreach (['2026-06-10', '2026-06-20', '2026-06-27', '2026-07-04'] as $d) $this->lesson(100, $d);
        $this->lesson(100, '2026-07-11', 'cancelled'); // not billable
        $this->course(101, 2, ['Rate' => 800, 'EndDate' => '2026-05-31']); // other campus
        $this->lesson(101, '2026-06-03');
        $this->course(102, 3, ['Rate' => 0]); // no rate
        $this->lesson(102, '2026-06-20');
        $this->course(103, 4, ['Rate' => 500, 'EndDate' => '2026-05-31']); // already billed
        $this->lesson(103, '2026-06-05');
        DB::table('Invoice')->insert(['id' => 1, 'StudentID' => 4, 'StudentClassID' => 103, 'IssueDate' => '2026-06-01', 'TotalAmount' => 500,
            'PaidAmount' => 0, 'Status' => 'unpaid', 'Note' => '', 'billing_period' => '2026-06', 'created_at' => now(), 'updated_at' => now()]);
        DB::table('InvoiceItem')->insert(['InvoiceID' => 1, 'StudentClassID' => 103, 'Description' => 'x', 'Amount' => 500, 'PeriodStart' => '2026-06-01', 'PeriodEnd' => '2026-06-30']);
        foreach ([[104, 'PackageID', 7], [105, 'ClassType', 'Tutoring'], [106, 'closed_reason', 'waived']] as [$id, $col, $v]) {
            $this->course($id, 5, [$col => $v, 'Rate' => 500]);
            $this->lesson($id, '2026-06-20');
        }
        $this->course(107, 6, ['Rate' => 500, 'Stop' => 1, 'closed_reason' => 'settled']); // closed for good
        $this->lesson(107, '2026-06-20');
    }

    private function course(int $id, int $student, array $over = []): void
    {
        DB::table('StudentClass')->insert(array_merge(['ID' => $id, 'StudentID' => $student, 'GradeID' => 1, 'SubjectID' => 1, 'TeacherID' => 1,
            'by1' => 1, 'Period' => 4, 'Pay' => 0, 'Rate' => 0, 'TotalHours' => 4, 'StartDate' => '2026-06-01', 'EndDate' => '2026-06-15',
            'ClassType' => 'one_on_one', 'ScheduleMode' => 'date', 'Stop' => 0, 'Paid' => 0, 'Charge' => 100], $over));
    }

    private function lesson(int $class, string $date, string $status = 'attended'): void
    {
        DB::table('ClassSession')->insert(['StudentClassID' => $class, 'SessionDate' => $date, 'StartTime' => '16:00', 'EndTime' => '18:00', 'Status' => $status]);
    }

    private function params(array $campuses, string $digest = ''): array
    {
        return ['campus_ids' => $campuses, 'decision_reference' => self::REF, 'expected_digest' => $digest];
    }

    private function rows(array $plan): array
    {
        $out = [];
        foreach ($plan['manifest'] as $r) $out[$r['contract_id'] . '|' . $r['month']] = $r;

        return $out;
    }

    /** @return array{0:UnbilledBacklogCatchupStrategy,1:array,2:array} */
    private function applied(array $campuses = [9]): array
    {
        $s = new UnbilledBacklogCatchupStrategy();
        $plan = $s->plan($this->params($campuses, $s->plan($this->params($campuses))['digest']));
        self::assertSame('pinned', $plan['state'], implode(',', $plan['errors']));

        return [$s, $plan, $s->execute($plan, ['operation_id' => 't'])];
    }

    public function test_dry_run_classifies_rows_by_campus_without_writing(): void
    {
        $plan = (new UnbilledBacklogCatchupStrategy())->plan($this->params([9]));
        $rows = $this->rows($plan);
        self::assertSame(['unpinned', true], [$plan['state'], $plan['ok']]);
        self::assertSame(['ready', 2000, 2, '2026-06-16', '2026-06-30'], [$rows['100|2026-06']['status'], $rows['100|2026-06']['amount'],
            $rows['100|2026-06']['lessons'], $rows['100|2026-06']['start'], $rows['100|2026-06']['end']]);
        self::assertSame(['ready', 1000, '2026-07-01', '2026-07-31'], [$rows['100|2026-07']['status'], $rows['100|2026-07']['amount'],
            $rows['100|2026-07']['start'], $rows['100|2026-07']['end']]);
        self::assertSame(['needs_director_amount', null], [$rows['102|2026-06']['status'], $rows['102|2026-06']['amount']]);
        self::assertSame('already_billed', $rows['103|2026-06']['reason']);
        self::assertSame(['package', 'tutoring_or_trial', 'waived', 'contract_closed'],
            [$rows['104|2026-06']['reason'], $rows['105|2026-06']['reason'], $rows['106|2026-06']['reason'], $rows['107|2026-06']['reason']]);
        self::assertArrayNotHasKey('101|2026-06', $rows); // campus 16 filtered out
        self::assertSame([2, 3000], [$plan['totals']['ready_rows'], $plan['totals']['ready_amount']]);
        self::assertSame(0, DB::table('Invoice')->where('Note', self::REF)->count());
        self::assertSame(['campus_ids_required'], (new UnbilledBacklogCatchupStrategy())->plan(['decision_reference' => self::REF])['errors']);
    }

    public function test_execute_needs_the_dry_run_digest(): void
    {
        $s = new UnbilledBacklogCatchupStrategy();
        self::assertSame(['digest_mismatch'], $s->plan($this->params([9], str_repeat('a', 64)))['errors']);
        $this->expectExceptionMessage('unbilled_backlog_plan_not_pinned');
        $s->execute($s->plan($this->params([9])), []);
    }

    public function test_execute_creates_one_contract_invoice_item_per_row_and_is_idempotent(): void
    {
        [$s, $plan, $result] = $this->applied();
        self::assertSame(2, count($result['snapshot']['rows']));
        $contracts = DB::table('StudentClass')->where('Memo', 'like', '%' . self::REF . '%')->orderBy('StartDate')->get();
        self::assertSame([2000, 1000], $contracts->pluck('Charge')->map(fn ($v) => (int) $v)->all());
        self::assertSame(['settled_pending', 1, 0, 'date'], [$contracts[0]->closed_reason, (int) $contracts[0]->Stop, (int) $contracts[0]->SessionCount, $contracts[0]->ScheduleMode]);
        $inv = DB::table('Invoice')->where('Note', self::REF)->orderBy('billing_period')->get();
        self::assertSame([['2026-06', 2000], ['2026-07', 1000]], $inv->map(fn ($i) => [$i->billing_period, (int) $i->TotalAmount])->all());
        self::assertSame(2, DB::table('InvoiceItem')->whereIn('InvoiceID', $inv->pluck('id'))->count());
        self::assertSame([0, 5], [DB::table('ClassSession')->whereIn('StudentClassID', $contracts->pluck('ID'))->count(), DB::table('ClassSession')->where('StudentClassID', 100)->count()]);
        self::assertSame(0, DB::table('Invoice')->where('StudentID', 3)->count()); // needs_director_amount never executed
        self::assertSame(0, DB::table('Invoice')->where('StudentID', 2)->count()); // other campus untouched
        self::assertTrue($s->verify($plan, $result)['ok']);

        $again = $s->plan($this->params([9], $plan['digest'])); // rerun: ready rows are now already_billed
        self::assertSame('after', $again['state']);
        self::assertSame(0, $s->execute($again, [])['created']);
        self::assertSame([2, 2], [DB::table('Invoice')->where('Note', self::REF)->count(), DB::table('StudentClass')->where('Memo', 'like', '%' . self::REF . '%')->count()]);
        self::assertTrue($s->verify($again, $result)['ok']);
        DB::table('Invoice')->where('Note', self::REF)->where('billing_period', '2026-07')->update(['TotalAmount' => 1]);
        self::assertNotEmpty($s->verify($again, $result)['errors']);
    }

    public function test_drift_after_dry_run_blocks_execute_and_campus_filter_scopes_the_run(): void
    {
        $s = new UnbilledBacklogCatchupStrategy();
        $plan = $s->plan($this->params([9]));
        $pinned = $s->plan($this->params([9], $plan['digest']));
        $this->lesson(100, '2026-07-18');
        self::assertSame(['digest_mismatch'], $s->plan($this->params([9], $plan['digest']))['errors']);
        try {
            $s->execute($pinned, []);
            self::fail('drift must block execute');
        } catch (\RuntimeException $e) {
            self::assertSame('unbilled_backlog_digest_drift', $e->getMessage());
        }
        self::assertSame(0, DB::table('Invoice')->where('Note', self::REF)->count());
        [, , $result] = $this->applied([16]);
        self::assertSame([[101, 800]], array_map(fn ($r) => [$r['source_id'], $r['amount']], $result['snapshot']['rows']));
    }

    public function test_rollback_removes_untouched_rows_and_skips_changed_ones(): void
    {
        [$s, , $result] = $this->applied();
        DB::table('Invoice')->where('billing_period', '2026-07')->where('Note', self::REF)->update(['Status' => 'paid', 'PaidAmount' => 1000]);
        $out = $s->rollback($result['snapshot'], []);
        self::assertSame([false, true, 2], [$out['ok'], $out['partial'], $out['deleted']]);
        self::assertSame(1, DB::table('Invoice')->where('Note', self::REF)->count());
    }

    public function test_other_contract_covering_the_month_blocks_double_billing(): void
    {
        // Student 8 (campus 9): contract A ended 06-30 (attended July sessions left on A); contract B, same subject, invoiced July.
        DB::table('Student')->insert(['id' => 8, 'name' => 's8', 'CampusID' => 9, 'ClassID' => 1, 'enable' => 1]);
        $this->course(200, 8, ['Rate' => 1000, 'EndDate' => '2026-06-30']);
        $this->lesson(200, '2026-07-04');
        $this->lesson(200, '2026-07-11');
        $this->course(201, 8, ['Rate' => 1000, 'StartDate' => '2026-07-01', 'EndDate' => '2026-07-31']);
        DB::table('Invoice')->insert(['id' => 2, 'StudentID' => 8, 'StudentClassID' => 201, 'IssueDate' => '2026-07-01', 'TotalAmount' => 1000,
            'PaidAmount' => 0, 'Status' => 'unpaid', 'Note' => '', 'billing_period' => '2026-07', 'created_at' => now(), 'updated_at' => now()]);
        $plan = (new UnbilledBacklogCatchupStrategy())->plan($this->params([9]));
        self::assertSame(['skipped', 'other_contract_covers_month'], [$this->rows($plan)['200|2026-07']['status'], $this->rows($plan)['200|2026-07']['reason']]);
        [, , $result] = $this->applied();
        self::assertNotContains(200, array_column($result['snapshot']['rows'], 'source_id'));
        self::assertSame(0, DB::table('Invoice')->where('StudentID', 8)->where('Note', self::REF)->count());
        // Without B's invoice, a live date-mode contract B covering the date also blocks; a different subject does not.
        DB::table('Invoice')->where('id', 2)->delete();
        self::assertSame('other_contract_covers_month', $this->rows((new UnbilledBacklogCatchupStrategy())->plan($this->params([9])))['200|2026-07']['reason']);
        DB::table('StudentClass')->where('ID', 201)->update(['SubjectID' => 2]);
        self::assertSame('ready', $this->rows((new UnbilledBacklogCatchupStrategy())->plan($this->params([9])))['200|2026-07']['status']);
    }

    public function test_execute_retry_after_completion_rebuilds_the_rollback_snapshot(): void
    {
        [$s, $plan, $result] = $this->applied();
        $again = $s->plan($this->params([9], $plan['digest']));
        $retry = $s->execute($again, []);
        self::assertTrue($retry['already_applied']);
        $strip = fn (array $rows) => collect($rows)->sortBy('contract_id')->values()->all();
        self::assertSame($strip($result['snapshot']['rows']), $strip($retry['snapshot']['rows']));
        // Recognised by Note alone (Memo tag only carries [src:]) too.
        DB::table('StudentClass')->where('Memo', 'like', '%' . self::REF . '%')->update(['Memo' => 'x [src:100]']);
        self::assertSame(2, count($s->execute($s->plan($this->params([9], $plan['digest'])), [])['snapshot']['rows']));
    }
}
