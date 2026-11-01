<?php

namespace Tests\Feature\Ops;

use App\Models\ClassSession;
use App\Models\Student;
use App\Models\StudentClass;
use App\Models\StudentSignIn;
use App\Models\User;
use App\Operations\PopOperationCatalog;
use App\Operations\PopOperationService;
use App\Operations\Strategies\ForwardExtraCancelStrategy;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use ReflectionMethod;
use RuntimeException;
use Tests\TestCase;

/** GitHub #4758 (in-app #452): cancel the forward generator's scheduled extras on fully used courses; list the doubtful ones. */
final class ForwardExtraCancelStrategyTest extends TestCase
{
    use RefreshDatabase;

    private const REF = 'cancel-forward-extra-sessions-20261101';

    private int $teacherId;

    protected function setUp(): void
    {
        parent::setUp();
        $this->teacherId = (int) User::create(['LoginName' => 'fx-teacher@t.com', 'Name' => 'T', 'PSW' => 's', 'type' => 'T', 'phone' => 911000000])->id;
    }

    private function params(int $campus = 0, string $digest = '', string $restore = ''): array
    {
        return ['campus_id' => $campus, 'decision_reference' => self::REF, 'expected_digest' => $digest, 'restore_request_id' => $restore];
    }

    private function student(int $campus): int
    {
        return (int) Student::create(['name' => 's' . uniqid(), 'CampusID' => $campus, 'ClassID' => 1, 'enable' => 1, 'MDT' => now(), 'Notify_Token' => ''])->id;
    }

    private function course(int $student, int $total): StudentClass
    {
        return StudentClass::create(['StudentID' => $student, 'GradeID' => 1, 'SubjectID' => 66, 'TeacherID' => $this->teacherId, 'by1' => 1, 'Period' => 4,
            'StartDate' => now()->subMonths(2)->toDateString(), 'TotalHours' => 0, 'ScheduleMode' => 'count', 'SessionCount' => $total, 'RemainingSessions' => 0,
            'UsedSessions' => $total, 'SessionDuration' => 120, 'ClassType' => 'one_on_one', 'Rate' => 500, 'rate_unit' => 'session', 'Charge' => 0, 'Pay' => 0,
            'Paid' => 0, 'Stop' => 0]);
    }

    private function lesson(StudentClass $course, string $date, string $status = 'attended', string $note = '', string $start = '10:00:00'): int
    {
        return (int) DB::table('ClassSession')->insertGetId(['StudentClassID' => $course->ID, 'SessionDate' => $date, 'StartTime' => $start,
            'EndTime' => '12:00:00', 'Status' => $status, 'Note' => $note, 'created_at' => now(), 'updated_at' => now()]);
    }

    /** A course that bought $total lessons and used them all, then got a generated scheduled extra on $date. @return array{0: StudentClass, 1: int} */
    private function fullCourse(int $student, string $date, int $total = 1, ?int $used = null): array
    {
        $course = $this->course($student, $total);
        for ($i = 1; $i <= ($used ?? $total); $i++) {
            DB::table('session_deduction_ledger')->insert(['student_class_id' => $course->ID, 'event_type' => 'deduct', 'source' => 'attendance',
                'class_session_id' => $this->lesson($course, now()->subDays(20 + $i)->toDateString()), 'created_at' => now(), 'updated_at' => now()]);
        }

        return [$course, $this->lesson($course, $date, 'scheduled', '系統向前生成 #1062')];
    }

    /** Diagnostics: the strategy's own verify errors for a request (the service keeps only checks + passed). */
    private function why(string $id, string $restore): string
    {
        $execute = DB::table('pop_execution_records')->where('operation_id', $id)->where('phase', 'execute')->orderByDesc('attempt_no')->first();
        $payload = $execute ? json_decode((string) $execute->payload, true) : [];
        $strategy = new ForwardExtraCancelStrategy();
        $plan = $strategy->plan($this->params(26, (string) (json_decode((string) DB::table('pop_operation_requests')->where('id', $id)->value('parameters'), true)['expected_digest'] ?? ''), $restore));

        return json_encode(['verify' => $strategy->verify($plan, $payload), 'plan_state' => $plan['state'] ?? null, 'rows' => $plan['manifest'] ?? null]);
    }

    private function st(int $id): string
    {
        return (string) DB::table('ClassSession')->where('id', $id)->value('Status');
    }

    private function pinned(ForwardExtraCancelStrategy $s, int $campus = 0): array
    {
        return $s->plan($this->params($campus, $s->plan($this->params($campus))['digest']));
    }

    /** @return array<int,array<string,mixed>> manifest rows by session id */
    private function rows(array $plan): array
    {
        return array_column($plan['manifest'], null, 'session_id');
    }

