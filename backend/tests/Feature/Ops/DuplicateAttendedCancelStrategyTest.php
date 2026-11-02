<?php

namespace Tests\Feature\Ops;

use App\Models\Student;
use App\Models\User;
use App\Operations\PopOperationCatalog;
use App\Operations\PopOperationService;
use App\Operations\Strategies\DuplicateAttendedCancelStrategy;
use Illuminate\Foundation\Testing\RefreshDatabase;
use App\Services\PackageDeductionService;
use App\Services\SessionDeductionService;
use Illuminate\Support\Facades\DB;
use ReflectionMethod;
use RuntimeException;
use Tests\TestCase;

/** GitHub #4554 / #4753 (Founder decision B): cancel the three double-counted attended lessons, retire the two duplicate packages. */
final class DuplicateAttendedCancelStrategyTest extends TestCase
{
    use RefreshDatabase;

    private const REF = 'cancel-duplicate-attended-20261102';
    private const TRIPLES = [[41783, 2879, 43450, 4264], [41799, 3571, 43852, 4312], [42975, 3226, 43985, 4329]];

    private int $teacherId;

    protected function setUp(): void
    {
        parent::setUp();
        $this->teacherId = (int) User::create(['LoginName' => 'da-teacher@t.com', 'Name' => 'T', 'PSW' => 's', 'type' => 'T', 'phone' => 911000001])->id;
        $date = now()->subDays(10)->toDateString();
        foreach (self::TRIPLES as $i => [$sid, $cid, $tid, $tcid]) {
            $student = $this->student();
            $this->course($cid, $student, 1, $i === 0 ? 700 : null);
            $this->course($tcid, $student, 8, null);
            $this->deducted($cid, $sid, $date, $i === 0 ? 700 : null, true);
            $this->deducted($cid, $sid + 1000000, now()->subDays(30)->toDateString(), $i === 0 ? 700 : null, false); // the lesson that is really owed
            $this->deducted($tcid, $tid, $date, null, false);
        }
        foreach ([[189, 190], [200, 201]] as [$dup, $twin]) {
            $student = $this->student();
            foreach ([$dup, $twin] as $id) {
                DB::table('course_packages')->insert(['id' => $id, 'student_id' => $student, 'campus_id' => 9, 'name' => 'p', 'total_sessions' => 10, 'remaining_sessions' => 9,
                    'used_sessions' => 1, 'created_at' => now(), 'updated_at' => now()]);
                DB::table('package_session_ledger')->insert(['package_id' => $id, 'student_class_id' => 1, 'class_session_id' => null, 'delta' => -1, 'reason' => 'seed',
                    'created_at' => now(), 'updated_at' => now()]);
            }
            $this->course(7000 + $twin, $student, 10, $twin);
        }
    }

    private function student(): int
    {
        return (int) Student::create(['name' => 's' . uniqid(), 'CampusID' => 9, 'ClassID' => 1, 'enable' => 1, 'MDT' => now(), 'Notify_Token' => ''])->id;
    }

    private function course(int $id, int $student, int $total, ?int $package): void
    {
        if ($package === 700 && !DB::table('course_packages')->where('id', 700)->exists()) {
            DB::table('course_packages')->insert(['id' => 700, 'student_id' => $student, 'campus_id' => 9, 'name' => 'p', 'total_sessions' => 2, 'remaining_sessions' => 0,
                'used_sessions' => 2, 'created_at' => now(), 'updated_at' => now()]);
        }
        DB::table('StudentClass')->insert(['ID' => $id, 'StudentID' => $student, 'GradeID' => 1, 'SubjectID' => 66, 'TeacherID' => $this->teacherId, 'by1' => 1, 'Period' => 4,
            'StartDate' => now()->subMonths(2)->toDateString(), 'TotalHours' => 0, 'ScheduleMode' => 'count', 'SessionCount' => $total, 'RemainingSessions' => max(0, $total - 2),
            'UsedSessions' => min($total, 2), 'SessionDuration' => 120, 'ClassType' => 'one_on_one', 'Rate' => 500, 'rate_unit' => 'session', 'Charge' => 0, 'Pay' => 0,
            'Paid' => 0, 'Stop' => 0, 'PackageID' => $package]);
    }

