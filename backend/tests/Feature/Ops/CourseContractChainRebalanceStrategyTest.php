<?php

namespace Tests\Feature\Ops;

use App\Models\AuthToken;
use App\Models\Campus;
use App\Models\Student;
use App\Models\StudentClass;
use App\Models\User;
use App\Operations\PopOperationCatalog;
use App\Operations\PopOperationService;
use App\Operations\Strategies\CourseContractChainRebalanceStrategy;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use RuntimeException;
use Tests\TestCase;

/**
 * GitHub #5124 / #5162 C1, PR 1: the plan (dry-run) half of `course-contract-chain-rebalance`. Synthetic ids only.
 * Acceptance numbers refer to the design on #5124 (issuecomment-6813739987).
 */
final class CourseContractChainRebalanceStrategyTest extends TestCase
{
    use RefreshDatabase;

    private const OPERATION = 'course-contract-chain-rebalance';
    private const SUBJECT = 66;
    private const TABLES = ['StudentClass', 'ClassSession', 'StudentSingIn', 'LearningRecord', 'session_deduction_ledger',
        'class_session_reassignments', 'security_audit_events'];

    private int $teacherId;

    private int $campusId;

    /** Days back for the next taught lesson: every lesson gets its own past date, oldest first. */
    private int $day = 400;

    protected function setUp(): void
    {
        parent::setUp();
        $this->teacherId = (int) User::create(['LoginName' => 'chain-teacher@t.com', 'Name' => 'T', 'PSW' => 's', 'type' => 'T', 'phone' => 911000101])->id;
        $this->campusId = (int) Campus::factory()->create()->id;
    }

    private function strategy(): CourseContractChainRebalanceStrategy
    {
        return new CourseContractChainRebalanceStrategy();
    }

    private function student(?int $campus = null): int
    {
        return (int) Student::create(['name' => 's' . uniqid(), 'CampusID' => $campus ?? $this->campusId, 'ClassID' => 1, 'enable' => 1, 'MDT' => now(), 'Notify_Token' => ''])->id;
    }

    /** A count contract; `$closed` ends it the ordinary way (Stop = 1, closed_reason completed), which is not a settlement lock. */
    private function contract(int $student, int $total = 8, bool $closed = true, int $subject = self::SUBJECT): StudentClass
    {
        $course = StudentClass::create(['StudentID' => $student, 'GradeID' => 1, 'SubjectID' => $subject, 'TeacherID' => $this->teacherId, 'by1' => 1, 'Period' => 4,
            'StartDate' => now()->subDays($this->day + 1)->toDateString(), 'TotalHours' => 0, 'ScheduleMode' => 'count', 'SessionCount' => $total, 'RemainingSessions' => 0,
            'UsedSessions' => 0, 'SessionDuration' => 120, 'ClassType' => 'one_on_one', 'Rate' => 500, 'rate_unit' => 'session', 'Charge' => 0, 'Pay' => 0,
            'Paid' => 0, 'Stop' => 0]);
        if ($closed) {
            DB::table('StudentClass')->where('ID', $course->ID)->update(['Stop' => 1, 'closed_reason' => 'completed']);
        }

        return $course;
    }

    /** Links the contract to a course package (package contracts are outside this operation). */
    private function linkPackage(StudentClass $course, int $student): void
    {
        $package = \App\Models\CoursePackage::create(['student_id' => $student, 'campus_id' => $this->campusId, 'name' => 'p', 'total_sessions' => 6,
            'remaining_sessions' => 6, 'used_sessions' => 0, 'rate' => 500, 'rate_unit' => 'session', 'class_type' => 'one_on_one', 'paid' => false,
            'stop' => false, 'enabled' => true]);
        DB::table('StudentClass')->where('ID', $course->ID)->update(['PackageID' => $package->id]);
    }

    /** A taught lesson (attended + one ledger deduct) on its own past date. */
    private function taught(StudentClass $course, ?string $date = null, string $start = '10:00:00', bool $ledger = true): int
    {
        $id = (int) DB::table('ClassSession')->insertGetId(['StudentClassID' => $course->ID, 'SessionDate' => $date ?? now()->subDays($this->day--)->toDateString(),
            'StartTime' => $start, 'EndTime' => '12:00:00', 'Status' => 'attended', 'Note' => '', 'created_at' => now(), 'updated_at' => now()]);
        if ($ledger) {
            DB::table('session_deduction_ledger')->insert(['student_class_id' => $course->ID, 'event_type' => 'deduct', 'source' => 'attendance',
                'class_session_id' => $id, 'created_at' => now(), 'updated_at' => now()]);
        }

        return $id;
    }

    /** @return list<int> */
    private function taughtMany(StudentClass $course, int $count): array
    {
        $ids = [];
        for ($i = 0; $i < $count; $i++) {
            $ids[] = $this->taught($course);
        }

        return $ids;
    }

    /** A future reservation; 23:00 so it is never "already ended" (Y2), and never today. */
    private function scheduled(StudentClass $course, int $daysAhead): int
    {
        return (int) DB::table('ClassSession')->insertGetId(['StudentClassID' => $course->ID, 'SessionDate' => now()->addDays($daysAhead)->toDateString(),
            'StartTime' => '23:00:00', 'EndTime' => '23:59:00', 'Status' => 'scheduled', 'Note' => '', 'created_at' => now(), 'updated_at' => now()]);
    }

