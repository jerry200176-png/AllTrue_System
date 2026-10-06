<?php

namespace Tests\Feature\Ops;

use App\Operations\Strategies\UnpaidHiddenClosuresManifest;
use App\Operations\Strategies\UnpaidHiddenClosuresStrategy;
use App\Models\PaymentReport;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

final class UnpaidHiddenClosuresStrategyTest extends TestCase
{
    use RefreshDatabase;

    private const PARAMS = ['decision_reference' => 'repair-unpaid-hidden-closures-20261005'];

    protected function setUp(): void
    {
        parent::setUp();
        UnpaidHiddenClosuresManifest::useCasesForTesting([
            9101 => ['closed_reason' => 'settled', 'outstanding' => 3000, 'campus_id' => 1],
            9102 => ['closed_reason' => 'completed', 'outstanding' => 2000, 'campus_id' => 1],
        ]);
        $this->course(9101, 'settled', 3000);
        $this->course(9102, 'completed', 2000);
        // Not in the manifest: must stay untouched.
        $this->course(9103, 'settled', 500);
    }

    protected function tearDown(): void
    {
        UnpaidHiddenClosuresManifest::useCasesForTesting(null);
        parent::tearDown();
    }

    private function course(int $id, string $reason, int $total, array $over = []): void
    {
        DB::table('Student')->insert(['id' => $id, 'name' => "s{$id}", 'CampusID' => 1, 'ClassID' => 1, 'enable' => 1]);
        DB::table('StudentClass')->insert(array_merge([
            'ID' => $id, 'StudentID' => $id, 'GradeID' => 1, 'SubjectID' => 1, 'TeacherID' => 1, 'by1' => 1,
            'Period' => 4, 'Pay' => 0, 'Rate' => 0, 'TotalHours' => 4, 'StartDate' => '2026-08-01', 'EndDate' => '2026-08-31',
            'ClassType' => 'one_on_one', 'ScheduleMode' => 'date',
            'Stop' => 1, 'Paid' => 0, 'closed_reason' => $reason, 'Charge' => $total,
        ], $over));
        DB::table('Invoice')->insert(['id' => $id, 'StudentID' => $id, 'StudentClassID' => $id,
            'IssueDate' => '2026-09-01', 'TotalAmount' => $total, 'PaidAmount' => 0, 'Status' => 'unpaid', 'Note' => '',
            'created_at' => now(), 'updated_at' => now()]);
    }

    private function reason(int $id): ?string
    {
        return DB::table('StudentClass')->where('ID', $id)->value('closed_reason');
    }

    private function pay(int $id, int $amount): void
    {
        DB::table('Payment')->insert(['InvoiceID' => $id, 'Amount' => $amount, 'PaidAt' => now(), 'Method' => 'cash', 'created_at' => now()]);
    }

    private function applied(): array
    {
        $s = new UnpaidHiddenClosuresStrategy();
        $plan = $s->plan(self::PARAMS);
        self::assertTrue($plan['ok'], implode(',', $plan['errors']));

        return [$s, $plan, $s->execute($plan, ['operation_id' => 't'])];
    }

    public function test_plan_ok_on_exact_state_and_rejects_wrong_reference(): void
    {
        $s = new UnpaidHiddenClosuresStrategy();
        $plan = $s->plan(self::PARAMS);
        self::assertTrue($plan['ok'], implode(',', $plan['errors']));
        self::assertSame('before', $plan['state']);
        self::assertSame(['case_parameters_mismatch'], $s->plan([])['errors']);
    }

    public function test_drift_fails_plan_per_row(): void
    {
        $s = new UnpaidHiddenClosuresStrategy();
        DB::table('StudentClass')->where('ID', 9101)->update(['closed_reason' => 'completed']);
        DB::table('StudentClass')->where('ID', 9102)->update(['Paid' => 1]);
        $plan = $s->plan(self::PARAMS);
        self::assertFalse($plan['ok']);
        self::assertEqualsCanonicalizing(['reason_9101', 'paid_9102'], $plan['errors']);

        DB::table('StudentClass')->where('ID', 9101)->update(['closed_reason' => 'settled']);
        DB::table('StudentClass')->where('ID', 9102)->update(['Paid' => 0, 'ClassType' => ' Tutoring ']);
        self::assertSame(['tutoring_9102'], $s->plan(self::PARAMS)['errors']);

        DB::table('StudentClass')->where('ID', 9102)->update(['ClassType' => 'one_on_one']);
        DB::table('Invoice')->where('id', 9102)->update(['TotalAmount' => 2500]);
        self::assertSame(['outstanding_9102'], $s->plan(self::PARAMS)['errors']);
    }

    public function test_execute_flips_exactly_manifest_rows_and_pending_report_blocks_plan(): void
    {
        $report = PaymentReport::create(['StudentID' => 9101, 'StudentClassID' => 9101, 'InvoiceID' => 9101,
            'reported_by_name' => 'x', 'payment_date' => '2026-10-01', 'payment_method' => 'cash',
            'reported_amount' => 3000, 'status' => 'pending', 'report_token_hash' => 'h', 'token_expires_at' => now()->addDay()]);
        self::assertContains('pending_report_9101', (new UnpaidHiddenClosuresStrategy())->plan(self::PARAMS)['errors']);
        $report->update(['status' => 'rejected']);
        [$s, $plan, $result] = $this->applied();
        self::assertSame(2, $result['updated']);
        self::assertSame('settled_pending', $this->reason(9101));
        self::assertSame('settled_pending', $this->reason(9102));
        self::assertSame('settled', $this->reason(9103));
        self::assertSame(3000, (int) DB::table('Invoice')->where('id', 9101)->value('TotalAmount'));
        self::assertSame('after', $s->plan(self::PARAMS)['state']);
        self::assertTrue($s->verify($plan, $result)['ok']);
    }

    public function test_verify_accepts_moved_on_rows_and_fails_when_unpaid_hidden_again(): void
    {
        [$s, $plan, $result] = $this->applied();
        $this->pay(9101, 3000);
        DB::table('StudentClass')->where('ID', 9101)->update(['closed_reason' => 'settled']); // paid in full
        DB::table('StudentClass')->where('ID', 9102)->update(['closed_reason' => 'waived']);
        self::assertTrue($s->verify($plan, $result)['ok']);

        DB::table('StudentClass')->where('ID', 9102)->update(['closed_reason' => 'settled']);
        $v = $s->verify($plan, $result);
        self::assertFalse($v['ok']);
        self::assertSame(['hidden_9102'], $v['errors']);
    }

    public function test_rollback_restores_unchanged_rows_and_skips_row_with_new_payment(): void
    {
        [$s, , $result] = $this->applied();
        $this->pay(9102, 500);
        $out = $s->rollback($result['snapshot'], []);
        self::assertSame(1, $out['restored']);
        self::assertSame([9102], $out['skipped_ids']);
        self::assertFalse($out['ok']); // partly undone: needs operator resolution
        self::assertTrue($out['partial']);
        self::assertSame('settled', $this->reason(9101));
        self::assertSame('settled_pending', $this->reason(9102));
    }
}