    private function deducted(int $course, int $id, string $date, ?int $package, bool $signIn): void
    {
        DB::table('ClassSession')->insert(['id' => $id, 'StudentClassID' => $course, 'SessionDate' => $date, 'StartTime' => '10:00:00', 'EndTime' => '12:00:00', 'Status' => 'attended',
            'Note' => 'n', 'created_at' => now(), 'updated_at' => now()]);
        DB::table('session_deduction_ledger')->insert(['student_class_id' => $course, 'class_session_id' => $id, 'event_type' => 'deduct', 'source' => 'attendance',
            'created_at' => now(), 'updated_at' => now()]);
        if ($package) {
            DB::table('package_session_ledger')->insert(['package_id' => $package, 'student_class_id' => $course, 'class_session_id' => $id, 'delta' => -1, 'reason' => 'attendance',
                'created_at' => now(), 'updated_at' => now()]);
        }
        if ($signIn) {
            DB::table('StudentSingIn')->insert(['StudentClassID' => $course, 'StudentID' => DB::table('StudentClass')->where('ID', $course)->value('StudentID'), 'ClassSessionID' => $id,
                'Status' => 'attended', 'SignInDT' => $date . ' 10:00:00', 'SignOutDT' => $date . ' 12:00:00', 'SessionDeducted' => 1]);
        }
    }

    private function params(int $campus = 0, string $digest = '', string $restore = ''): array
    {
        return ['campus_id' => $campus, 'decision_reference' => self::REF, 'expected_digest' => $digest, 'restore_request_id' => $restore];
    }

    private function pinned(DuplicateAttendedCancelStrategy $s): array
    {
        return $s->plan($this->params(0, $s->plan($this->params())['digest']));
    }

    private function reasonOf(DuplicateAttendedCancelStrategy $s, string $type, int $id): string
    {
        foreach ($s->plan($this->params())['manifest'] as $r) {
            if ($r['type'] === $type && ($r['session_id'] ?? $r['package_id']) === $id) return $r['class'] . ':' . $r['reason'];
        }

        return 'none';
    }

    public function test_happy_path_cancels_three_and_closes_two_with_the_twins_untouched(): void
    {
        $s = new DuplicateAttendedCancelStrategy();
        $plan = $this->pinned($s);
        $this->assertEquals(['cancel' => 3, 'close' => 2], array_count_values(array_column($plan['manifest'], 'class')));
        $twinBefore = DB::table('ClassSession')->whereIn('id', [43450, 43852, 43985])->orderBy('id')->get()->all();
        $pkgLedgerBefore = DB::table('package_session_ledger')->whereIn('package_id', [189, 200])->count();

        $result = $s->execute($plan, ['operation_id' => 'op-1']);

        $this->assertSame([3, 2], [$result['cancelled'], $result['closed']]);
        foreach ([41783, 41799, 42975] as $id) {
            $this->assertSame('cancelled', DB::table('ClassSession')->where('id', $id)->value('Status'));
            $this->assertSame(0, DB::table('StudentSingIn')->where('ClassSessionID', $id)->whereNull('VoidedAt')->count());
        }
        $this->assertSame(1, DB::table('package_session_ledger')->where('class_session_id', 41783)->where('delta', 1)->count(), 'a package course reverses its package ledger too');
        $this->assertEquals($twinBefore, DB::table('ClassSession')->whereIn('id', [43450, 43852, 43985])->orderBy('id')->get()->all());
        foreach ([189, 200] as $id) {
            $this->assertSame([1, 'duplicate_record'], [(int) DB::table('course_packages')->where('id', $id)->value('stop'), DB::table('course_packages')->where('id', $id)->value('closed_reason')]);
        }
        $this->assertSame($pkgLedgerBefore, DB::table('package_session_ledger')->whereIn('package_id', [189, 200])->count());
        $this->assertSame(0, (int) DB::table('course_packages')->where('id', 190)->value('stop'));
        $this->assertSame(1, DB::table('security_audit_events')->where('event_type', 'pop.duplicate_attended_cancel')->count());
        $this->assertTrue($s->verify($plan, $result)['ok'], json_encode($s->verify($plan, $result)));
        $this->assertSame('after', $s->plan($this->params(0, $plan['digest']))['state']);
    }

