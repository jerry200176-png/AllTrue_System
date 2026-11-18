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

    private function storedStatus(int $id): string
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
        self::assertSame('scheduled', $this->storedStatus(9201));
        self::assertSame(['case_parameters_mismatch'], $s->plan([])['errors']);
    }

    public function test_execute_cancels_exactly_the_manifest_and_leaves_billing_untouched(): void
    {
        $s = new PastScheduledSessionsCancelStrategy();
        $plan = $s->plan(self::PARAMS);
        $result = $s->execute($plan, ['operation_id' => 't']);
        self::assertSame(2, $result['updated']);
        self::assertSame(['cancelled', 'cancelled', 'scheduled'], [$this->storedStatus(9201), $this->storedStatus(9202), $this->storedStatus(9203)]);
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
        self::assertSame(['scheduled', 'cancelled'], [$this->storedStatus(9201), $this->storedStatus(9202)]);
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
        self::assertSame('scheduled', $this->storedStatus(9201));
    }

    private const PARAMS_3380 = ['decision_reference' => 'cancel-past-scheduled-course3380-20261118'];

    public function test_course_3380_manifest_is_exact_and_2942_is_unchanged(): void
    {
        PastScheduledSessionsCancelStrategy::useCasesForTesting(null);
        $c = PastScheduledSessionsCancelStrategy::cases('cancel-past-scheduled-course3380-20261118');
        self::assertSame(range(32305, 32311), array_keys($c));
        self::assertSame([3380], array_values(array_unique(array_column($c, 'course_id'))));
        self::assertSame([16], array_values(array_unique(array_column($c, 'campus_id'))));
        self::assertSame([27142, 27143, 27144], array_keys(PastScheduledSessionsCancelStrategy::cases()));
    }

    public function test_course_3380_reference_plans_executes_verifies_and_rolls_back(): void
    {
        $s = new PastScheduledSessionsCancelStrategy();
        $plan = $s->plan(self::PARAMS_3380);
        self::assertTrue($plan['ok'], implode(',', $plan['errors']));
        self::assertSame('cancel-past-scheduled-course3380-20261118', $plan['reference']);
        $result = $s->execute($plan, ['operation_id' => 't']);
        self::assertSame(2, $result['updated']);
        self::assertSame('cancel-past-scheduled-course3380-20261118', $result['snapshot']['reference']);
        self::assertTrue($s->verify($plan, $result)['ok']);
        self::assertSame(2, (new PastScheduledSessionsCancelStrategy())->rollback($result['snapshot'], [])['restored']);
        self::assertSame(['scheduled', 'scheduled', 'scheduled'], [$this->storedStatus(9201), $this->storedStatus(9202), $this->storedStatus(9203)]);
    }

    public function test_unknown_reference_is_rejected_and_attendance_blocks_3380(): void
    {
        $s = new PastScheduledSessionsCancelStrategy();
        self::assertContains('case_parameters_mismatch', $s->plan(['decision_reference' => 'cancel-past-scheduled-course9999-20261118'])['errors']);
        DB::table('StudentSingIn')->insert(['ClassSessionID' => 9201, 'StudentID' => 9200, 'StudentClassID' => 9200, 'TeacherID' => 1, 'Status' => 'present', 'SignInDT' => now()]);
        $plan = $s->plan(self::PARAMS_3380);
        self::assertFalse($plan['ok']);
        self::assertSame(['activity_9201'], $plan['errors']);
    }

    public function test_an_operation_only_drafts_its_own_case(): void
    {
        $service = new \App\Operations\PopOperationService(new \App\Operations\PopOperationCatalog(dirname(base_path()) . '/operations/catalog.yaml'));
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('must equal its operation id');
        $service->createDraft('cancel-past-scheduled-course2942-20261008', ['decision_reference' => 'cancel-past-scheduled-course3380-20261118'],
            'past-cancel-cross-case', 'machine:test', 'super_admin', [1], 1, ['production_sha' => str_repeat('a', 40), 'source' => 'github-actions:test']);
    }
}