    public function test_a_future_extra_on_a_fully_used_course_is_cancelled_and_counters_stay(): void
    {
        [$course, $extra] = $this->fullCourse($this->student(15), now()->addDays(3)->toDateString());
        [, $notFull] = $this->fullCourse($this->student(15), now()->addDays(3)->toDateString(), 2, 1);
        $before = (array) DB::table('StudentClass')->where('ID', $course->ID)->first();
        $s = new ForwardExtraCancelStrategy();

        $plan = $this->pinned($s);
        $this->assertSame(['class_id', 'campus_id', 'session_id', 'session_date', 'start_time', 'class', 'reason'], array_keys($plan['manifest'][0]));
        $this->assertSame(1, $plan['totals']['cancel']);
        $result = $s->execute($plan, ['operation_id' => 'op-1']);

        $this->assertSame(1, $result['cancelled']);
        $this->assertSame('cancelled', $this->st($extra));
        $this->assertSame('scheduled', $this->st($notFull), 'a course that is not fully used is untouched');
        $this->assertEquals($before, (array) DB::table('StudentClass')->where('ID', $course->ID)->first());
        $this->assertSame(1, DB::table('security_audit_events')->where('event_type', 'pop.forward_extra_cancel')->count());
        $this->assertTrue($s->verify($plan, $result)['ok']);
        $this->assertSame('after', $s->plan($this->params(0, $plan['digest']))['state']);
    }

    public function test_a_past_extra_is_cancelled_only_with_a_live_duplicate_on_a_later_course(): void
    {
        $student = $this->student(17);
        $past = now()->subDays(2)->toDateString();
        [$dupCourse, $dup] = $this->fullCourse($student, $past);
        $later = $this->course($student, 8);
        $this->lesson($later, $past, 'scheduled');
        [, $alone] = $this->fullCourse($this->student(17), $past);
        [$cancelledDupCourse, $cancelledDup] = $this->fullCourse($student = $this->student(17), $past);
        $this->lesson($this->course($student, 8), $past, 'cancelled'); // a cancelled twin is not a duplicate
        // Today's lesson may already have ended unmarked: like a past one, it needs a duplicate.
        [, $today] = $this->fullCourse($this->student(17), now('Asia/Taipei')->toDateString());
        // Codex P2: a voided twin covers nothing (the generator's live-slot rule excludes it).
        [, $voidedDup] = $this->fullCourse($student = $this->student(17), $past);
        $this->lesson($this->course($student, 8), $past, 'voided');
        // Codex P2: a legacy learning record with no ClassSessionID, same course + date + start, is activity.
        [$lrCourse, $unbound] = $this->fullCourse($student = $this->student(17), $past);
        $this->lesson($this->course($student, 8), $past, 'scheduled');
        DB::statement('SET FOREIGN_KEY_CHECKS=0'); // a pre-FK legacy row: today's schema no longer accepts it
        DB::table('LearningRecord')->insert(['StudentClassID' => $lrCourse->ID, 'ClassSessionID' => 0, 'TeacherID' => $this->teacherId, 'Content' => '',
            'Subject' => 's', 'SessionDate' => $past, 'StartTime' => '10:00:00', 'EndTime' => '12:00:00', 'Status' => 'pending']);
        // Codex P2: so does a live sign-in on that course and day bound to no existing lesson.
        [$siCourse, $unboundSignIn] = $this->fullCourse($student = $this->student(17), $past);
        $this->lesson($this->course($student, 8), $past, 'scheduled');
        DB::table('StudentSingIn')->insert(['StudentClassID' => $siCourse->ID, 'StudentID' => $siCourse->StudentID, 'ClassSessionID' => null,
            'Status' => 'attended', 'SignInDT' => $past . ' 10:00:00', 'SignOutDT' => $past . ' 12:00:00', 'SessionDeducted' => 1]);
        DB::statement('SET FOREIGN_KEY_CHECKS=1');
        $s = new ForwardExtraCancelStrategy();

        $plan = $this->pinned($s);
        $rows = $this->rows($plan);
        $this->assertSame(['cancel', 'past_duplicate_later_course'], [$rows[$dup]['class'], $rows[$dup]['reason']]);
        $this->assertSame(['review', 'past_no_duplicate'], [$rows[$alone]['class'], $rows[$alone]['reason']]);
        $this->assertSame('review', $rows[$cancelledDup]['class']);
        $this->assertSame(['review', 'past_no_duplicate'], [$rows[$today]['class'], $rows[$today]['reason']]);
        $this->assertSame(['review', 'past_no_duplicate'], [$rows[$voidedDup]['class'], $rows[$voidedDup]['reason']]);
        $this->assertSame(['review', 'activity'], [$rows[$unbound]['class'], $rows[$unbound]['reason']]);
        $this->assertSame(['review', 'activity'], [$rows[$unboundSignIn]['class'], $rows[$unboundSignIn]['reason']]);
        $s->execute($plan, []);

        $this->assertSame(['cancelled', 'scheduled', 'scheduled'], [$this->st($dup), $this->st($alone), $this->st($cancelledDup)]);
    }