    /** One manifest move, with the lesson's date, time and status as they are in the database now. @return array<string,mixed> */
    private function move(int $sessionId, int $from, int $to): array
    {
        $s = DB::table('ClassSession')->where('id', $sessionId)->first(['SessionDate', 'StartTime', 'Status']);

        return ['session_id' => $sessionId, 'from' => $from, 'to' => $to, 'date' => substr((string) $s->SessionDate, 0, 10),
            'start_time' => substr((string) $s->StartTime, 0, 5), 'status' => strtolower((string) $s->Status)];
    }

    /**
     * @param list<int> $contracts
     * @param list<array<string,mixed>> $moves
     * @param array<string,mixed> $extra
     * @return array<string,mixed>
     */
    private function params(int $student, array $contracts, array $moves, array $extra = []): array
    {
        return $extra + ['campus_id' => $this->campusId, 'student_id' => $student, 'subject_id' => self::SUBJECT, 'contract_ids' => $contracts,
            'moves' => $moves, 'allow_active_contracts' => false, 'allow_order_only_moves' => false, 'future_reservation_policy' => 'block',
            'expected_digest' => '', 'restore_request_id' => '', 'reason' => 'chain_overuse_repair', 'decision_reference' => 'issue-5124-test'];
    }

    /**
     * The shape of the #5124 diagnosis with synthetic ids: five contracts of 8, taught 8 / 8 / 9 / 8 / 1, the last two still
     * active, the last with `$reservations` future lessons. Moves: 1 and 2 swap a lesson between contracts 2 and 3 (no count
     * changes), 3 takes the ninth lesson of contract 3 to contract 4, 4 cascades contract 4's newest lesson into contract 5.
     *
     * @return array{student: int, contracts: list<int>, moves: list<array<string,mixed>>, sessions: list<list<int>>}
     */
    private function workedExample(int $reservations = 6): array
    {
        $student = $this->student();
        $courses = [];
        $sessions = [];
        foreach ([8, 8, 9, 8, 1] as $index => $taught) {
            $courses[$index] = $this->contract($student, 8, $index < 3);
            $sessions[$index] = $this->taughtMany($courses[$index], $taught);
        }
        for ($i = 1; $i <= $reservations; $i++) {
            $this->scheduled($courses[4], $i);
        }
        $ids = array_map(fn (StudentClass $c): int => (int) $c->ID, $courses);
        $moves = [
            $this->move($sessions[2][0], $ids[2], $ids[1]),
            $this->move($sessions[1][7], $ids[1], $ids[2]),
            $this->move($sessions[2][8], $ids[2], $ids[3]),
            $this->move($sessions[3][7], $ids[3], $ids[4]),
        ];

        return ['student' => $student, 'contracts' => $ids, 'moves' => $moves, 'sessions' => $sessions];
    }

    /**
     * Two closed contracts of one student: A bought 8 and has 9 taught, B bought 8 and has 1. The base manifest moves A's
     * newest lesson to B and is accepted; each rejection test changes one thing.
     *
     * @return array{student: int, a: StudentClass, b: StudentClass, extra: int, params: array<string,mixed>}
     */
    private function pair(): array
    {
        $student = $this->student();
        $a = $this->contract($student);
        $aSessions = $this->taughtMany($a, 9);
        $b = $this->contract($student);
        $this->taught($b);
        $extra = $aSessions[8];

        return ['student' => $student, 'a' => $a, 'b' => $b, 'extra' => $extra,
            'params' => $this->params($student, [(int) $a->ID, (int) $b->ID], [$this->move($extra, (int) $a->ID, (int) $b->ID)])];
    }

    /** @param array<string,mixed> $plan @return list<int> */
    private function movesWith(array $plan, string $code): array
    {
        return array_values(array_map(fn (array $f): int => (int) ($f['move'] ?? 0), array_filter($plan['findings'], fn (array $f): bool => $f['code'] === $code)));
    }

    /** @param array<string,mixed> $plan @return list<int> */
    private function taughtAfter(array $plan): array
    {
        return array_map(fn (array $c): int => $c['after']['taught'], $plan['contracts']);
    }

    private function assertRejected(string $code, array $plan): void
    {
        $this->assertFalse($plan['ok'], $code);
        $this->assertContains($code, $plan['errors'], json_encode($plan['errors']));
    }

    /** @param list<string> $skip @return array<string,string> */
    private function checksums(array $skip = []): array
    {
        $out = [];
        foreach (array_diff(self::TABLES, $skip) as $table) {
            $out[$table] = md5(json_encode(DB::table($table)->get(), JSON_THROW_ON_ERROR));
        }

        return $out;
    }

    // ── The worked example (acceptance 3, 6, 7, 9) ──

