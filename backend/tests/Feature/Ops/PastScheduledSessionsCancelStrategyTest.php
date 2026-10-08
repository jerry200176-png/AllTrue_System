<?php

namespace Tests\Feature\Ops;

use App\Operations\Strategies\PastScheduledSessionsCancelStrategy;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

final class PastScheduledSessionsCancelStrategyTest extends TestCase
{
    use RefreshDatabase;

    private const PARAMS = ['decision_reference' => 'cancel-past-scheduled-course2942-20261008'];

    protected function setUp(): void
    {
        parent::setUp();
        PastScheduledSessionsCancelStrategy::useCasesForTesting([
            9201 => ['course_id' => 9200, 'date' => '2026-09-03', 'campus_id' => 1],
            9202 => ['course_id' => 9200, 'date' => '2026-09-10', 'campus_id' => 1],
        ]);
        DB::table('Student')->insert(['id' => 9200, 'name' => 's', 'CampusID' => 1, 'ClassID' => 1, 'enable' => 1]);
        DB::table('StudentClass')->insert(['ID' => 9200, 'StudentID' => 9200, 'GradeID' => 1, 'SubjectID' => 1, 'TeacherID' => 1,
            'by1' => 1, 'Period' => 4, 'Pay' => 0, 'Rate' => 2750, 'TotalHours' => 4, 'StartDate' => '2026-07-30',
            'EndDate' => '2026-09-21', 'ClassType' => 'one_on_one', 'ScheduleMode' => 'count', 'SessionCount' => 3,
            'Stop' => 1, 'Paid' => 1, 'Charge' => 22000, 'closed_reason' => 'contract_amended']);
        $this->addSession(9201, '2026-09-03');
        $this->addSession(9202, '2026-09-10');
        $this->addSession(9203, '2026-09-17'); // not in the manifest: must stay scheduled
    }

    protected function tearDown(): void
    {
        PastScheduledSessionsCancelStrategy::useCasesForTesting(null);
        parent::tearDown();
    }

    private function addSession(int $id, string $date): void
    {
        DB::table('ClassSession')->insert(['id' => $id, 'StudentClassID' => 9200, 'SessionDate' => $date,
            'StartTime' => '10:00:00', 'EndTime' => '12:00:00', 'Status' => 'scheduled', 'created_at' => now(), 'updated_at' => now()]);
    }

    private function status(int $id): string
    {
        return (string) DB::table('ClassSession')->where('id', $id)->value('Status');
    }

    public function test_plan_is_read_only_and_digest_is_stable(): void
    {
        $s = new PastScheduledSessionsCancelStrategy();
        $plan = $s->plan(self::PARAMS);
        self::assertTrue($plan['ok'], implode(',', $plan['errors']));
        self::assertSame('before', $plan['state']);
        self::assertSame(2, $plan['counts']['to_cancel']);
        self::assertSame($plan['digest'], $s->plan(self::PARAMS)['digest']);
        self::assertSame('scheduled', $this->status(9201));
        self::assertSame(['case_parameters_mismatch'], $s->plan([])['errors']);
    }

    public function test_execute_cancels_exactly_the_manifest_and_leaves_billing_untouched(): void
    {
        $s = new PastScheduledSessionsCancelStrategy();
        $plan = $s->plan(self::PARAMS);
        $result = $s->execute($plan, ['operation_id' => 't']);
        self::assertSame(2, $result['updated']);
        self::assertSame(['cancelled', 'cancelled', 'scheduled'], [$this->status(9201), $this->status(9202), $this->status(9203)]);
        $course = DB::table('StudentClass')->where('ID', 9200)->first();
        self::assertSame([22000, 1, 3], [(int) $course->Charge, (int) $course->Paid, (int) $course->SessionCount]);
        self::assertTrue($s->verify($plan, $result)['ok']);
        self::assertSame('after', $s->plan(self::PARAMS)['state']);
        self::assertSame(1, DB::table('security_audit_events')->where('event_type', 'pop.past_scheduled_sessions_cancel')->count());
    }

    public function test_drift_fails_plan_per_row(): void
    {
        $s = new PastScheduledSessionsCancelStrategy();
        DB::table('ClassSession')->where('id', 9201)->update(['SessionDate' => '2026-09-04']);
        DB::table('ClassSession')->where('id', 9202)->update(['Status' => 'attended']);
        $plan = $s->plan(self::PARAMS);
        self::assertFalse($plan['ok']);
        self::assertEqualsCanonicalizing(['identity_9201', 'status_9202'], $plan['errors']);
    }

    public function test_ledger_activity_blocks_the_plan(): void
    {
        DB::table('session_deduction_ledger')->insert(['student_class_id' => 9200, 'class_session_id' => 9202,
            'event_type' => 'deduct', 'source' => 'attendance', 'created_at' => now(), 'updated_at' => now()]);
        self::assertSame(['activity_9202'], (new PastScheduledSessionsCancelStrategy())->plan(self::PARAMS)['errors']);
    }

    public function test_future_dated_session_is_refused(): void
    {
        $future = now()->addDays(3)->toDateString();
        PastScheduledSessionsCancelStrategy::useCasesForTesting([
            9201 => ['course_id' => 9200, 'date' => $future, 'campus_id' => 1],
        ]);
        DB::table('ClassSession')->where('id', 9201)->update(['SessionDate' => $future]);
        self::assertSame(['not_past_9201'], (new PastScheduledSessionsCancelStrategy())->plan(self::PARAMS)['errors']);
    }

    public function test_rollback_restores_and_skips_rows_with_activity(): void
    {
        $s = new PastScheduledSessionsCancelStrategy();
        $result = $s->execute($s->plan(self::PARAMS), []);
        DB::table('session_deduction_ledger')->insert(['student_class_id' => 9200, 'class_session_id' => 9202,
            'event_type' => 'deduct', 'source' => 'attendance', 'created_at' => now(), 'updated_at' => now()]);
        $out = $s->rollback($result['snapshot'], []);
        self::assertSame(1, $out['restored']);
        self::assertSame([9202], $out['skipped_ids']);
        self::assertTrue($out['partial']);
        self::assertSame(['scheduled', 'cancelled'], [$this->status(9201), $this->status(9202)]);
    }

    public function test_retry_after_apply_is_idempotent_and_rollback_snapshot_restores_scheduled(): void
    {
        $s = new PastScheduledSessionsCancelStrategy();
        $s->execute($s->plan(self::PARAMS), []);
        $again = $s->plan(self::PARAMS);
        self::assertSame('after', $again['state']);
        $result = $s->execute($again, []);
        self::assertTrue($result['already_applied']);
        self::assertTrue($s->verify($again, $result)['ok']);
        self::assertSame(2, $s->rollback($result['snapshot'], [])['restored']);
        self::assertSame('scheduled', $this->status(9201));
    }
}
