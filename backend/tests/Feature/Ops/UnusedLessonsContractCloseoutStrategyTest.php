<?php

namespace Tests\Feature\Ops;

use App\Operations\Strategies\UnusedLessonsContractCloseoutStrategy;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use RuntimeException;
use Tests\TestCase;

/** GitHub #5617: synthetic amounts (Rate 1500); ids and dates are the manifest's. */
final class UnusedLessonsContractCloseoutStrategyTest extends TestCase
{
    use RefreshDatabase;

    private const REF = ['decision_reference' => 'closeout-unused-lessons-5617-20261125'];
    private const LESSONS = [50637, 50638, 50639, 50640, 50641, 50642];

    protected function setUp(): void
    {
        parent::setUp();
        DB::table('Student')->insert(['id' => 9201, 'name' => 's', 'CampusID' => 17, 'ClassID' => 1, 'enable' => 1]);
        DB::table('StudentClass')->insert(['ID' => 2201, 'StudentID' => 9201, 'GradeID' => 1, 'SubjectID' => 1, 'TeacherID' => 1,
            'by1' => 1, 'Period' => 8, 'Pay' => 0, 'Rate' => 1500, 'TotalHours' => 8, 'StartDate' => '2026-09-01',
            'EndDate' => '2026-11-17', 'ClassType' => 'one_on_one', 'ScheduleMode' => 'count', 'SessionCount' => 8,
            'UsedSessions' => 2, 'RemainingSessions' => 6, 'Stop' => 1, 'Paid' => 0, 'Charge' => 12000]);
        foreach ([17806 => '2026-09-01', 17815 => '2026-09-08'] as $id => $date) { // the two taught lessons
            $this->lesson($id, $date, 'attended');
            DB::table('session_deduction_ledger')->insert(['student_class_id' => 2201, 'class_session_id' => $id, 'event_type' => 'deduct',
                'source' => 'attendance', 'created_at' => now(), 'updated_at' => now()]);
        }
        foreach (UnusedLessonsContractCloseoutStrategy::MANIFESTS[self::REF['decision_reference']]['lessons'] as $id => $date) {
            $this->lesson($id, $date, 'scheduled');
        }
        DB::table('Invoice')->insert(['id' => 2821, 'StudentID' => 9201, 'StudentClassID' => 2201, 'IssueDate' => '2026-09-01',
            'TotalAmount' => 12000, 'PaidAmount' => 3000, 'Status' => 'partial', 'Note' => '',
            'created_at' => '2026-09-01 10:00:00', 'updated_at' => '2026-09-05 10:00:00']);
        DB::table('Payment')->insert(['InvoiceID' => 2821, 'Amount' => 3000, 'PaidAt' => '2026-09-05 10:00:00', 'Method' => 'cash', 'created_at' => now()]);
        DB::table('payment_reports')->insert(['StudentID' => 9201, 'StudentClassID' => 2201, 'InvoiceID' => 2821, 'reported_by_name' => 'p',
            'payment_date' => '2026-09-05', 'payment_method' => 'cash', 'reported_amount' => 3000, 'status' => 'confirmed',
            'report_token_hash' => str_repeat('c', 64), 'token_expires_at' => now()->addDay(), 'created_at' => now(), 'updated_at' => now()]);
    }

    private function lesson(int $id, string $date, string $status): void
    {
        DB::table('ClassSession')->insert(['id' => $id, 'StudentClassID' => 2201, 'SessionDate' => $date, 'StartTime' => '18:00:00',
            'EndTime' => '19:00:00', 'Status' => $status, 'created_at' => now(), 'updated_at' => now()]);
    }

    /** Every row this operation may touch or must leave alone. */
    private function rows(): string
    {
        return json_encode([DB::table('StudentClass')->get(), DB::table('Invoice')->get(), DB::table('ClassSession')->orderBy('id')->get(),
            DB::table('Payment')->get(), DB::table('payment_reports')->get(), DB::table('session_deduction_ledger')->get()]);
    }

    private function untouched(): string
    {
        return json_encode([DB::table('Payment')->get(), DB::table('payment_reports')->get(), DB::table('session_deduction_ledger')->get(),
            DB::table('ClassSession')->whereNotIn('id', self::LESSONS)->orderBy('id')->get(),
            DB::table('Invoice')->select('PaidAmount', 'StudentID', 'StudentClassID')->get()]);
    }