    public function test_worked_example_passes_only_with_both_founder_decisions(): void
    {
        $x = $this->workedExample();
        $strategy = $this->strategy();

        $plan = $strategy->plan($this->params($x['student'], $x['contracts'], $x['moves']));
        $this->assertFalse($plan['ok']);
        $this->assertEqualsCanonicalizing(['decision_required_active_contract', 'decision_required_order_only'], $plan['errors']);
        $this->assertSame([3, 4], $this->movesWith($plan, 'decision_required_active_contract'), 'moves 3 and 4 touch the active contracts');
        $this->assertSame([1, 2], $this->movesWith($plan, 'decision_required_order_only'), 'moves 1 and 2 only swap');

        $plan = $strategy->plan($this->params($x['student'], $x['contracts'], $x['moves'], ['allow_active_contracts' => true]));
        $this->assertSame(['decision_required_order_only'], $plan['errors']);
        $plan = $strategy->plan($this->params($x['student'], $x['contracts'], $x['moves'], ['allow_order_only_moves' => true]));
        $this->assertSame(['decision_required_active_contract'], $plan['errors']);

        $both = ['allow_active_contracts' => true, 'allow_order_only_moves' => true];
        $plan = $strategy->plan($this->params($x['student'], $x['contracts'], $x['moves'], $both));
        $this->assertTrue($plan['ok'], json_encode($plan['errors']));
        $this->assertSame(['unpinned', 'plan_only'], [$plan['state'], $plan['mode']]);
        $this->assertSame([8, 8, 9, 8, 1], array_map(fn (array $c): int => $c['before']['taught'], $plan['contracts']));
        $this->assertSame([8, 8, 8, 8, 2], $this->taughtAfter($plan));
        $this->assertSame([34, 34], [$plan['totals']['taught_before'], $plan['totals']['taught_after']]);
        $this->assertSame([1, 2], $plan['order_only_moves']);
        $this->assertSame([false, false, false, true, true], array_column($plan['contracts'], 'active'));
        $this->assertSame(6, $plan['contracts'][4]['after']['reserved']);
        $this->assertMatchesRegularExpression('/^[0-9a-f]{64}$/', $plan['digest']);

        // The oldest-first comparison reaches the same counts with two moves and disagrees only on the swapped pair.
        $this->assertFalse($plan['advisor']['executed']);
        $this->assertSame([8, 8, 8, 8, 2], array_values($plan['advisor']['taught_after']));
        $this->assertSame(2, $plan['advisor']['moves_needed']);
        $this->assertEqualsCanonicalizing([$x['sessions'][2][0], $x['sessions'][1][7]], array_column($plan['advisor']['differs_from_manifest'], 'session_id'));

        // Pinned to its own digest.
        $pinned = $strategy->plan($this->params($x['student'], $x['contracts'], $x['moves'], $both + ['expected_digest' => $plan['digest']]));
        $this->assertSame([true, 'pinned', $plan['digest']], [$pinned['ok'], $pinned['state'], $pinned['digest']]);
    }

    public function test_without_the_swap_only_the_active_contract_decision_is_needed_and_the_advisor_agrees(): void
    {
        $x = $this->workedExample();
        $moves = [$x['moves'][2], $x['moves'][3]];

        $this->assertSame(['decision_required_active_contract'], $this->strategy()->plan($this->params($x['student'], $x['contracts'], $moves))['errors']);
        $plan = $this->strategy()->plan($this->params($x['student'], $x['contracts'], $moves, ['allow_active_contracts' => true]));
        $this->assertTrue($plan['ok'], json_encode($plan['errors']));
        $this->assertSame([8, 8, 8, 8, 2], $this->taughtAfter($plan));
        $this->assertSame([], $plan['order_only_moves']);
        $this->assertTrue($plan['advisor']['agrees_with_manifest']);
    }

    /** Acceptance 4: the cascade leaves the newest contract with more reservations than it has left. */
    public function test_future_reservations_beyond_what_is_left_block_or_are_listed(): void
    {
        $x = $this->workedExample(7);
        $both = ['allow_active_contracts' => true, 'allow_order_only_moves' => true];

        $plan = $this->strategy()->plan($this->params($x['student'], $x['contracts'], $x['moves'], $both));
        $this->assertSame(['future_reservations_exceed_remaining'], $plan['errors']);
        $overflow = [['contract_id' => $x['contracts'][4], 'purchased' => 8, 'taught' => 2, 'reserved' => 7]];
        $this->assertSame($overflow, $plan['reservation_overflows']);

        $plan = $this->strategy()->plan($this->params($x['student'], $x['contracts'], $x['moves'], $both + ['future_reservation_policy' => 'report']));
        $this->assertTrue($plan['ok'], json_encode($plan['errors']));
        $this->assertSame($overflow, $plan['reservation_overflows']);
    }

    public function test_a_past_scheduled_lesson_is_reported_as_stale_and_is_not_a_reservation(): void
    {
        $p = $this->pair();
        $stale = (int) DB::table('ClassSession')->insertGetId(['StudentClassID' => $p['a']->ID, 'SessionDate' => now()->subDays(20)->toDateString(),
            'StartTime' => '23:00:00', 'EndTime' => '23:59:00', 'Status' => 'scheduled', 'Note' => '', 'created_at' => now(), 'updated_at' => now()]);

        $plan = $this->strategy()->plan($p['params']);
        $this->assertTrue($plan['ok'], json_encode($plan['errors']));
        $this->assertSame([$stale], $plan['contracts'][0]['stale_scheduled_session_ids']);
        $this->assertSame(0, $plan['contracts'][0]['after']['reserved']);
        $this->assertSame([8, 2], $this->taughtAfter($plan));
    }

    // ── Scope: campus, student, subject, the whole chain (acceptance 1, 15) ──

