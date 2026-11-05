<?php

namespace Tests\Feature\Ops;

use App\Operations\PopOperationCatalog;
use App\Operations\PopOperationService;
use App\Operations\Strategies\TaughtLessonSignInRepairStrategy;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use ReflectionMethod;
use RuntimeException;
use Tests\TestCase;

/** GitHub #4925 #4927 #4931 (in-app #502, #504): taught, debited lessons with no sign-in get one; synthetic ids shaped like the real campus-9 case. */
final class TaughtLessonSignInRepairStrategyTest extends TestCase
{
    use RefreshDatabase;

    private const REF = 'repair-taught-lesson-signin-20261105';

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow(Carbon::parse('2026-11-05 10:00:00', 'Asia/Taipei'));
        foreach ([[289, 'part_time'], [30, 'full_time'], [288, 'part_time']] as [$id, $type]) {
            DB::table('User')->insert(['id' => $id, 'LoginName' => "t{$id}@test.com", 'Name' => 't', 'PSW' => 'x', 'type' => 'T', 'phone' => '0900000000',
                'MustChangePassword' => 0, 'employment_type' => $type]);
        }
        foreach ([[2432, 9], [2433, 9], [2500, 2]] as [$id, $campus]) {
            DB::table('Student')->insert(['id' => $id, 'name' => 's', 'CampusID' => $campus, 'ClassID' => 1, 'enable' => 1]);
        }
        $this->course(4640, 2432, 289);
        $this->course(4285, 2433, 30);
        $this->course(5000, 2500, 999); // no such user: payee does not resolve

        $this->lesson(47205, 4640, '2026-10-31', 'completed', 1, '', '（系統自動核准）');   // enrollment 已上過: write
        $this->lesson(47300, 4640, '2026-09-10', 'attended', 1);                          // calendar scheduled -> attended: write, September locked
        $this->lesson(43605, 4285, '2026-10-06', 'completed', 1, '系統判定補登（新增時已過下課時間）'); // full-time payee: write
        $this->lesson(50000, 5000, '2026-10-05', 'completed', 1);                         // review: payee unresolved
        $this->lesson(47301, 4640, '2026-10-24', 'completed', 0, '', null, 'pending');    // pending evaluation, not debited: out
        $this->lesson(47302, 4640, '2026-10-17', 'completed', 1);                         // only a voided sign-in row: write by un-voiding it
        DB::table('StudentSingIn')->insert(['StudentClassID' => 4640, 'StudentID' => 2432, 'TeacherID' => 288, 'ClassSessionID' => 47302,
            'SignInDT' => '2026-10-17 17:00:00', 'SignOutDT' => '2026-10-17 19:00:00', 'StudentID' => 2432, 'CampusID' => 9, 'GradeID' => 1, 'SubjectID' => 1, 'PersonType' => 'student', 'Status' => 'absent', 'MDT' => '2026-10-18 09:00:00', 'VoidedAt' => '2026-10-18 10:00:00',
            'VoidedByUserID' => 30, 'VoidReason' => 'r', 'SessionDeducted' => 0, 'Memo' => 'old']);
        $this->lesson(47309, 4640, '2026-10-14', 'completed', 1);                         // voided row from another time slot (rescheduled): review, not written
        DB::table('StudentSingIn')->insert(['StudentClassID' => 4640, 'StudentID' => 2432, 'TeacherID' => 289, 'ClassSessionID' => 47309, 'CampusID' => 9, 'GradeID' => 1,
            'SubjectID' => 1, 'PersonType' => 'student', 'SignInDT' => '2026-10-14 15:00:00', 'SignOutDT' => '2026-10-14 16:00:00', 'Status' => 'present', 'MDT' => now(),
            'VoidedAt' => now()]);
        $this->lesson(47308, 4640, '2026-10-12', 'late', 1);                              // old calendar 遲到, debited, no sign-in: write
        $this->lesson(47303, 4640, '2026-10-10', 'completed', 1);                         // debited then reversed (net 0): out
        DB::table('session_deduction_ledger')->insert(['student_class_id' => 4640, 'class_session_id' => 47303, 'event_type' => 'reverse', 'source' => 'retro_leave']);
        $this->lesson(47304, 4640, '2026-07-20', 'completed', 1);                         // before the floor: out
        $this->lesson(47306, 4640, '2026-11-05', 'completed', 1);                         // today: out
        $this->lesson(47307, 4640, '2026-10-03', 'scheduled', 1);                         // not taught: out