    public function test_an_extra_with_a_live_sign_in_or_learning_record_is_never_cancelled(): void
    {
        $future = now()->addDays(3)->toDateString();
        [$c1, $signed] = $this->fullCourse($this->student(3), $future);
        StudentSignIn::create(['StudentClassID' => $c1->ID, 'StudentID' => $c1->StudentID, 'ClassSessionID' => $signed, 'Status' => 'leave',
            'SignInDT' => $future . ' 10:00:00', 'SignOutDT' => $future . ' 10:00:00']);
        [$c2, $noted] = $this->fullCourse($this->student(3), $future);
        DB::table('LearningRecord')->insert(['StudentClassID' => $c2->ID, 'ClassSessionID' => $noted, 'TeacherID' => $this->teacherId, 'Content' => '',
            'Subject' => 's', 'SessionDate' => $future, 'StartTime' => '10:00:00', 'EndTime' => '12:00:00', 'Status' => 'pending']);
        [$c3, $voided] = $this->fullCourse($this->student(3), $future);
        StudentSignIn::create(['StudentClassID' => $c3->ID, 'StudentID' => $c3->StudentID, 'ClassSessionID' => $voided, 'Status' => 'leave',
            'SignInDT' => $future . ' 10:00:00', 'SignOutDT' => $future . ' 10:00:00', 'VoidedAt' => now()]);
        $s = new ForwardExtraCancelStrategy();

        $plan = $this->pinned($s);
        $rows = $this->rows($plan);
        $this->assertSame(['activity', 'activity', 'future'], [$rows[$signed]['reason'], $rows[$noted]['reason'], $rows[$voided]['reason']]);
        $s->execute($plan, []);

        $this->assertSame(['scheduled', 'scheduled', 'cancelled'], [$this->st($signed), $this->st($noted), $this->st($voided)]);
    }

    public function test_dry_run_writes_nothing(): void
    {
        [, $extra] = $this->fullCourse($this->student(4), now()->addDays(3)->toDateString());
        $before = DB::table('ClassSession')->orderBy('id')->get()->all();

        $plan = (new ForwardExtraCancelStrategy())->plan($this->params(4));

        $this->assertSame('unpinned', $plan['state']);
        $this->assertSame(1, $plan['totals']['cancel']);
        $this->assertEquals($before, DB::table('ClassSession')->orderBy('id')->get()->all());
        $this->assertSame(0, DB::table('security_audit_events')->where('event_type', 'like', 'pop.forward_extra_cancel%')->count());
        $this->assertSame(0, (new ForwardExtraCancelStrategy())->plan($this->params(9))['totals']['rows'], 'another campus is out of scope');
        $this->assertSame('scheduled', $this->st($extra));
        $this->assertFalse((new ForwardExtraCancelStrategy())->plan(['campus_id' => 4, 'decision_reference' => 'other'])['ok']);
    }

    public function test_rollback_restores_exactly_the_cancelled_ids(): void
    {
        [, $extra] = $this->fullCourse($this->student(9), now()->addDays(3)->toDateString());
        [, $review] = $this->fullCourse($this->student(9), now()->subDays(3)->toDateString());
        $s = new ForwardExtraCancelStrategy();
        $result = $s->execute($this->pinned($s), []);
        $this->assertSame(['cancelled', 'scheduled'], [$this->st($extra), $this->st($review)]);

        $back = $s->rollback($result['snapshot'], []);

        $this->assertSame([1, []], [$back['restored'], $back['skipped_ids']]);
        $this->assertSame(['scheduled', 'scheduled'], [$this->st($extra), $this->st($review)]);
        $this->assertSame(1, DB::table('security_audit_events')->where('event_type', 'pop.forward_extra_cancel.rollback')->count());
    }

    public function test_apply_refuses_after_a_change_between_plan_and_apply(): void
    {
        $future = now()->addDays(3)->toDateString();
        [$course, $extra] = $this->fullCourse($this->student(11), $future);
        $s = new ForwardExtraCancelStrategy();
        $plan = $this->pinned($s);
        $this->assertSame('digest_mismatch', $s->plan($this->params(0, str_repeat('a', 64)))['state']);
        StudentSignIn::create(['StudentClassID' => $course->ID, 'StudentID' => $course->StudentID, 'ClassSessionID' => $extra, 'Status' => 'leave',
            'SignInDT' => $future . ' 10:00:00', 'SignOutDT' => $future . ' 10:00:00']);

        try {
            $s->execute($plan, []);
            $this->fail('drift must refuse');
        } catch (RuntimeException $e) {
            $this->assertStringContainsString('forward_extra_cancel_digest_drift', $e->getMessage());
        }
        $this->assertSame('scheduled', $this->st($extra));
        $this->expectException(RuntimeException::class);
        $s->execute($s->plan($this->params()), []);
    }