    public function test_a_student_of_another_campus_is_refused_before_any_contract_or_lesson_is_read(): void
    {
        $p = $this->pair();
        $otherCampus = (int) Campus::factory()->create()->id;

        DB::flushQueryLog();
        DB::enableQueryLog();
        $plan = $this->strategy()->plan(['campus_id' => $otherCampus] + $p['params']);
        $queries = array_column(DB::getQueryLog(), 'query');
        DB::disableQueryLog();

        $this->assertSame([false, ['student_campus_mismatch'], 'rejected'], [$plan['ok'], $plan['errors'], $plan['state']]);
        $this->assertArrayNotHasKey('contracts', $plan);
        $this->assertCount(1, $queries, 'only the student lookup ran');
        foreach ($queries as $sql) {
            $this->assertStringNotContainsString('ClassSession', $sql);
            $this->assertStringNotContainsString('StudentClass', $sql);
        }
        $this->assertRejected('student_campus_mismatch', $this->strategy()->plan(['student_id' => 987654321] + $p['params']));
    }

    public function test_lessons_of_another_campus_student_or_subject_are_refused(): void
    {
        $p = $this->pair();
        $a = (int) $p['a']->ID;
        $b = (int) $p['b']->ID;
        $otherCampusLesson = $this->taught($this->contract($this->student((int) Campus::factory()->create()->id)));
        $otherStudentLesson = $this->taught($this->contract($this->student()));
        $otherSubjectLesson = $this->taught($this->contract($p['student'], 8, true, 77));
        $packageCourse = $this->contract($p['student']);
        $this->linkPackage($packageCourse, $p['student']);
        $packageLesson = $this->taught($packageCourse);

        foreach ([[$otherCampusLesson, 'session_not_found_in_campus'], [$otherStudentLesson, 'session_student_mismatch'],
            [$otherSubjectLesson, 'session_subject_mismatch'], [$packageLesson, 'session_not_in_chain'], [987654321, 'session_not_found_in_campus']] as [$lesson, $code]) {
            $move = ['session_id' => $lesson] + $p['params']['moves'][0];
            $this->assertRejected($code, $this->strategy()->plan(['moves' => [$move]] + $p['params']));
        }

        // A lesson of the chain whose own subject was overridden to another subject.
        DB::table('ClassSession')->where('id', $p['extra'])->update(['SubjectID' => 77]);
        $this->assertRejected('session_subject_mismatch', $this->strategy()->plan($p['params']));
        $this->assertSame([$a, $b], $p['params']['contract_ids']);
    }

    public function test_the_contract_list_must_be_the_whole_chain_of_that_student_and_subject(): void
    {
        $p = $this->pair();
        $a = (int) $p['a']->ID;
        $b = (int) $p['b']->ID;
        $plan = fn (array $contracts): array => $this->strategy()->plan(['contract_ids' => $contracts] + $p['params']);

        $this->assertTrue($this->strategy()->plan($p['params'])['ok']);
        $this->assertRejected('contract_not_owned_by_student', $plan([$a, $b, 987654321]));
        $this->assertRejected('contract_not_owned_by_student', $plan([$a, $b, (int) $this->contract($this->student())->ID]));
        $elsewhere = (int) $this->contract($this->student((int) Campus::factory()->create()->id))->ID;
        $this->assertRejected('contract_not_owned_by_student', $plan([$a, $b, $elsewhere]));
        $this->assertRejected('contract_subject_mismatch', $plan([$a, $b, (int) $this->contract($p['student'], 8, true, 77)->ID]));

        $third = $this->contract($p['student']);
        $missing = $plan([$a, $b]);
        $this->assertRejected('contract_chain_incomplete', $missing);
        $this->assertSame([['code' => 'contract_chain_incomplete', 'contract_id' => (int) $third->ID]], $missing['findings']);
        $this->assertTrue($plan([$a, $b, (int) $third->ID])['ok']);

        $this->linkPackage($third, $p['student']);
        $this->assertTrue($plan([$a, $b])['ok'], 'a package contract is not part of the chain');
        $this->assertRejected('package_contract_unsupported', $plan([$a, $b, (int) $third->ID]));
        DB::table('StudentClass')->where('ID', $third->ID)->update(['PackageID' => null, 'ScheduleMode' => 'date']);
        $this->assertTrue($plan([$a, $b])['ok'], 'a monthly contract is not part of the chain');
        $this->assertRejected('non_count_contract_unsupported', $plan([$a, $b, (int) $third->ID]));
    }

    // ── Drift between the reviewed list and the data (acceptance 2, 3) ──

    public function test_a_move_that_no_longer_matches_the_lesson_is_refused(): void
    {
        $p = $this->pair();
        $a = (int) $p['a']->ID;
        $b = (int) $p['b']->ID;
        $base = $p['params']['moves'][0];
        $plan = fn (array $moves): array => $this->strategy()->plan(['moves' => $moves] + $p['params']);

        $this->assertRejected('move_from_mismatch', $plan([['from' => $b, 'to' => $a] + $base]));
        $this->assertRejected('session_drift', $plan([['date' => now()->subDays(3)->toDateString()] + $base]));
        $this->assertRejected('session_drift', $plan([['start_time' => '11:00'] + $base]));
        $this->assertRejected('session_drift', $plan([['status' => 'completed'] + $base]));
        $this->assertRejected('session_moved_twice', $plan([$base, ['from' => $b, 'to' => $a] + $base]));
        $this->assertRejected('move_noop', $plan([['to' => $a] + $base]));
        $this->assertRejected('move_contract_not_in_chain', $plan([['to' => 987654321] + $base]));

        // Stale `from` after the data changed: the lesson was already moved by someone else.
        DB::table('ClassSession')->where('id', $p['extra'])->update(['StudentClassID' => $b]);
        DB::table('session_deduction_ledger')->where('class_session_id', $p['extra'])->update(['student_class_id' => $b]);
        $this->assertRejected('move_from_mismatch', $plan([$base]));
    }