    public function test_dry_run_writes_nothing_and_lists_ids_only(): void
    {
        $s = new DuplicateAttendedCancelStrategy();
        $plan = $s->plan($this->params());

        $this->assertSame('unpinned', $plan['state']);
        $this->assertSame(['rows' => 5, 'cancel' => 3, 'close' => 2, 'review' => 0], $plan['totals']);
        $this->assertSame(0, DB::table('ClassSession')->where('Status', 'cancelled')->count());
        $this->assertSame(0, DB::table('course_packages')->where('stop', 1)->count());
        $this->assertSame([], $s->plan($this->params(77))['manifest'], 'another campus lists no rows');
        $this->assertFalse($s->plan(['campus_id' => 0, 'decision_reference' => 'other'])['ok']);
    }

    public function test_each_guard_flips_its_row_to_review(): void
    {
        $s = new DuplicateAttendedCancelStrategy();
        DB::table('ClassSession')->where('id', 43450)->update(['Status' => 'scheduled']);
        $this->assertSame('review:twin_not_attended', $this->reasonOf($s, 'session', 41783));
        DB::table('ClassSession')->where('id', 43852)->delete();
        $this->assertSame('review:twin_missing', $this->reasonOf($s, 'session', 41799));
        DB::table('StudentClass')->where('ID', 3226)->update(['SessionCount' => 5]);
        $this->assertSame('review:course_not_over', $this->reasonOf($s, 'session', 42975));

        DB::table('StudentClass')->insert(['ID' => 9999, 'StudentID' => DB::table('course_packages')->where('id', 189)->value('student_id'), 'GradeID' => 1, 'SubjectID' => 66,
            'TeacherID' => $this->teacherId, 'by1' => 1, 'Period' => 4, 'StartDate' => now()->toDateString(), 'TotalHours' => 0, 'ScheduleMode' => 'count', 'SessionCount' => 1,
            'RemainingSessions' => 1, 'UsedSessions' => 0, 'SessionDuration' => 120, 'ClassType' => 'one_on_one', 'Rate' => 1, 'rate_unit' => 'session', 'Charge' => 0, 'Pay' => 0,
            'Paid' => 0, 'Stop' => 0, 'PackageID' => 189]);
        $this->assertSame('review:still_linked', $this->reasonOf($s, 'package', 189));
        DB::table('course_packages')->where('id', 201)->update(['remaining_sessions' => 3]);
        $this->assertSame('review:twin_mismatch', $this->reasonOf($s, 'package', 200));
        DB::table('course_packages')->where('id', 201)->update(['remaining_sessions' => 9]);
        DB::table('course_packages')->where('id', 200)->update(['stop' => 1]);
        $this->assertSame('review:already_stopped', $this->reasonOf($s, 'package', 200));
        DB::table('StudentClass')->where('PackageID', 201)->update(['PackageID' => null]);
        DB::table('course_packages')->where('id', 200)->update(['stop' => 0]);
        $this->assertSame('review:twin_unlinked', $this->reasonOf($s, 'package', 200));
    }

    public function test_a_projected_monthly_lesson_is_left_for_review(): void
    {
        DB::table('ClassSession')->where('id', 41783)->update(['Note' => 'projected-monthly-materialized']);
        $this->assertSame('review:projected_monthly_session', $this->reasonOf(new DuplicateAttendedCancelStrategy(), 'session', 41783));
    }