    public function test_a_cancel_run_is_undone_by_an_approved_restore_request(): void
    {
        // Codex P2: the reachable rollback is a new approved request of this operation naming the cancel request (#4736 pattern);
        // the Pi runs it like any approved execute, so it needs no recovery-phase allowlist.
        [, $extra] = $this->fullCourse($this->student(26), now()->addDays(3)->toDateString());
        $service = new PopOperationService(new PopOperationCatalog(dirname(__DIR__, 4) . '/operations/catalog.yaml'));
        $sha = str_repeat('c', 40);
        $go = function (string $restore) use ($service, $sha): array {
            $draft = fn (string $digest): string => (string) $service->createDraft('forward-extra-cancel-20261101', $this->params(26, $digest, $restore),
                'fx-test-' . bin2hex(random_bytes(4)), 'user:1', 'super_admin', [], 1)['id'];
            $digest = (string) $service->runDryRun($draft(''), 'user:1', 1, 'super_admin', [])['plan']['digest'];
            $id = $draft($digest);
            $service->runDryRun($id, 'user:1', 1, 'super_admin', []);
            $token = $service->approve($id, 'founder-go-forward-extra-test-' . substr($id, 0, 8), 'user:2', 'super_admin', $sha, 2, [], 15)['token'];

            $execute = $service->run($id, 'execute', $token, $sha, 'pop-pi-local')['result'];
            $verify = $service->run($id, 'verify', $token, $sha, 'pop-pi-local')['result'];

            return [$id, $execute, $verify === 'succeeded' ? $verify : $verify . ' ' . $this->why($id, $restore)];
        };

        [$cancelId, $execute, $verify] = $go('');
        $this->assertSame(['succeeded', 'succeeded', 'cancelled'], [$execute, $verify, $this->st($extra)]);
        [, $execute, $verify] = $go($cancelId);
        $this->assertSame(['succeeded', 'succeeded', 'scheduled'], [$execute, $verify, $this->st($extra)]);
        $plan = (new ForwardExtraCancelStrategy())->plan($this->params(26, '', $cancelId));
        $this->assertSame(['skip'], array_column($plan['manifest'], 'class'), 'nothing left to restore');
    }

    public function test_the_catalog_entry_runs_through_pop_and_requires_the_exact_founder_shape(): void
    {
        [, $extra] = $this->fullCourse($this->student(26), now()->addDays(3)->toDateString());
        $catalog = new PopOperationCatalog(dirname(__DIR__, 4) . '/operations/catalog.yaml');
        $service = new PopOperationService($catalog);
        // Campus 26 only: rows other tests in the suite leave behind are not this test's.
        $run = function (string $digest) use ($service): array {
            $id = (string) $service->createDraft('forward-extra-cancel-20261101', $this->params(26, $digest), 'fx-test-' . bin2hex(random_bytes(4)), 'user:1', 'super_admin', [], 1)['id'];
            $dry = $service->runDryRun($id, 'user:1', 1, 'super_admin', []);
            if ($digest === '') {
                return [$dry];
            }
            $sha = str_repeat('c', 40);
            $token = $service->approve($id, 'founder-go-forward-extra-test', 'user:2', 'super_admin', $sha, 2, [], 15)['token'];

            return [$dry, $service->run($id, 'execute', $token, $sha, 'pop-pi-local'), $service->run($id, 'verify', $token, $sha, 'pop-pi-local') + ['id' => $id]];
        };
        $digest = (string) $run('')[0]['plan']['digest'];
        [, $execute, $verify] = $run($digest);

        $this->assertSame(['succeeded', 'succeeded'], [$execute['result'], $verify['result']], $this->why($verify['id'], ''));
        $this->assertSame('cancelled', $this->st($extra));
        $adapter = file_get_contents(dirname(__DIR__, 4) . '/.github/workflows/pop-forward-extra-cancel.yml');
        $this->assertStringContainsString('AUDIT="pop.forward_extra_cancel${RESTORE:+.rollback}"', $adapter);
        $entry = $catalog->operation('forward-extra-cancel-20261101');
        $this->assertSame(['campus_id', 'decision_reference', 'expected_digest', 'restore_request_id'], $entry['parameter_keys']);
        $roles = new ReflectionMethod($service, 'approvalRoles');
        $this->assertSame(['super_admin'], $roles->invoke($service, $entry));
        $entry['blast_radius'] = 'global';
        $this->expectException(RuntimeException::class);
        $roles->invoke($service, $entry);
    }
}