    public function test_an_unsupported_lesson_status_is_refused(): void
    {
        $p = $this->pair();
        $odd = (int) DB::table('ClassSession')->insertGetId(['StudentClassID' => $p['a']->ID, 'SessionDate' => now()->subDays(11)->toDateString(),
            'StartTime' => '10:00:00', 'EndTime' => '12:00:00', 'Status' => 'rescheduled', 'Note' => '', 'created_at' => now(), 'updated_at' => now()]);

        $plan = $this->strategy()->plan(['moves' => [$this->move($odd, (int) $p['a']->ID, (int) $p['b']->ID)]] + $p['params']);
        $this->assertRejected('session_status_unsupported', $plan);
    }

    public function test_the_result_may_not_leave_any_contract_over_its_bought_count(): void
    {
        $student = $this->student();
        $a = $this->contract($student);
        $extra = $this->taughtMany($a, 9)[8];
        $b = $this->contract($student);
        $this->taughtMany($b, 8);

        $plan = $this->strategy()->plan($this->params($student, [(int) $a->ID, (int) $b->ID], [$this->move($extra, (int) $a->ID, (int) $b->ID)]));
        $this->assertRejected('taught_exceeds_purchased', $plan);
        $this->assertSame([['code' => 'taught_exceeds_purchased', 'contract_id' => (int) $b->ID]], $plan['findings']);
        $this->assertSame([17, 17], [$plan['totals']['taught_before'], $plan['totals']['taught_after']], 'total taught is conserved');
    }

    // ── Money, settlement and lesson length (acceptance 5) ──

    public function test_money_closures_settlement_locks_and_minute_contracts_are_refused(): void
    {
        $cases = [
            'contract_terminal_money_closure' => fn (array $p) => DB::table('StudentClass')->where('ID', $p['b']->ID)->update(['closed_reason' => 'waived']),
            'contract_settlement_locked' => fn (array $p) => DB::table('StudentClass')->where('ID', $p['b']->ID)->update(['closed_reason' => 'usage_settled']),
            'actual_duration_contract_unsupported' => fn (array $p) => DB::table('StudentClass')->where('ID', $p['b']->ID)->update(['deduction_basis' => 'actual_duration']),
            'session_duration_differs' => fn (array $p) => DB::table('StudentClass')->where('ID', $p['b']->ID)->update(['SessionDuration' => 90]),
            'contract_session_count_invalid' => fn (array $p) => DB::table('StudentClass')->where('ID', $p['b']->ID)->update(['SessionCount' => 0]),
            'session_charge_override_present' => fn (array $p) => DB::table('ClassSession')->where('id', $p['extra'])->update(['session_charge' => 900]),
            'partial_minutes_unsupported' => fn (array $p) => DB::table('session_deduction_ledger')->where('class_session_id', $p['extra'])->update(['minutes' => 240]),
        ];
        foreach ($cases as $code => $change) {
            $p = $this->pair();
            $this->assertTrue($this->strategy()->plan($p['params'])['ok'], $code . ' baseline');
            $change($p);
            $this->assertRejected($code, $this->strategy()->plan($p['params']));
        }

        $p = $this->pair();
        DB::table('StudentClass')->where('ID', $p['a']->ID)->update(['settlement_locked_at' => now()]);
        $this->assertRejected('contract_settlement_locked', $this->strategy()->plan($p['params']));
    }

    // ── Mirrors: sign-in, learning record, deduction ledger ──

    public function test_mirrors_that_disagree_with_the_lesson_owner_are_refused(): void
    {
        // A sign-in that points at another contract than its lesson.
        $p = $this->pair();
        DB::table('StudentSingIn')->insert(['StudentClassID' => $p['b']->ID, 'StudentID' => $p['student'], 'TeacherID' => $this->teacherId, 'ClassSessionID' => $p['extra'],
            'SignInDT' => now()->subDays(30), 'SignOutDT' => now()->subDays(30), 'CampusID' => $this->campusId, 'GradeID' => 1, 'SubjectID' => self::SUBJECT,
            'PersonType' => 'student', 'Status' => 'present', 'MDT' => now(), 'SessionDeducted' => 0]);
        $plan = $this->strategy()->plan($p['params']);
        $this->assertRejected('mirror_inconsistent', $plan);
        $this->assertContains(['code' => 'mirror_inconsistent', 'session_id' => $p['extra']], $plan['findings']);

        // A learning record that points at another contract than its lesson.
        $p = $this->pair();
        $row = DB::table('ClassSession')->where('id', $p['extra'])->first(['SessionDate']);
        DB::table('LearningRecord')->insert(['StudentClassID' => $p['b']->ID, 'ClassSessionID' => $p['extra'], 'TeacherID' => $this->teacherId,
            'Content' => 'reviewed fractions and word problems', 'Subject' => 's', 'SessionDate' => substr((string) $row->SessionDate, 0, 10),
            'StartTime' => '10:00:00', 'EndTime' => '12:00:00', 'Status' => 'approved']);
        $this->assertRejected('mirror_inconsistent', $this->strategy()->plan($p['params']));

        // A ledger row that points at another contract than its lesson.
        $p = $this->pair();
        DB::table('session_deduction_ledger')->where('class_session_id', $p['extra'])->update(['student_class_id' => $p['b']->ID]);
        $this->assertRejected('mirror_inconsistent', $this->strategy()->plan($p['params']));

        // A taught lesson with no deduction: moving it would not shift the ledger with the taught count.
        $p = $this->pair();
        DB::table('session_deduction_ledger')->where('class_session_id', $p['extra'])->delete();
        $plan = $this->strategy()->plan($p['params']);
        $this->assertRejected('mirror_inconsistent', $plan);
        $this->assertContains(['code' => 'mirror_inconsistent', 'contract_id' => (int) $p['a']->ID], $plan['findings']);
    }