        // September 2026 at campus 9 is locked with one snapshot line.
        DB::table('payroll_runs')->insert(['id' => 1, 'branch_id' => 9, 'month' => '2026-09', 'status' => 'locked']);
        DB::table('payroll_run_lines')->insert(['run_id' => 1, 'class_session_id' => 40000, 'teacher_id' => 289, 'session_salary' => 600, 'payload' => '{}']);
        DB::table('payroll_month_status')->insert(['branch_id' => 9, 'month' => '2026-09', 'status' => 'locked', 'current_run_id' => 1]);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function params(int $campus = 0, string $digest = '', string $restore = ''): array
    {
        return ['campus_id' => $campus, 'decision_reference' => self::REF, 'expected_digest' => $digest, 'restore_request_id' => $restore];
    }

    private function course(int $id, int $student, int $teacher): void
    {
        DB::table('StudentClass')->insert(['ID' => $id, 'StudentID' => $student, 'GradeID' => 1, 'SubjectID' => 1, 'TeacherID' => $teacher, 'by1' => 1,
            'Period' => 4, 'Pay' => 0, 'Rate' => 500, 'TotalHours' => 4, 'StartDate' => '2026-07-01', 'ClassType' => 'one_on_one', 'ScheduleMode' => 'count',
            'SessionCount' => 20, 'UsedSessions' => 7, 'RemainingSessions' => 13, 'Stop' => 0, 'Paid' => 1, 'Charge' => 10000]);
    }

    private function lesson(int $id, int $course, string $date, string $status, int $debits, string $note = '', ?string $content = null, string $lr = 'approved'): void
    {
        DB::table('ClassSession')->insert(['id' => $id, 'StudentClassID' => $course, 'SessionDate' => $date, 'StartTime' => '17:00:00',
            'EndTime' => '19:00:00', 'Status' => $status, 'Note' => $note, 'created_at' => now(), 'updated_at' => now()]);
        for ($i = 0; $i < $debits; $i++) {
            DB::table('session_deduction_ledger')->insert(['student_class_id' => $course, 'class_session_id' => $id, 'event_type' => 'deduct', 'source' => 'attendance']);
        }
        if ($content !== null || $lr === 'pending') {
            DB::table('LearningRecord')->insert(['StudentClassID' => $course, 'ClassSessionID' => $id, 'StudentID' => DB::table('StudentClass')->where('ID', $course)->value('StudentID'),
                'TeacherID' => DB::table('StudentClass')->where('ID', $course)->value('TeacherID'), 'SessionDate' => $date, 'Content' => $content ?? '', 'Status' => $lr]);
        }
    }

    /** @return array<string, list<array<string,mixed>>> everything the repair must not write */
    private function untouched(): array
    {
        return array_map(fn ($t) => DB::table($t)->orderBy(DB::raw('1'))->get()->map(fn ($r) => (array) $r)->all(),
            ['session_deduction_ledger' => 'session_deduction_ledger', 'payroll_month_status' => 'payroll_month_status', 'payroll_runs' => 'payroll_runs',
                'payroll_run_lines' => 'payroll_run_lines', 'StudentClass' => 'StudentClass', 'ClassSession' => 'ClassSession', 'LearningRecord' => 'LearningRecord']);
    }

    public function test_dry_run_lists_taught_debited_lessons_without_any_sign_in_and_writes_nothing(): void
    {
        $s = new TaughtLessonSignInRepairStrategy();
        $before = DB::table('StudentSingIn')->count();
        $plan = $s->plan($this->params());
        self::assertTrue($plan['ok']);
        self::assertSame('unpinned', $plan['state']);
        $byId = array_column($plan['manifest'], null, 'session_id');
        self::assertSame([43605, 47205, 47300, 47302, 47308, 47309, 50000], array_keys($byId));
        self::assertSame(['review', 'voided_row_fields_differ', 'voided'], [$byId[47309]['class'], $byId[47309]['reason'], $byId[47309]['group']]);
        self::assertSame('2026-10-14 15:00:00', $byId[47309]['voided_fields']['SignInDT']); // the compared fields are in the manifest, so the digest covers them
        self::assertSame([['missing', 0], ['missing', 0], ['voided', 1], ['late', 0]], array_map(fn ($id) => [$byId[$id]['group'], (int) ($byId[$id]['voided_sign_in_id'] > 0)], [47205, 47300, 47302, 47308]));
        self::assertSame(['write', 289, 'part_time', '2026-10', false, 'enrollment_confirmed'],
            [$byId[47205]['class'], $byId[47205]['payee_teacher_id'], $byId[47205]['employment_type'], $byId[47205]['month'], $byId[47205]['month_locked'], $byId[47205]['source']]);
        self::assertSame([true, 'other'], [$byId[47300]['month_locked'], $byId[47300]['source']]);
        self::assertSame(['full_time', 'enrollment_backfill'], [$byId[43605]['employment_type'], $byId[43605]['source']]);
        self::assertSame(['review', 'payee_unresolved'], [$byId[50000]['class'], $byId[50000]['reason']]);
        self::assertSame(['rows' => 7, 'write' => 5, 'review' => 2, 'part_time' => 4, 'month_locked' => 1, 'by_group' => ['late' => 1, 'missing' => 4, 'voided' => 2],
            'by_source' => ['enrollment_backfill' => 1, 'enrollment_confirmed' => 1, 'other' => 3], 'by_campus' => [9 => ['write' => 5, 'other' => 3]]], $plan['totals']);
        self::assertSame([50000], array_column($s->plan($this->params(2))['manifest'], 'session_id'));

        self::assertSame($plan['digest'], $s->plan($this->params())['digest']); // idempotent
        self::assertSame($before, DB::table('StudentSingIn')->count());
    }