    public function test_closeout_cancels_the_unused_lessons_closes_the_contract_and_settles_the_bill(): void
    {
        $s = new UnusedLessonsContractCloseoutStrategy();
        $before = $this->untouched();
        $plan = $s->plan(self::REF);
        self::assertTrue($plan['ok'], implode(',', $plan['errors']));
        self::assertSame('before', $plan['state']);
        self::assertSame(['lessons' => 6, 'from_count' => 8, 'to_count' => 2, 'ledger_used' => 2, 'other_scheduled' => 0,
            'live_invoices' => 1, 'to_cancel' => 6], $plan['counts']);

        $result = $s->execute($plan, ['operation_id' => 'op-5617']);
        self::assertSame(6, $result['updated']);
        self::assertSame(array_fill_keys(self::LESSONS, 'cancelled'),
            DB::table('ClassSession')->whereIn('id', self::LESSONS)->orderBy('id')->pluck('Status', 'id')->all());
        $c = DB::table('StudentClass')->where('ID', 2201)->first();
        self::assertSame([2, 0, 3000, 'contract_amended', 1, 1, 2, '2026-11-17', '2026-09-05'],
            [(int) $c->SessionCount, (int) $c->RemainingSessions, (int) $c->Charge, $c->closed_reason, (int) $c->Paid, (int) $c->Stop,
                (int) $c->UsedSessions, substr((string) $c->EndDate, 0, 10), substr((string) $c->PayDate, 0, 10)]);
        $i = DB::table('Invoice')->where('id', 2821)->first();
        self::assertSame([3000, 3000, 'paid'], [(int) $i->TotalAmount, (int) $i->PaidAmount, $i->Status]);
        self::assertSame($before, $this->untouched(), 'taught lessons, payments, reports and ledger are not written');
        self::assertSame(1, DB::table('security_audit_events')->where('event_type', 'pop.unused_lessons_contract_closeout')->count());
        $v = $s->verify($s->plan(self::REF), $result);
        self::assertTrue($v['ok'], implode(',', $v['errors']));

        $again = $s->plan(self::REF);
        self::assertSame(['after', true], [$again['state'], $again['ok']]);
        self::assertTrue($s->execute($again, [])['already_applied']);
    }

    public function test_rollback_restores_the_exact_before_rows(): void
    {
        $s = new UnusedLessonsContractCloseoutStrategy();
        $before = $this->rows();
        $result = $s->execute($s->plan(self::REF), ['operation_id' => 'op-5617']);
        $back = $s->rollback($result['snapshot'], ['operation_id' => 'op-5617']);
        self::assertSame(['ok' => true, 'partial' => false, 'restored' => 6, 'skipped_ids' => []], $back);
        $normalize = fn (string $json): string => preg_replace('/"updated_at":"[^"]*"/', '', $json); // lesson updated_at moves
        self::assertSame($normalize($before), $normalize($this->rows()));
        self::assertSame('2026-09-05 10:00:00', (string) DB::table('Invoice')->where('id', 2821)->value('updated_at'));
        self::assertSame(1, DB::table('security_audit_events')->where('event_type', 'pop.unused_lessons_contract_closeout.rollback')->count());
        self::assertSame('before', $s->plan(self::REF)['state']);
        self::assertFalse($s->rollback($result['snapshot'], [])['ok'], 'a second rollback finds nothing to restore');
        $this->expectException(RuntimeException::class);
        $s->rollback(['reference' => self::REF['decision_reference'], 'lessons' => [50637 => 'scheduled']] + $result['snapshot'], []);
    }

    /** @dataProvider drifts */
    public function test_any_drift_refuses_and_writes_nothing(string $code, callable $arrange): void
    {
        $arrange();
        $rows = $this->rows();
        $s = new UnusedLessonsContractCloseoutStrategy();
        $plan = $s->plan(self::REF);
        self::assertFalse($plan['ok']);
        self::assertContains($code, $plan['errors']);
        self::assertSame([], $plan['snapshot']);
        try {
            $s->execute($plan, []);
            self::fail('expected abort');
        } catch (RuntimeException) {
            self::assertSame($rows, $this->rows());
        }
        // Even a plan forged as ready re-checks under lock and writes nothing.
        try {
            $s->execute(['ok' => true, 'state' => 'before', 'reference' => self::REF['decision_reference']], []);
            self::fail('expected abort under lock');
        } catch (RuntimeException) {
            self::assertSame($rows, $this->rows());
        }
    }

    public static function drifts(): array
    {
        return [
            'lesson has a sign-in' => ['lesson_50639_activity', fn () => DB::table('StudentSingIn')->insert(['StudentClassID' => 2201,
                'ClassSessionID' => 50639, 'StudentID' => 9201, 'TeacherID' => 1, 'Status' => 'present', 'SignInDT' => '2026-10-27 18:00:00'])],
            'another scheduled lesson' => ['other_scheduled_lessons', fn () => DB::table('ClassSession')->insert(['id' => 50700,
                'StudentClassID' => 2201, 'SessionDate' => '2026-11-24', 'StartTime' => '18:00:00', 'EndTime' => '19:00:00',
                'Status' => 'scheduled', 'created_at' => now(), 'updated_at' => now()])],
            'invoice not partial' => ['invoice_not_partial', fn () => DB::table('Invoice')->where('id', 2821)->update(['Status' => 'unpaid'])],
            'paid amount is not the new charge' => ['invoice_paid_amount', fn () => DB::table('Invoice')->where('id', 2821)->update(['PaidAmount' => 4500])],
            'course not stopped' => ['not_stopped', fn () => DB::table('StudentClass')->where('ID', 2201)->update(['Stop' => 0])],
        ];
    }
}
