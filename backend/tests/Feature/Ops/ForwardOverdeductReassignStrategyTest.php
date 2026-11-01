<?php

namespace Tests\Feature\Ops;

use App\Models\Student;
use App\Models\StudentClass;
use App\Models\User;
use App\Operations\PopOperationCatalog;
use App\Operations\PopOperationService;
use App\Operations\Strategies\ForwardOverdeductReassignStrategy;
use App\Services\SessionDeductionService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use ReflectionMethod;
use RuntimeException;
use Tests\TestCase;

/** GitHub #4554 (in-app #452), Founder decision 2A: move taught forward extras beyond the bought count onto the student's later contract. */
final class ForwardOverdeductReassignStrategyTest extends TestCase
{
    use RefreshDatabase;

    private const REF = 'reassign-forward-overdeduct-20261101';

    private int $teacherId;

    protected function setUp(): void
    {
        parent::setUp();
        $this->teacherId = (int) User::create(['LoginName' => 'fo-teacher@t.com', 'Name' => 'T', 'PSW' => 's', 'type' => 'T', 'phone' => 911000001])->id;
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
            'UsedSessions' => 0, 'SessionDuration' => 120, 'ClassType' => 'one_on_one', 'Rate' => 500, 'rate_unit' => 'session', 'Charge' => 0, 'Pay' => 0,
            'Paid' => 0, 'Stop' => 0]);
    }

    /** A taught (attended + ledger deduct) lesson. */
    private function taught(StudentClass $course, string $date, string $note = '', string $start = '10:00:00'): int
    {
        $id = (int) DB::table('ClassSession')->insertGetId(['StudentClassID' => $course->ID, 'SessionDate' => $date, 'StartTime' => $start,
            'EndTime' => '12:00:00', 'Status' => 'attended', 'Note' => $note, 'created_at' => now(), 'updated_at' => now()]);
        DB::table('session_deduction_ledger')->insert(['student_class_id' => $course->ID, 'event_type' => 'deduct', 'source' => 'attendance',
            'class_session_id' => $id, 'created_at' => now(), 'updated_at' => now()]);

        return $id;
    }

    /** A course that bought $total, with $total normal taught lessons plus one taught generated extra on $date. @return array{0: StudentClass, 1: int} */
    private function overCourse(int $student, string $date, int $total = 1): array
    {
        $course = $this->course($student, $total);
        for ($i = 1; $i <= $total; $i++) {
            $this->taught($course, now()->subDays(30 + $i)->toDateString());
        }

        return [$course, $this->taught($course, $date, '系統向前生成 #1062')];
    }

    /** A later contract of $total lessons with $used taught. */
    private function later(int $student, int $total, int $used = 0): StudentClass
    {
        $course = $this->course($student, $total);
        for ($i = 1; $i <= $used; $i++) {
            $this->taught($course, now()->subDays(60 + $i)->toDateString());
        }

        return $course;
    }

    private function onCourse(int $sessionId): int
    {
        return (int) DB::table('ClassSession')->where('id', $sessionId)->value('StudentClassID');
    }

    private function used(StudentClass $c): int
    {
        return (int) SessionDeductionService::batchExpectedUsedSessionDiagnostics([$c->ID])[$c->ID]['uncapped_used'];
    }

    private function pinned(ForwardOverdeductReassignStrategy $s, int $campus): array
    {
        return $s->plan($this->params($campus, $s->plan($this->params($campus))['digest']));
    }

    /** @return array<int,array<string,mixed>> manifest rows by session id */
    private function rows(array $plan): array
    {
        return array_column($plan['manifest'], null, 'session_id');
    }

    public function test_codex_review_cases_stay_review_and_only_counted_extras_move(): void
    {
        $past = now()->subDays(5)->toDateString();
        // P1: a minute-based later contract (one 240-minute makeup on 2 x 120) is never a target.
        $s1 = $this->student(28);
        [, $toMinuteTarget] = $this->overCourse($s1, $past);
        $minuteTarget = $this->later($s1, 2);
        $makeup = (int) DB::table('ClassSession')->insertGetId(['StudentClassID' => $minuteTarget->ID, 'SessionDate' => now()->subDays(40)->toDateString(),
            'StartTime' => '10:00:00', 'EndTime' => '14:00:00', 'Status' => 'attended', 'Note' => '', 'created_at' => now(), 'updated_at' => now()]);
        DB::table('session_deduction_ledger')->insert(['student_class_id' => $minuteTarget->ID, 'event_type' => 'deduct', 'source' => 'attendance',
            'class_session_id' => $makeup, 'minutes' => 240, 'created_at' => now(), 'updated_at' => now()]);
        // P1: an approved assessment by a substitute could move to the substitute's contract but never move back.
        $s2 = $this->student(28);
        [, $assessed] = $this->overCourse($s2, $past);
        $substitute = (int) User::create(['LoginName' => 'fo-sub@t.com', 'Name' => 'S', 'PSW' => 's', 'type' => 'T', 'phone' => 911000002])->id;
        $subContract = $this->later($s2, 5);
        $subContract->forceFill(['TeacherID' => $substitute])->save();
        DB::table('LearningRecord')->insert(['StudentClassID' => $this->onCourse($assessed), 'ClassSessionID' => $assessed, 'TeacherID' => $substitute,
            'Content' => 'reviewed fractions and word problems', 'Subject' => 's', 'SessionDate' => $past, 'StartTime' => '10:00:00', 'EndTime' => '12:00:00',
            'Status' => 'approved']);
        // P2: excess 2 but only one generated lesson: a partial move would leave the course over, so nothing moves.
        $s3 = $this->student(28);
        $mixed = $this->course($s3, 2);
        for ($i = 1; $i <= 3; $i++) {
            $this->taught($mixed, now()->subDays(30 + $i)->toDateString());
        }
        $lonely = $this->taught($mixed, $past, '系統向前生成 #1062');
        $this->later($s3, 5);
        // Codex P1 (2nd round): the reassigner does not move package_session_ledger, so a package-linked source or target never moves.
        $s4 = $this->student(28);
        $pkg = \App\Models\CoursePackage::create(['student_id' => $s4, 'campus_id' => 28, 'name' => 'p', 'total_sessions' => 6, 'remaining_sessions' => 6,
            'used_sessions' => 0, 'rate' => 500, 'rate_unit' => 'session', 'class_type' => 'one_on_one', 'paid' => false, 'stop' => false, 'enabled' => true]);
        [$pkgSource] = $this->overCourse($s4, $past);
        $pkgSource->forceFill(['PackageID' => $pkg->id])->save();
        $this->later($s4, 5);
        $s5 = $this->student(28);
        [, $toPackage] = $this->overCourse($s5, $past);
        $this->later($s5, 5)->forceFill(['PackageID' => $pkg->id])->save();
        // Codex P1 (3rd round): a later contract with another lesson length would be over-drawn in minutes.
        $s6 = $this->student(28);
        [, $toShorter] = $this->overCourse($s6, $past);
        $this->later($s6, 5)->forceFill(['SessionDuration' => 60])->save();
        // Codex P2: a live sign-in bound to no lesson counts toward usage but cannot move, so the course stays manual.
        $s7 = $this->student(28);
        [$unboundSource] = $this->overCourse($s7, $past);
        $this->later($s7, 5);
        DB::statement('SET FOREIGN_KEY_CHECKS=0'); // a legacy row today's schema no longer accepts
        DB::table('StudentSingIn')->insert(['StudentClassID' => $unboundSource->ID, 'StudentID' => $s7, 'ClassSessionID' => 0, 'Status' => 'attended',
            'SignInDT' => now()->subDays(50)->toDateString() . ' 10:00:00', 'SignOutDT' => now()->subDays(50)->toDateString() . ' 12:00:00', 'SessionDeducted' => 1]);
        DB::statement('SET FOREIGN_KEY_CHECKS=1');
        // Codex P2 (4th round): a future generated lesson with a deduction is not counted usage, so yesterday's extra moves, not it.
        $s8 = $this->student(28);
        [$withFuture, $yesterday] = $this->overCourse($s8, now()->subDay()->toDateString());
        $tomorrow = $this->taught($withFuture, now()->addDay()->toDateString(), '系統向前生成 #1062', '14:00:00');
        $this->later($s8, 5);
        // Codex P2 (5th round): usage from taught lessons with no ledger row would not clear by moving a ledger-backed extra.
        $s9 = $this->student(28);
        $noLedger = $this->course($s9, 2);
        for ($i = 1; $i <= 3; $i++) {
            DB::table('ClassSession')->insert(['StudentClassID' => $noLedger->ID, 'SessionDate' => now()->subDays(30 + $i)->toDateString(), 'StartTime' => '10:00:00',
                'EndTime' => '12:00:00', 'Status' => 'attended', 'Note' => '', 'created_at' => now(), 'updated_at' => now()]);
        }
        $this->taught($noLedger, $past, '系統向前生成 #1062');
        $this->later($s9, 5);
        // Codex P2 (5th round): a voided lesson still holds the slot for the reassigner, so that target is skipped.
        $s10 = $this->student(28);
        [, $toVoidedSlot] = $this->overCourse($s10, $past);
        $voidedHolder = $this->later($s10, 5);
        DB::table('ClassSession')->insert(['StudentClassID' => $voidedHolder->ID, 'SessionDate' => $past, 'StartTime' => '10:00:00', 'EndTime' => '12:00:00',
            'Status' => 'voided', 'Note' => '', 'created_at' => now(), 'updated_at' => now()]);

        $plan = (new ForwardOverdeductReassignStrategy())->plan($this->params(28));
        $rows = $this->rows($plan);
        $courseRows = array_map(fn ($r) => ['class_id' => $r['class_id'], 'class' => $r['class'], 'reason' => $r['reason']],
            array_values(array_filter($plan['manifest'], fn ($r) => $r['session_id'] === 0)));
        $this->assertSame(['review', 'no_target_with_room'], [$rows[$toMinuteTarget]['class'], $rows[$toMinuteTarget]['reason']]);
        $this->assertSame(['review', 'assessment_locked'], [$rows[$assessed]['class'], $rows[$assessed]['reason']]);
        $this->assertArrayNotHasKey($lonely, $rows);
        $this->assertSame(['review', 'no_target_with_room'], [$rows[$toPackage]['class'], $rows[$toPackage]['reason']]);
        $this->assertContains(['class_id' => $pkgSource->ID, 'class' => 'review', 'reason' => 'package_linked'], $courseRows);
        $this->assertSame(['review', 'target_duration_differs'], [$rows[$toShorter]['class'], $rows[$toShorter]['reason']]);
        $this->assertContains(['class_id' => $unboundSource->ID, 'class' => 'review', 'reason' => 'unbound_usage'], $courseRows);
        $this->assertSame(['move', 'later_contract'], [$rows[$yesterday]['class'], $rows[$yesterday]['reason']]);
        $this->assertContains(['class_id' => $noLedger->ID, 'class' => 'review', 'reason' => 'usage_not_from_ledger'], $courseRows);
        $this->assertSame(['review', 'target_slot_taken'], [$rows[$toVoidedSlot]['class'], $rows[$toVoidedSlot]['reason']]);
        $this->assertArrayNotHasKey($tomorrow, $rows);
        $this->assertSame(1, $plan['totals']['move'], 'only the counted extra moves');
        $this->assertContains(['class_id' => $mixed->ID, 'class' => 'review', 'reason' => 'insufficient_generated_lessons'], $courseRows);
    }

    public function test_a_taught_extra_moves_to_a_later_contract_with_room_and_counters_are_fixed(): void
    {
        $student = $this->student(27);
        [$a, $extra] = $this->overCourse($student, now()->subDays(5)->toDateString());
        $b = $this->later($student, 5);
        $this->assertSame(2, $this->used($a));
        $s = new ForwardOverdeductReassignStrategy();

        $plan = $this->pinned($s, 27);
        $this->assertSame(['class_id', 'campus_id', 'session_id', 'session_date', 'start_time', 'to_class_id', 'class', 'reason'], array_keys($plan['manifest'][0]));
        $this->assertSame([1, $b->ID, 'later_contract'], [$plan['totals']['move'], $plan['manifest'][0]['to_class_id'], $plan['manifest'][0]['reason']]);
        $result = $s->execute($plan, ['operation_id' => 'op-1']);

        $this->assertSame(1, $result['moved']);
        $this->assertSame($b->ID, $this->onCourse($extra));
        $this->assertSame([1, 1], [$this->used($a), $this->used($b)]);
        $this->assertSame(1, DB::table('security_audit_events')->where('event_type', 'pop.forward_overdeduct_reassign')->count());
        $this->assertSame(1, DB::table('class_session_reassignments')->where('class_session_id', $extra)->count());
        $this->assertTrue($s->verify($plan, $result)['ok']);
        $this->assertSame('after', $s->plan($this->params(27, $plan['digest']))['state']);
    }

    public function test_no_later_contract_keeps_the_gift_and_writes_nothing(): void
    {
        [$a, $extra] = $this->overCourse($this->student(28), now()->subDays(5)->toDateString());
        $before = DB::table('ClassSession')->orderBy('id')->get()->all();
        $s = new ForwardOverdeductReassignStrategy();

        $plan = $s->plan($this->params(28));

        $this->assertSame(['keep', 'gift_no_later_contract'], [$plan['manifest'][0]['class'], $plan['manifest'][0]['reason']]);
        $this->assertSame('unpinned', $plan['state']);
        $this->assertEquals($before, DB::table('ClassSession')->orderBy('id')->get()->all());
        $this->assertSame($a->ID, $this->onCourse($extra));
        $this->assertSame(0, $s->plan($this->params(29))['totals']['rows'], 'another campus is out of scope');
        $this->assertFalse($s->plan(['campus_id' => 28, 'decision_reference' => 'other'])['ok']);
    }

    public function test_doubtful_lessons_are_reviewed_never_moved(): void
    {
        $date = now()->subDays(5)->toDateString();
        // later contract is full
        $s1 = $this->student(27);
        [, $full] = $this->overCourse($s1, $date);
        $this->later($s1, 1, 1);
        // target slot taken by a live lesson at the same date and start
        $s2 = $this->student(27);
        [, $taken] = $this->overCourse($s2, $date);
        $this->taught($this->later($s2, 5), $date);
        // settled source
        $s3 = $this->student(27);
        [$settledCourse, $settled] = $this->overCourse($s3, $date);
        $this->later($s3, 5);
        DB::table('StudentClass')->where('ID', $settledCourse->ID)->update(['closed_reason' => 'usage_settled']);
        // partial minutes
        $s4 = $this->student(27);
        [$partialCourse] = $this->overCourse($s4, $date);
        $this->later($s4, 5);
        DB::table('session_deduction_ledger')->where('student_class_id', $partialCourse->ID)->limit(1)->update(['minutes' => 30]);
        $s = new ForwardOverdeductReassignStrategy();

        $plan = $this->pinned($s, 27);
        $rows = $this->rows($plan);
        $this->assertSame(['review', 'no_target_with_room'], [$rows[$full]['class'], $rows[$full]['reason']]);
        $this->assertSame(['review', 'target_slot_taken'], [$rows[$taken]['class'], $rows[$taken]['reason']]);
        $this->assertSame(['review', 'course_settled'], [$rows[$settled]['class'], $rows[$settled]['reason']]);
        $this->assertSame(['review', 'partial_minutes', 0], [$rows[0]['class'], $rows[0]['reason'], $rows[0]['session_id']]);
        $this->assertSame(0, $plan['totals']['move']);
        $this->assertSame('after', $plan['state']);
    }

    public function test_apply_refuses_after_a_change_between_plan_and_apply(): void
    {
        $student = $this->student(27);
        [, $extra] = $this->overCourse($student, now()->subDays(5)->toDateString());
        $b = $this->later($student, 5);
        $s = new ForwardOverdeductReassignStrategy();
        $plan = $this->pinned($s, 27);
        $this->assertSame('digest_mismatch', $s->plan($this->params(27, str_repeat('a', 64)))['state']);
        $this->taught($b, now()->subDays(5)->toDateString()); // the target slot is taken meanwhile

        try {
            $s->execute($plan, []);
            $this->fail('drift must refuse');
        } catch (RuntimeException $e) {
            $this->assertStringContainsString('forward_overdeduct_reassign_digest_drift', $e->getMessage());
        }
        $this->assertNotSame($b->ID, $this->onCourse($extra));
        $this->expectException(RuntimeException::class);
        $s->execute($s->plan($this->params(27)), []);
    }

    public function test_rollback_moves_exactly_the_snapshot_back(): void
    {
        $student = $this->student(27);
        [$a, $extra] = $this->overCourse($student, now()->subDays(5)->toDateString());
        $b = $this->later($student, 5);
        $s = new ForwardOverdeductReassignStrategy();
        $result = $s->execute($this->pinned($s, 27), []);
        $this->assertSame($b->ID, $this->onCourse($extra));

        $back = $s->rollback($result['snapshot'], []);

        $this->assertSame([1, []], [$back['restored'], $back['skipped_ids']]);
        $this->assertSame([$a->ID, 2, 0], [$this->onCourse($extra), $this->used($a), $this->used($b)]);
        $this->assertSame(1, DB::table('security_audit_events')->where('event_type', 'pop.forward_overdeduct_reassign.rollback')->count());
    }

    public function test_a_reassign_run_is_undone_by_an_approved_restore_request(): void
    {
        $student = $this->student(28);
        [$a, $extra] = $this->overCourse($student, now()->subDays(5)->toDateString());
        $b = $this->later($student, 5);
        $service = new PopOperationService(new PopOperationCatalog(dirname(__DIR__, 4) . '/operations/catalog.yaml'));
        $sha = str_repeat('c', 40);
        $go = function (string $restore) use ($service, $sha): array {
            $draft = fn (string $digest): string => (string) $service->createDraft('forward-overdeduct-reassign-20261101', $this->params(28, $digest, $restore),
                'fo-test-' . bin2hex(random_bytes(4)), 'user:1', 'super_admin', [], 1)['id'];
            $digest = (string) $service->runDryRun($draft(''), 'user:1', 1, 'super_admin', [])['plan']['digest'];
            $id = $draft($digest);
            $service->runDryRun($id, 'user:1', 1, 'super_admin', []);
            $token = $service->approve($id, 'founder-go-forward-overdeduct-test-' . substr($id, 0, 8), 'user:2', 'super_admin', $sha, 2, [], 15)['token'];

            return [$id, $service->run($id, 'execute', $token, $sha, 'pop-pi-local')['result'], $service->run($id, 'verify', $token, $sha, 'pop-pi-local')['result']];
        };

        [$reassignId, $execute, $verify] = $go('');
        $this->assertSame(['succeeded', 'succeeded', $b->ID], [$execute, $verify, $this->onCourse($extra)]);
        [, $execute, $verify] = $go($reassignId);
        $this->assertSame(['succeeded', 'succeeded', $a->ID], [$execute, $verify, $this->onCourse($extra)]);
        $plan = (new ForwardOverdeductReassignStrategy())->plan($this->params(28, '', $reassignId));
        $this->assertSame(['skip'], array_column($plan['manifest'], 'class'), 'nothing left to restore');
    }

    public function test_the_catalog_entry_and_workflow_have_the_exact_founder_shape(): void
    {
        $catalog = new PopOperationCatalog(dirname(__DIR__, 4) . '/operations/catalog.yaml');
        $service = new PopOperationService($catalog);
        $adapter = file_get_contents(dirname(__DIR__, 4) . '/.github/workflows/pop-forward-overdeduct-reassign.yml');
        $this->assertStringContainsString('AUDIT="pop.forward_overdeduct_reassign${RESTORE:+.rollback}"', $adapter);
        $this->assertStringContainsString('DRY_RUN_FORWARD_OVERDEDUCT_$KIND', $adapter);
        $this->assertStringContainsString('APPROVE_FORWARD_OVERDEDUCT_$KIND', $adapter);
        $entry = $catalog->operation('forward-overdeduct-reassign-20261101');
        $this->assertSame(['campus_id', 'decision_reference', 'expected_digest', 'restore_request_id'], $entry['parameter_keys']);
        $roles = new ReflectionMethod($service, 'approvalRoles');
        $this->assertSame(['super_admin'], $roles->invoke($service, $entry));
        $entry['blast_radius'] = 'global';
        $this->expectException(RuntimeException::class);
        $roles->invoke($service, $entry);
    }
}