    public function test_execute_is_pinned_writes_one_sign_in_each_without_moving_ledger_or_payroll_and_rolls_back(): void
    {
        $s = new TaughtLessonSignInRepairStrategy();
        $digest = $s->plan($this->params())['digest'];
        self::assertSame('digest_mismatch', $s->plan($this->params(0, str_repeat('a', 64)))['state']);
        $before = $this->untouched();
        $plan = $s->plan($this->params(0, $digest));
        self::assertSame('pinned', $plan['state']);

        $result = $s->execute($plan, ['operation_id' => 't']);
        self::assertSame(5, $result['created']);
        foreach ([47205 => [289, '2026-10-31', 'present'], 47300 => [289, '2026-09-10', 'present'], 43605 => [30, '2026-10-06', 'present'],
            47302 => [289, '2026-10-17', 'present'], 47308 => [289, '2026-10-12', 'late']] as $session => [$teacher, $date, $status]) {
            $rows = DB::table('StudentSingIn')->where('ClassSessionID', $session)->get();
            self::assertCount(1, $rows);
            self::assertSame([$status, $teacher, 1, null, "$date 17:00:00", "$date 19:00:00"], [(string) $rows[0]->Status, (int) $rows[0]->TeacherID,
                (int) $rows[0]->SessionDeducted, $rows[0]->VoidedAt, (string) $rows[0]->SignInDT, (string) $rows[0]->SignOutDT]);
        }
        self::assertFalse(DB::table('StudentSingIn')->where('ClassSessionID', 50000)->exists());
        self::assertSame($before, $this->untouched()); // ledger, counters, lesson status, records and the locked run are as they were
        self::assertTrue(DB::table('security_audit_events')->where('event_type', 'pop.taught_lesson_signin_repair')->exists());
        self::assertTrue($s->verify($plan, $result)['ok']);

        $again = $s->plan($this->params(0, $digest));
        self::assertSame('after', $again['state']);
        self::assertSame(0, $s->execute($again, [])['created']); // re-execute is a no-op
        self::assertSame(5, DB::table('StudentSingIn')->where('Memo', 'pop_taught_signin_20261105')->count());

        $rolled = $s->rollback($result['snapshot'], []);
        self::assertSame([4, 1], [$rolled['deleted'], $rolled['revoided']]);
        self::assertSame(0, DB::table('StudentSingIn')->where('Memo', 'pop_taught_signin_20261105')->count());
        self::assertSame($before, $this->untouched());
        self::assertSame('unpinned', $s->plan($this->params())['state']);
    }

    public function test_a_voided_row_is_unvoided_and_rollback_re_voids_exactly_it_and_deletes_only_the_created_rows(): void
    {
        $s = new TaughtLessonSignInRepairStrategy();
        $original = (array) DB::table('StudentSingIn')->where('ClassSessionID', 47302)->first();
        $result = $s->execute($s->plan($this->params(0, $s->plan($this->params())['digest'])), []);

        $live = DB::table('StudentSingIn')->where('ClassSessionID', 47302)->get();
        self::assertCount(1, $live); // un-voided, not a second row
        self::assertSame([$original['id'], null, 'present', 289, 1, 'pop_taught_signin_20261105'],
            [$live[0]->id, $live[0]->VoidedAt, $live[0]->Status, (int) $live[0]->TeacherID, (int) $live[0]->SessionDeducted, $live[0]->Memo]);
        self::assertSame('late', DB::table('StudentSingIn')->where('ClassSessionID', 47308)->value('Status'));

        $other = DB::table('StudentSingIn')->where('ClassSessionID', 47205)->value('id');
        DB::table('StudentSingIn')->where('id', $other)->update(['Memo' => 'edited-by-someone']); // no longer ours: must be skipped
        $rolled = $s->rollback($result['snapshot'], []);
        self::assertSame([3, 1, [(int) $other]], [$rolled['deleted'], $rolled['revoided'], $rolled['skipped_ids']]);
        self::assertSame($original, (array) DB::table('StudentSingIn')->where('id', $original['id'])->first());
        self::assertTrue(DB::table('StudentSingIn')->where('id', $other)->exists());
    }