    public function test_apply_refuses_after_drift_and_writes_nothing(): void
    {
        $s = new DuplicateAttendedCancelStrategy();
        $plan = $this->pinned($s);
        $this->assertSame('digest_mismatch', $s->plan($this->params(0, str_repeat('a', 64)))['state']);
        DB::table('ClassSession')->where('id', 43985)->update(['Status' => 'cancelled']);

        try {
            $s->execute($plan, []);
            $this->fail('drift must refuse');
        } catch (RuntimeException $e) {
            $this->assertStringContainsString('duplicate_attended_digest_drift', $e->getMessage());
        }
        $this->assertSame(0, DB::table('ClassSession')->where('id', 41783)->where('Status', 'cancelled')->count());
        $this->assertSame(0, DB::table('course_packages')->where('stop', 1)->count());
    }

    public function test_verify_fails_when_state_drifted_after_execute(): void
    {
        $s = new DuplicateAttendedCancelStrategy();
        $plan = $this->pinned($s);
        $result = $s->execute($plan, []);
        DB::table('ClassSession')->where('id', 43450)->update(['Status' => 'cancelled']); // the twin was lost
        DB::table('course_packages')->where('id', 189)->update(['stop' => 0]);

        $verify = $s->verify($plan, $result);

        $this->assertFalse($verify['ok']);
        $this->assertContains('twin_changed_43450', $verify['errors']);
        $this->assertContains('package_not_retired_189', $verify['errors']);
    }

    public function test_rollback_puts_everything_back(): void
    {
        $dump = fn (): array => [DB::table('ClassSession')->orderBy('id')->get(['id', 'StudentClassID', 'Status', 'Note'])->all(), DB::table('session_deduction_ledger')->orderBy('id')->get()->all(),
            DB::table('package_session_ledger')->orderBy('id')->get()->all(), DB::table('StudentSingIn')->orderBy('id')->get(['id', 'VoidedAt', 'VoidReason', 'SessionDeducted'])->all(),
            DB::table('course_packages')->orderBy('id')->get(['id', 'stop', 'closed_reason', 'used_sessions', 'remaining_sessions'])->all(),
            DB::table('StudentClass')->orderBy('ID')->get(['ID', 'UsedSessions', 'RemainingSessions', 'RemainingMinutes', 'PurchasedMinutes', 'Stop'])->all()];
        foreach (DB::table('StudentClass')->pluck('ID') as $id) SessionDeductionService::recomputeCounters((int) $id); // start from ledger-true counters
        $before = $dump();
        $s = new DuplicateAttendedCancelStrategy();
        $result = $s->execute($this->pinned($s), []);
        $this->assertNotEquals($before, $dump());

        $back = $s->rollback($result['snapshot'], []);

        $this->assertSame([5, true], [$back['restored'], $back['ok']]);
        $this->assertEquals($before, $dump());
        $this->assertSame(1, DB::table('security_audit_events')->where('event_type', 'pop.duplicate_attended_cancel.rollback')->count());
    }

    public function test_restore_recomputes_counters_from_the_ledger_as_it_is_now(): void
    {
        $s = new DuplicateAttendedCancelStrategy();
        $result = $s->execute($this->pinned($s), []);
        SessionDeductionService::reverseForSession(2879, 1041783, 'status_adjust'); // staff reverse the course's other lesson after the repair
        DB::table('ClassSession')->where('id', 1041783)->update(['Status' => 'cancelled']);
        $s->rollback($result['snapshot'], []);
        $counters = fn (): array => [DB::table('StudentClass')->where('ID', 2879)->first(['UsedSessions', 'RemainingSessions']),
            DB::table('course_packages')->where('id', 700)->first(['used_sessions', 'remaining_sessions'])];
        $after = $counters();
        SessionDeductionService::recomputeCounters(2879);
        PackageDeductionService::recomputeCounters(700);
        $this->assertEquals($counters(), $after);
        $this->assertSame(1, (int) $after[1]->used_sessions); // the later reversal survives; the snapshot said 2
    }