    /** Codex P2 on #5222: usage that is bound to no lesson stays behind when lessons move, so the projected counts would be wrong. */
    public function test_usage_bound_to_no_lesson_is_refused(): void
    {
        DB::statement('SET FOREIGN_KEY_CHECKS=0'); // legacy rows today's schema no longer accepts (same fixture as ForwardOverdeductReassignStrategyTest)
        try {
            // An approved learning record with no lesson: totals still agree before the move (9 = 9 = 9).
            $p = $this->pair();
            DB::table('LearningRecord')->insert(['StudentClassID' => $p['a']->ID, 'ClassSessionID' => 0, 'TeacherID' => $this->teacherId,
                'Content' => 'reviewed fractions and word problems', 'Subject' => 's', 'SessionDate' => now()->subDays(40)->toDateString(),
                'StartTime' => '10:00:00', 'EndTime' => '12:00:00', 'Status' => 'approved']);
            $plan = $this->strategy()->plan($p['params']);
            $this->assertSame(['unbound_usage'], $plan['errors']);
            $this->assertSame([['code' => 'unbound_usage', 'contract_id' => (int) $p['a']->ID]], $plan['findings']);

            // A live sign-in with no lesson.
            $p = $this->pair();
            DB::table('StudentSingIn')->insert(['StudentClassID' => $p['b']->ID, 'StudentID' => $p['student'], 'TeacherID' => $this->teacherId, 'ClassSessionID' => 0,
                'SignInDT' => now()->subDays(30), 'SignOutDT' => now()->subDays(30), 'CampusID' => $this->campusId, 'GradeID' => 1, 'SubjectID' => self::SUBJECT,
                'PersonType' => 'student', 'Status' => 'present', 'MDT' => now(), 'SessionDeducted' => 0]);
            $this->assertRejected('unbound_usage', $this->strategy()->plan($p['params']));

            // A ledger deduction with no lesson.
            $p = $this->pair();
            DB::table('session_deduction_ledger')->insert(['student_class_id' => $p['a']->ID, 'event_type' => 'deduct', 'source' => 'attendance',
                'class_session_id' => 0, 'created_at' => now(), 'updated_at' => now()]);
            $this->assertRejected('unbound_usage', $this->strategy()->plan($p['params']));
        } finally {
            DB::statement('SET FOREIGN_KEY_CHECKS=1');
        }
    }

    // ── What the reassigner itself would refuse (acceptance 8) ──

    public function test_a_taken_target_slot_and_a_locked_assessment_are_refused(): void
    {
        $p = $this->pair();
        $row = DB::table('ClassSession')->where('id', $p['extra'])->first(['SessionDate']);
        $date = substr((string) $row->SessionDate, 0, 10);
        $clash = $this->taught($p['b'], $date);
        $this->assertRejected('target_slot_taken', $this->strategy()->plan($p['params']));
        DB::table('ClassSession')->where('id', $clash)->update(['Status' => 'cancelled']);
        DB::table('session_deduction_ledger')->where('class_session_id', $clash)->delete();
        $this->assertTrue($this->strategy()->plan($p['params'])['ok'], 'a cancelled lesson frees the slot');

        // A teacher-filled assessment by a substitute: the lesson could not be moved back, so it is not moved.
        $p = $this->pair();
        $row = DB::table('ClassSession')->where('id', $p['extra'])->first(['SessionDate']);
        $substitute = (int) User::create(['LoginName' => 'chain-sub@t.com', 'Name' => 'S', 'PSW' => 's', 'type' => 'T', 'phone' => 911000102])->id;
        DB::table('LearningRecord')->insert(['StudentClassID' => $p['a']->ID, 'ClassSessionID' => $p['extra'], 'TeacherID' => $substitute,
            'Content' => 'reviewed fractions and word problems', 'Subject' => 's', 'SessionDate' => substr((string) $row->SessionDate, 0, 10),
            'StartTime' => '10:00:00', 'EndTime' => '12:00:00', 'Status' => 'approved']);
        $this->assertSame(['assessment_locked'], $this->strategy()->plan($p['params'])['errors']);
    }

    // ── Manifest shape ──