    public function test_verify_fails_closed_when_a_repaired_lessons_ledger_disappears(): void
    {
        // Codex P1: a lesson whose ledger rows are gone must not pass as `ledger_net_one`.
        $s = new TaughtLessonSignInRepairStrategy();
        $plan = $s->plan($this->params(0, $s->plan($this->params())['digest']));
        $result = $s->execute($plan, []);
        DB::table('session_deduction_ledger')->where('class_session_id', 47205)->delete();
        $verify = $s->verify($plan, $result);
        self::assertFalse($verify['ok']);
        self::assertContains('ledger_net_not_one_47205', $verify['errors']);
    }

    public function test_a_repair_run_is_undone_by_an_approved_restore_request(): void
    {
        // Codex P1: the reachable rollback is a new approved request of this operation naming the repair request (#4736 pattern).
        $service = new PopOperationService(new PopOperationCatalog(dirname(__DIR__, 4) . '/operations/catalog.yaml'));
        $sha = str_repeat('c', 40);
        $go = function (string $restore) use ($service, $sha): array {
            $draft = fn (string $digest): string => (string) $service->createDraft('taught-lesson-signin-repair-20261105', $this->params(9, $digest, $restore),
                'ts-test-' . bin2hex(random_bytes(4)), 'user:1', 'super_admin', [], 1)['id'];
            $digest = (string) $service->runDryRun($draft(''), 'user:1', 1, 'super_admin', [])['plan']['digest'];
            $id = $draft($digest);
            $service->runDryRun($id, 'user:1', 1, 'super_admin', []);
            $token = $service->approve($id, 'founder-go-taught-signin-test-' . substr($id, 0, 8), 'user:2', 'super_admin', $sha, 2, [], 15)['token'];

            return [$id, $service->run($id, 'execute', $token, $sha, 'pop-pi-local')['result'], $service->run($id, 'verify', $token, $sha, 'pop-pi-local')['result']];
        };
        $before = $this->untouched();

        [$repairId, $execute, $verify] = $go('');
        $this->assertSame(['succeeded', 'succeeded', 5], [$execute, $verify, DB::table('StudentSingIn')->where('Memo', 'pop_taught_signin_20261105')->count()]);
        [, $execute, $verify] = $go($repairId);
        $this->assertSame(['succeeded', 'succeeded', 0], [$execute, $verify, DB::table('StudentSingIn')->where('Memo', 'pop_taught_signin_20261105')->count()]);
        $this->assertSame($before, $this->untouched());
        $this->assertTrue(DB::table('security_audit_events')->where('event_type', 'pop.taught_lesson_signin_repair.rollback')->exists());
        $plan = (new TaughtLessonSignInRepairStrategy())->plan($this->params(9, '', $repairId));
        $this->assertSame(array_fill(0, 5, 'skip'), array_column($plan['manifest'], 'class'), 'nothing left to delete or re-void');
        $voided = DB::table('StudentSingIn')->where('ClassSessionID', 47302)->first(); // re-voided exactly as it was
        $this->assertSame(['absent', 'old', '2026-10-18 10:00:00', 30, 'r', 0, 288, '2026-10-18 09:00:00'], [$voided->Status, $voided->Memo, (string) $voided->VoidedAt,
            (int) $voided->VoidedByUserID, $voided->VoidReason, (int) $voided->SessionDeducted, (int) $voided->TeacherID, (string) $voided->MDT]);
        $this->assertSame('restore_source_not_found', (new TaughtLessonSignInRepairStrategy())->plan($this->params(2, '', $repairId))['errors'][0]);
    }