    public function test_the_catalog_entry_runs_through_pop_and_a_restore_request_undoes_it(): void
    {
        $catalog = new PopOperationCatalog(dirname(__DIR__, 4) . '/operations/catalog.yaml');
        $service = new PopOperationService($catalog);
        $sha = str_repeat('c', 40);
        $go = function (string $restore) use ($service, $sha): array {
            $draft = fn (string $digest): string => (string) $service->createDraft('duplicate-attended-cancel-20261102', $this->params(9, $digest, $restore),
                'da-test-' . bin2hex(random_bytes(4)), 'user:1', 'super_admin', [], 1)['id'];
            $digest = (string) $service->runDryRun($draft(''), 'user:1', 1, 'super_admin', [])['plan']['digest'];
            $id = $draft($digest);
            $service->runDryRun($id, 'user:1', 1, 'super_admin', []);
            $token = $service->approve($id, 'founder-go-duplicate-attended-test-' . substr($id, 0, 8), 'user:2', 'super_admin', $sha, 2, [], 15)['token'];

            return [$id, $service->run($id, 'execute', $token, $sha, 'pop-pi-local')['result'], $service->run($id, 'verify', $token, $sha, 'pop-pi-local')['result']];
        };

        [$cancelId, $execute, $verify] = $go('');
        $this->assertSame(['succeeded', 'succeeded', 'cancelled', 1], [$execute, $verify, DB::table('ClassSession')->where('id', 41783)->value('Status'), (int) DB::table('course_packages')->where('id', 189)->value('stop')]);
        DB::table('ClassSession')->where('id', 41799)->update(['Status' => 'attended']); // one source row moved on: no partial restore
        $refused = (new DuplicateAttendedCancelStrategy())->plan($this->params(9, '', $cancelId));
        $this->assertSame([false, ['restore_incomplete_source_changed']], [$refused['ok'], $refused['errors']]);
        DB::table('ClassSession')->where('id', 41799)->update(['Status' => 'cancelled']);
        [, $execute, $verify] = $go($cancelId);
        $this->assertSame(['succeeded', 'succeeded', 'attended', 0], [$execute, $verify, DB::table('ClassSession')->where('id', 41783)->value('Status'), (int) DB::table('course_packages')->where('id', 189)->value('stop')]);

        [, $execute] = $go('');                     // cancel again (new reversal ids), then try to restore the first run once more
        $stale = (new DuplicateAttendedCancelStrategy())->plan($this->params(9, '', $cancelId));
        $this->assertSame([false, ['restore_incomplete_source_changed']], [$stale['ok'], $stale['errors']]);
        $runbook = (string) file_get_contents(dirname(__DIR__, 4) . '/docs/incidents/RM-DUPLICATE-ATTENDED-CANCEL-20261102.md');
        foreach (['DRY_RUN_DUPLICATE_ATTENDED_RESTORE', 'APPROVE_DUPLICATE_ATTENDED_RESTORE', 'DRY_RUN_DUPLICATE_ATTENDED_CANCEL', 'APPROVE_DUPLICATE_ATTENDED_CANCEL'] as $phrase) {
            $this->assertStringContainsString('`' . $phrase . '`', $runbook);
        }
        $adapter = file_get_contents(dirname(__DIR__, 4) . '/.github/workflows/pop-duplicate-attended-cancel.yml');
        $this->assertStringContainsString('AUDIT="pop.duplicate_attended_cancel${RESTORE:+.rollback}"', $adapter);
        $entry = $catalog->operation('duplicate-attended-cancel-20261102');
        $roles = new ReflectionMethod($service, 'approvalRoles');
        $this->assertSame(['super_admin'], $roles->invoke($service, $entry));
        $entry['blast_radius'] = 'global';
        $this->expectException(RuntimeException::class);
        $roles->invoke($service, $entry);
    }
}