    public function test_a_malformed_manifest_is_refused_without_reading_anything(): void
    {
        $p = $this->pair();
        $cases = [
            'campus_id_invalid' => ['campus_id' => 0],
            'student_id_invalid' => ['student_id' => 'abc'],
            'subject_id_invalid' => ['subject_id' => -1],
            'contract_ids_invalid' => ['contract_ids' => [(int) $p['a']->ID, (int) $p['a']->ID]],
            'moves_invalid' => ['moves' => [['session_id' => $p['extra'], 'from' => (int) $p['a']->ID, 'to' => (int) $p['b']->ID]]],
            'flag_invalid' => ['allow_active_contracts' => 'yes'],
            'future_reservation_policy_invalid' => ['future_reservation_policy' => 'ignore'],
            'digest_format' => ['expected_digest' => 'not-a-digest'],
            'restore_not_available_plan_only' => ['restore_request_id' => '0f8fad5b-d9cb-469f-a165-70867728950e'],
            'reason_invalid' => ['reason' => 'Free text reason'],
            'decision_reference_invalid' => ['decision_reference' => ''],
        ];
        foreach ($cases as $code => $change) {
            DB::flushQueryLog();
            DB::enableQueryLog();
            $plan = $this->strategy()->plan($change + $p['params']);
            $queries = DB::getQueryLog();
            DB::disableQueryLog();
            $this->assertSame([false, [$code], 'invalid'], [$plan['ok'], $plan['errors'], $plan['state']], $code);
            $this->assertSame([], $queries, $code . ' ran a query');
        }
        $this->assertRejected('moves_invalid', $this->strategy()->plan(['moves' => []] + $p['params']));

        // Absent flags mean "no": the plan is the same as with both set to false.
        $x = $this->workedExample();
        $params = $this->params($x['student'], $x['contracts'], $x['moves']);
        unset($params['allow_active_contracts'], $params['allow_order_only_moves'], $params['future_reservation_policy']);
        $this->assertEqualsCanonicalizing(['decision_required_active_contract', 'decision_required_order_only'], $this->strategy()->plan($params)['errors']);
    }

    // ── Digest: pins the manifest and the observed state (replay of a stale plan) ──

    public function test_the_digest_follows_the_manifest_and_the_observed_state(): void
    {
        $p = $this->pair();
        $first = $this->strategy()->plan($p['params']);
        $this->assertSame($first['digest'], $this->strategy()->plan($p['params'])['digest'], 'same input, same state, same digest');
        $this->assertNotSame($first['digest'], $this->strategy()->plan(['decision_reference' => 'issue-5124-other'] + $p['params'])['digest']);
        $this->assertNotSame($first['digest'], $this->strategy()->plan(['future_reservation_policy' => 'report'] + $p['params'])['digest']);

        // State changes between the reviewed plan and the re-plan: another lesson is added to the target contract.
        $this->taught($p['b']);
        $pinned = $this->strategy()->plan(['expected_digest' => $first['digest']] + $p['params']);
        $this->assertSame([false, ['digest_mismatch'], 'digest_mismatch'], [$pinned['ok'], $pinned['errors'], $pinned['state']]);
        $this->assertNotSame($first['digest'], $pinned['digest']);

        // A change that keeps every count (the bought count of the source) still changes the digest.
        $second = $this->strategy()->plan($p['params'])['digest'];
        DB::table('StudentClass')->where('ID', $p['a']->ID)->update(['SessionCount' => 9]);
        $this->assertNotSame($second, $this->strategy()->plan($p['params'])['digest']);
    }

    // ── Read-only, plan-only ──

    public function test_plan_only_reads(): void
    {
        $x = $this->workedExample(7);
        $before = $this->checksums();

        DB::flushQueryLog();
        DB::enableQueryLog();
        $ok = $this->strategy()->plan($this->params($x['student'], $x['contracts'], $x['moves'],
            ['allow_active_contracts' => true, 'allow_order_only_moves' => true, 'future_reservation_policy' => 'report']));
        $rejected = $this->strategy()->plan($this->params($x['student'], $x['contracts'], $x['moves']));
        $queries = array_column(DB::getQueryLog(), 'query');
        DB::disableQueryLog();

        $this->assertTrue($ok['ok'], json_encode($ok['errors']));
        $this->assertFalse($rejected['ok']);
        $this->assertNotEmpty($queries);
        foreach ($queries as $sql) {
            $this->assertMatchesRegularExpression('/^\s*select\b/i', $sql, 'plan ran a non-select statement');
            $this->assertStringNotContainsStringIgnoringCase('for update', $sql);
        }
        $this->assertSame($before, $this->checksums(), 'no row changed');
    }

    public function test_execute_verify_and_rollback_refuse_and_write_nothing(): void
    {
        $p = $this->pair();
        $strategy = $this->strategy();
        $plan = $strategy->plan($p['params']);
        $pinned = $strategy->plan(['expected_digest' => $plan['digest']] + $p['params']);
        $this->assertSame('pinned', $pinned['state']);
        $before = $this->checksums();

        foreach ([fn () => $strategy->execute($pinned, ['actor' => 'pop-pi-local', 'operation_id' => 'x']), fn () => $strategy->rollback(['rows' => []], [])] as $call) {
            try {
                $call();
                $this->fail('a planned-only strategy must refuse');
            } catch (RuntimeException $e) {
                $this->assertSame('strategy_planned_only', $e->getMessage());
            }
        }
        $this->assertSame(['ok' => false, 'errors' => ['strategy_planned_only'], 'checks' => []], $strategy->verify($pinned, ['ok' => true]));
        $this->assertSame($before, $this->checksums());
        $this->assertSame((int) $p['a']->ID, (int) DB::table('ClassSession')->where('id', $p['extra'])->value('StudentClassID'));
    }