    public function test_a_failed_execute_record_rolls_back_the_repair_writes(): void
    {
        // Codex P1 (#4933): the sign-ins must commit with their execute snapshot, or a crash leaves an unrestorable repair.
        $service = new PopOperationService(new PopOperationCatalog(dirname(__DIR__, 4) . '/operations/catalog.yaml'));
        $sha = str_repeat('d', 40);
        $draft = fn (string $digest): string => (string) $service->createDraft('taught-lesson-signin-repair-20261105', $this->params(9, $digest, ''),
            'ts-test-' . bin2hex(random_bytes(4)), 'user:1', 'super_admin', [], 1)['id'];
        $id = $draft((string) $service->runDryRun($draft(''), 'user:1', 1, 'super_admin', [])['plan']['digest']);
        $service->runDryRun($id, 'user:1', 1, 'super_admin', []);
        $token = $service->approve($id, 'founder-go-taught-signin-test-' . substr($id, 0, 8), 'user:2', 'super_admin', $sha, 2, [], 15)['token'];
        $before = $this->untouched();
        $fault = (object) ['active' => true];
        DB::listen(function ($q) use ($fault) {
            if ($fault->active && preg_match('/^insert (ignore )?into `pop_execution_records`/', $q->sql)) throw new \RuntimeException('synthetic_record_failure');
        });
        $error = null;
        try {
            $service->run($id, 'execute', $token, $sha, 'pop-pi-local');
        } catch (\RuntimeException $e) {
            $error = $e->getMessage();
        } finally {
            $fault->active = false;
        }
        $this->assertSame('synthetic_record_failure', $error);
        $this->assertSame(0, DB::table('StudentSingIn')->where('Memo', 'pop_taught_signin_20261105')->count());
        $this->assertSame($before, $this->untouched());
        $this->assertSame(0, DB::table('pop_execution_records')->where('operation_id', $id)->where('phase', 'execute')->count());
    }

    public function test_execute_refuses_a_drifted_digest(): void
    {
        $s = new TaughtLessonSignInRepairStrategy();
        $plan = $s->plan($this->params(0, $s->plan($this->params())['digest']));
        DB::table('session_deduction_ledger')->insert(['student_class_id' => 4640, 'class_session_id' => 47205, 'event_type' => 'reverse', 'source' => 'retro_leave']);
        try {
            $s->execute($plan, []);
            self::fail('expected digest drift');
        } catch (\RuntimeException $e) {
            self::assertSame('taught_signin_digest_drift', $e->getMessage());
        }
        self::assertSame(2, DB::table('StudentSingIn')->count()); // only the voided fixture rows, still voided
        self::assertNotNull(DB::table('StudentSingIn')->where('ClassSessionID', 47302)->value('VoidedAt'));
    }

    public function test_the_catalog_entry_runs_through_pop_and_requires_the_exact_founder_shape(): void
    {
        $catalog = new PopOperationCatalog(dirname(__DIR__, 4) . '/operations/catalog.yaml');
        $service = new PopOperationService($catalog);
        $run = function (string $digest) use ($service): array {
            $id = (string) $service->createDraft('taught-lesson-signin-repair-20261105', $this->params(9, $digest), 'ts-test-' . bin2hex(random_bytes(4)), 'user:1', 'super_admin', [], 1)['id'];
            $dry = $service->runDryRun($id, 'user:1', 1, 'super_admin', []);
            if ($digest === '') {
                return [$dry];
            }
            $sha = str_repeat('c', 40);
            $token = $service->approve($id, 'founder-go-taught-signin-test', 'user:2', 'super_admin', $sha, 2, [], 15)['token'];

            return [$dry, $service->run($id, 'execute', $token, $sha, 'pop-pi-local'), $service->run($id, 'verify', $token, $sha, 'pop-pi-local')];
        };
        $digest = (string) $run('')[0]['plan']['digest'];
        [, $execute, $verify] = $run($digest);

        $this->assertSame(['succeeded', 'succeeded'], [$execute['result'], $verify['result']]);
        $this->assertSame(5, DB::table('StudentSingIn')->where('Memo', 'pop_taught_signin_20261105')->count());
        $adapter = (string) file_get_contents(dirname(__DIR__, 4) . '/.github/workflows/pop-taught-lesson-signin-repair.yml');
        $this->assertStringContainsString('AUDIT="pop.taught_lesson_signin_repair${RESTORE:+.rollback}"', $adapter);
        $entry = $catalog->operation('taught-lesson-signin-repair-20261105');
        $roles = new ReflectionMethod($service, 'approvalRoles');
        $this->assertSame(['super_admin'], $roles->invoke($service, $entry));
        $entry['blast_radius'] = 'global';
        $this->expectException(RuntimeException::class);
        $roles->invoke($service, $entry);
    }
}