    // ── Catalog and POP control plane ──

    public function test_the_catalog_entry_is_planned_and_pop_dry_runs_it_but_never_approves_it(): void
    {
        $catalog = new PopOperationCatalog(dirname(__DIR__, 4) . '/operations/catalog.yaml');
        $entry = $catalog->operation(self::OPERATION);
        $this->assertSame('planned', $entry['lifecycle']);
        $this->assertSame(CourseContractChainRebalanceStrategy::class, $entry['strategy_class']);
        $this->assertSame(['plan', 'dry_run'], $entry['capabilities']);
        $this->assertSame(['super_admin'], $entry['approver_roles']);
        $this->assertTrue($entry['founder_approval_required']);
        $this->assertSame(['campus_id', 'student_id', 'subject_id', 'contract_ids', 'moves', 'allow_active_contracts', 'allow_order_only_moves',
            'future_reservation_policy', 'expected_digest', 'restore_request_id', 'reason', 'decision_reference'], $entry['parameter_keys']);

        $p = $this->pair();
        $service = new PopOperationService($catalog);
        $before = $this->checksums(['security_audit_events']); // POP records its own dry-run audit event
        $id = (string) $service->createDraft(self::OPERATION, $p['params'], 'chain-test-' . bin2hex(random_bytes(4)), 'user:1', 'super_admin', [], 1)['id'];
        $dryRun = $service->runDryRun($id, 'user:1', 1, 'super_admin', []);
        $this->assertTrue($dryRun['plan']['ok'], json_encode($dryRun['plan']['errors'] ?? null));
        $this->assertSame(['unpinned', [8, 2]], [$dryRun['plan']['state'], $this->taughtAfter($dryRun['plan'])]);
        $this->assertSame($before, $this->checksums(['security_audit_events']), 'the POP dry-run changed no lesson, contract or mirror row');

        // A manifest that omits a catalog key never becomes a request.
        $partial = $p['params'];
        unset($partial['allow_active_contracts']);
        try {
            $service->createDraft(self::OPERATION, $partial, 'chain-test-' . bin2hex(random_bytes(4)), 'user:1', 'super_admin', [], 1);
            $this->fail('a partial parameter set must be refused');
        } catch (RuntimeException $e) {
            $this->assertSame('POP parameters do not exactly match the catalog contract.', $e->getMessage());
        }

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('POP operation is not active; Founder activation is required.');
        $service->approve($id, 'founder-go-chain-rebalance-test-' . substr($id, 0, 8), 'user:2', 'super_admin', str_repeat('c', 40), 2, [], 15);
    }

    public function test_a_director_of_another_campus_and_a_teacher_cannot_plan(): void
    {
        $p = $this->pair();
        $service = new PopOperationService(new PopOperationCatalog(dirname(__DIR__, 4) . '/operations/catalog.yaml'));
        $otherCampus = (int) Campus::factory()->create()->id;

        foreach ([['director', [$otherCampus]], ['director', []], ['teacher', [$otherCampus]]] as [$role, $campuses]) {
            try {
                $service->createDraft(self::OPERATION, $p['params'], 'chain-test-' . bin2hex(random_bytes(4)), 'user:7', $role, $campuses, 7);
                $this->fail($role . ' outside the campus must be refused');
            } catch (RuntimeException $e) {
                $this->assertSame('POP campus is outside the actor scope.', $e->getMessage());
            }
        }
        // The director's own campus in the manifest does not help: the student belongs to another campus.
        $id = (string) $service->createDraft(self::OPERATION, ['campus_id' => $otherCampus] + $p['params'], 'chain-test-' . bin2hex(random_bytes(4)),
            'user:7', 'director', [$otherCampus], 7)['id'];
        $dryRun = $service->runDryRun($id, 'user:7', 7, 'director', [$otherCampus]);
        $this->assertSame([false, ['student_campus_mismatch']], [$dryRun['plan']['ok'], $dryRun['plan']['errors']]);
        $this->assertSame(0, DB::table('pop_operation_requests')->where('operation_id', self::OPERATION)->where('id', '!=', $id)->count());

        // The control-plane routes admit directors and super_admins only.
        $teacher = User::create(['LoginName' => 'chain-http-teacher@test.com', 'Name' => 'Chain Teacher', 'PSW' => bcrypt('pw'), 'type' => 'T', 'status' => 'active']);
        $token = bin2hex(random_bytes(16));
        AuthToken::create(['user_id' => $teacher->id, 'token' => $token, 'expires_at' => now()->addDay()]);
        $this->withToken($token)->postJson('/api/v1/pop/operations/' . self::OPERATION . '/draft',
            ['parameters' => $p['params'], 'idempotency_key' => 'chain-test-http-teacher'])->assertForbidden();
        $this->postJson('/api/v1/pop/operations/' . self::OPERATION . '/draft',
            ['parameters' => $p['params'], 'idempotency_key' => 'chain-test-http-anon'], ['Authorization' => 'Bearer invalid-fixture-token'])->assertUnauthorized();
        $this->assertSame(1, DB::table('pop_operation_requests')->where('operation_id', self::OPERATION)->count());
    }
}
