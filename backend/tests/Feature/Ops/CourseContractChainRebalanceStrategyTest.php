<?php

namespace Tests\Feature\Ops;

use App\Models\AuthToken;
use App\Models\Campus;
use App\Models\ClassSessionReassignment;
use App\Models\Student;
use App\Models\StudentClass;
use App\Models\User;
use App\Operations\Inapp\InappStandingPolicy;
use App\Operations\PopOperationCatalog;
use App\Operations\PopOperationService;
use App\Operations\Strategies\CourseContractChainRebalanceStrategy;
use App\Services\SessionDeductionService;
use Illuminate\Database\Events\TransactionRolledBack;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Str;
use ReflectionMethod;
use RuntimeException;
use Tests\TestCase;

/**
 * GitHub #5124 / #5162 C1: `course-contract-chain-rebalance`, plan (dry-run) first, then execute, verify and restore.
 * Synthetic ids only. Acceptance numbers refer to the design on #5124 (issuecomment-6813739987).
 */
final class CourseContractChainRebalanceStrategyTest extends TestCase
{
    use RefreshDatabase;

    private const OPERATION = 'course-contract-chain-rebalance';
    private const SUBJECT = 66;
    private const AUDIT = 'pop.course_contract_chain_rebalance';
    /** Tables a move may touch, plus the append-only evidence tables. */
    private const TABLES = ['StudentClass', 'ClassSession', 'StudentSingIn', 'LearningRecord', 'session_deduction_ledger',
        'class_session_reassignments', 'security_audit_events'];
    private const MONEY_TABLES = ['Invoice', 'InvoiceItem', 'Payment', 'payment_reports'];
    /** Contract columns that are not money or terms: the counters the reassigner recomputes. */
    private const COUNTERS = ['UsedSessions', 'RemainingSessions', 'PurchasedMinutes', 'RemainingMinutes'];

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
        $this->derive((int) $course->ID);
        if ($closed) {
            DB::table('StudentClass')->where('ID', $course->ID)->update(['Stop' => 1, 'closed_reason' => 'completed']);
        }

        return $course;
    }

    /** Stored counters as the app keeps them: recomputed from the lessons (the plan refuses a chain whose counters are not). */
    private function derive(int ...$contractIds): void
    {
        foreach ($contractIds as $id) {
            SessionDeductionService::recomputeCounters($id);
        }
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
        $this->derive(...$ids);
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
        $this->derive((int) $a->ID, (int) $b->ID);
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
        $this->assertSame(['unpinned', 'rebalance'], [$plan['state'], $plan['mode']]);
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

        // #5236 review P2a: `report` would let the execute leave an active contract with more taught + reserved lessons than
        // it bought. With an active contract on the chain (or the cascade decision given) the policy must be `block`.
        $plan = $this->strategy()->plan($this->params($x['student'], $x['contracts'], $x['moves'], $both + ['future_reservation_policy' => 'report']));
        $this->assertSame(['report_policy_requires_no_active_contract'], $plan['errors']);
        $this->assertSame([['code' => 'report_policy_requires_no_active_contract', 'contract_id' => $x['contracts'][3]],
            ['code' => 'report_policy_requires_no_active_contract', 'contract_id' => $x['contracts'][4]]], $plan['findings']);
        $this->assertSame($overflow, $plan['reservation_overflows']);
        // Also without any overflow, and also with the active contracts untouched by the moves (only the swap, moves 1 and 2).
        $x = $this->workedExample();
        $this->assertSame(['report_policy_requires_no_active_contract'],
            $this->strategy()->plan($this->params($x['student'], $x['contracts'], $x['moves'], $both + ['future_reservation_policy' => 'report']))['errors']);
        // The swap alone leaves the third contract at 9 taught of 8, which is refused under either policy; `report` adds
        // the policy code although no move touches an active contract.
        $swap = fn (string $policy): array => $this->strategy()->plan($this->params($x['student'], $x['contracts'],
            [$x['moves'][0], $x['moves'][1]], ['allow_order_only_moves' => true, 'future_reservation_policy' => $policy]))['errors'];
        $this->assertSame(['taught_exceeds_purchased'], $swap('block'));
        $this->assertSame(['report_policy_requires_no_active_contract', 'taught_exceeds_purchased'], $swap('report'));

        // A chain with no active contract: `report` lists the overflow and passes, `block` refuses it.
        $p = $this->endedPairWithReservations();
        $overflow = [['contract_id' => (int) $p['b']->ID, 'purchased' => 8, 'taught' => 2, 'reserved' => 7]];
        $this->assertSame(['future_reservations_exceed_remaining'], $this->strategy()->plan($p['params'])['errors']);
        $plan = $this->strategy()->plan(['future_reservation_policy' => 'report'] + $p['params']);
        $this->assertTrue($plan['ok'], json_encode($plan['errors']));
        $this->assertSame($overflow, $plan['reservation_overflows']);
        // The cascade decision and `report` never go together, even when no contract is active now.
        $this->assertSame(['report_policy_requires_no_active_contract'],
            $this->strategy()->plan(['future_reservation_policy' => 'report', 'allow_active_contracts' => true] + $p['params'])['errors']);
    }

    /**
     * `pair()` with seven future lessons on the ended target contract: after the move it holds 2 taught + 7 reserved of 8.
     *
     * @return array{student: int, a: StudentClass, b: StudentClass, extra: int, params: array<string,mixed>}
     */
    private function endedPairWithReservations(): array
    {
        $p = $this->pair();
        for ($i = 1; $i <= 7; $i++) {
            $this->scheduled($p['b'], $i);
        }

        return $p;
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
        $this->derive((int) $a->ID, (int) $b->ID);

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

    /** Codex P2 on #5222 (2nd): usage on a lesson that is not taught counts toward its contract but would not follow the plan's taught count. */
    public function test_usage_on_a_lesson_that_is_not_taught_is_refused(): void
    {
        $pastScheduled = function (array $p): int {
            return (int) DB::table('ClassSession')->insertGetId(['StudentClassID' => $p['a']->ID, 'SessionDate' => now()->subDays(15)->toDateString(),
                'StartTime' => '23:00:00', 'EndTime' => '23:59:00', 'Status' => 'scheduled', 'Note' => '', 'created_at' => now(), 'updated_at' => now()]);
        };

        // A deducted sign-in on a past scheduled lesson: totals still agree before the move (max(1, 9) = 9 = 9).
        $p = $this->pair();
        $lesson = $pastScheduled($p);
        $this->assertTrue($this->strategy()->plan($p['params'])['ok'], 'a past scheduled lesson alone is only stale');
        DB::table('StudentSingIn')->insert(['StudentClassID' => $p['a']->ID, 'StudentID' => $p['student'], 'TeacherID' => $this->teacherId, 'ClassSessionID' => $lesson,
            'SignInDT' => now()->subDays(15), 'SignOutDT' => now()->subDays(15), 'CampusID' => $this->campusId, 'GradeID' => 1, 'SubjectID' => self::SUBJECT,
            'PersonType' => 'student', 'Status' => 'present', 'MDT' => now(), 'SessionDeducted' => 1]);
        $plan = $this->strategy()->plan($p['params']);
        $this->assertSame(['mirror_inconsistent'], $plan['errors']);
        $this->assertSame([['code' => 'mirror_inconsistent', 'session_id' => $lesson]], $plan['findings']);

        // A ledger deduction on a past scheduled lesson, on a lesson that is not moved.
        $p = $this->pair();
        $lesson = $pastScheduled($p);
        DB::table('session_deduction_ledger')->insert(['student_class_id' => $p['a']->ID, 'event_type' => 'deduct', 'source' => 'attendance',
            'class_session_id' => $lesson, 'created_at' => now(), 'updated_at' => now()]);
        $this->assertContains(['code' => 'mirror_inconsistent', 'session_id' => $lesson], $this->strategy()->plan($p['params'])['findings']);
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
            'restore_request_id_format' => ['restore_request_id' => 'not-a-request-id'],
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
        $this->derive((int) $p['b']->ID);
        $pinned = $this->strategy()->plan(['expected_digest' => $first['digest']] + $p['params']);
        $this->assertSame([false, ['digest_mismatch'], 'digest_mismatch'], [$pinned['ok'], $pinned['errors'], $pinned['state']]);
        $this->assertNotSame($first['digest'], $pinned['digest']);

        // A change that keeps every count (the bought count of the source) still changes the digest.
        $second = $this->strategy()->plan($p['params'])['digest'];
        DB::table('StudentClass')->where('ID', $p['a']->ID)->update(['SessionCount' => 9]);
        $this->assertNotSame($second, $this->strategy()->plan($p['params'])['digest']);
    }

    // ── Plan only reads ──

    public function test_plan_only_reads(): void
    {
        $x = $this->workedExample();
        $before = $this->checksums();

        DB::flushQueryLog();
        DB::enableQueryLog();
        $ok = $this->strategy()->plan($this->params($x['student'], $x['contracts'], $x['moves'],
            ['allow_active_contracts' => true, 'allow_order_only_moves' => true]));
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

    // ── Catalog and POP control plane ──

    public function test_the_pop_dry_run_reads_only_and_a_partial_manifest_never_becomes_a_request(): void
    {
        $catalog = new PopOperationCatalog(dirname(__DIR__, 4) . '/operations/catalog.yaml');
        $entry = $catalog->operation(self::OPERATION);
        $this->assertSame(CourseContractChainRebalanceStrategy::class, $entry['strategy_class']);
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
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('POP parameters do not exactly match the catalog contract.');
        $service->createDraft(self::OPERATION, $partial, 'chain-test-' . bin2hex(random_bytes(4)), 'user:1', 'super_admin', [], 1);
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

    // ══ Execute, verify, restore (acceptance 10 to 15) ══

    /** @return array<string,mixed> */
    private function context(?string $requestId = null): array
    {
        return ['actor' => 'pop-pi-local', 'operation_id' => $requestId ?? (string) Str::uuid()];
    }

    /** The plan pinned to its own digest, as POP hands it to execute. @param array<string,mixed> $params @return array<string,mixed> */
    private function pinned(array $params): array
    {
        $plan = $this->strategy()->plan($params);
        $this->assertTrue($plan['ok'], json_encode($plan['errors']));
        $pinned = $this->strategy()->plan(['expected_digest' => $plan['digest']] + $params);
        $this->assertSame('pinned', $pinned['state']);

        return $pinned;
    }

    /**
     * The worked example with the decided flags: cascade into the active contract yes, order-only swaps no, so only moves
     * 3 and 4 remain. Counters are derived first, as they are on a contract the app has maintained.
     *
     * @return array{student: int, contracts: list<int>, moves: list<array<string,mixed>>, sessions: list<list<int>>, params: array<string,mixed>}
     */
    private function decided(int $reservations = 6, string $policy = 'block'): array
    {
        $x = $this->workedExample($reservations);
        foreach ($x['contracts'] as $id) {
            SessionDeductionService::recomputeCounters($id);
        }
        $x['moves'] = [$x['moves'][2], $x['moves'][3]];
        $x['params'] = $this->params($x['student'], $x['contracts'], $x['moves'], ['allow_active_contracts' => true, 'future_reservation_policy' => $policy]);

        return $x;
    }

    /** A sign-in and a learning record on the lesson, owned by its contract (the mirrors that must follow a move). */
    private function mirrors(int $sessionId, int $contractId, int $student): void
    {
        $row = DB::table('ClassSession')->where('id', $sessionId)->first(['SessionDate']);
        DB::table('StudentSingIn')->insert(['StudentClassID' => $contractId, 'StudentID' => $student, 'TeacherID' => $this->teacherId, 'ClassSessionID' => $sessionId,
            'SignInDT' => $row->SessionDate, 'SignOutDT' => $row->SessionDate, 'CampusID' => $this->campusId, 'GradeID' => 1, 'SubjectID' => self::SUBJECT,
            'PersonType' => 'student', 'Status' => 'present', 'MDT' => now(), 'SessionDeducted' => 1]);
        DB::table('LearningRecord')->insert(['StudentClassID' => $contractId, 'ClassSessionID' => $sessionId, 'TeacherID' => $this->teacherId,
            'Content' => 'reviewed fractions and word problems', 'Subject' => 's', 'SessionDate' => substr((string) $row->SessionDate, 0, 10),
            'StartTime' => '10:00:00', 'EndTime' => '12:00:00', 'Status' => 'approved']);
    }

    /** An invoice with one item and one payment on the contract. */
    private function invoice(int $contractId, int $student): void
    {
        $invoice = (int) DB::table('Invoice')->insertGetId(['StudentID' => $student, 'StudentClassID' => $contractId, 'IssueDate' => '2026-01-05',
            'TotalAmount' => 4000, 'PaidAmount' => 4000, 'Status' => 'paid', 'created_at' => now(), 'updated_at' => now()]);
        DB::table('InvoiceItem')->insert(['InvoiceID' => $invoice, 'StudentClassID' => $contractId, 'Amount' => 4000, 'Description' => 'x',
            'created_at' => now(), 'updated_at' => now()]);
        DB::table('Payment')->insert(['InvoiceID' => $invoice, 'Amount' => 4000, 'Method' => 'cash', 'PaidAt' => now(), 'created_at' => now(), 'updated_at' => now()]);
    }

    /**
     * Row count and checksum of every money table, and of every contract column except the counters (so Charge, Pay, Paid,
     * PayDate, Rate, SessionCount, the dates, Stop and closed_reason are all inside).
     *
     * @return array<string,array{0:int,1:string}>
     */
    private function money(): array
    {
        $out = [];
        foreach (self::MONEY_TABLES as $table) {
            $rows = DB::table($table)->orderBy('id')->get();
            $out[$table] = [$rows->count(), md5(json_encode($rows, JSON_THROW_ON_ERROR))];
        }
        $contracts = DB::table('StudentClass')->orderBy('ID')->get()->map(fn (\stdClass $c): array => array_diff_key((array) $c, array_flip(self::COUNTERS)));
        $out['StudentClass'] = [$contracts->count(), md5(json_encode($contracts, JSON_THROW_ON_ERROR))];

        return $out;
    }

    /**
     * Ownership and content of everything a move touches, without the columns a move is allowed to stamp
     * (ClassSession.updated_at). Equal before an execute and after its restore.
     */
    private function ownership(): string
    {
        $lessons = DB::table('ClassSession')->orderBy('id')->get()->map(function (\stdClass $s): array {
            $row = (array) $s;
            unset($row['updated_at']);

            return $row;
        });

        return md5(json_encode([$lessons, DB::table('StudentClass')->orderBy('ID')->get(), DB::table('StudentSingIn')->orderBy('id')->get(),
            DB::table('LearningRecord')->orderBy('id')->get(), DB::table('session_deduction_ledger')->orderBy('id')->get()], JSON_THROW_ON_ERROR));
    }

    /** @param list<int> $sessionIds @return list<int> */
    private function owners(array $sessionIds): array
    {
        return array_map(fn (int $id): int => (int) DB::table('ClassSession')->where('id', $id)->value('StudentClassID'), $sessionIds);
    }

    /** @param list<int> $contracts @return list<int> taught lessons per contract, counted from the lessons themselves */
    private function taughtNow(array $contracts): array
    {
        return array_map(fn (int $id): int => DB::table('ClassSession')->where('StudentClassID', $id)->whereIn('Status', ['attended', 'completed', 'late'])->count(), $contracts);
    }

    private function service(): PopOperationService
    {
        return new PopOperationService(new PopOperationCatalog(dirname(__DIR__, 4) . '/operations/catalog.yaml'));
    }

    /**
     * The POP path of one request: draft, dry-run, a second draft pinned to the digest, dry-run, Founder approval by another
     * user, then the given phases. @param array<string,mixed> $params @param list<string> $phases @return array<string,mixed>
     */
    private function viaPop(PopOperationService $service, array $params, array $phases = ['execute', 'verify']): array
    {
        $sha = str_repeat('c', 40);
        $draft = fn (string $digest): string => (string) $service->createDraft(self::OPERATION, ['expected_digest' => $digest] + $params,
            'chain-exec-' . bin2hex(random_bytes(4)), 'user:1', 'super_admin', [], 1)['id'];
        $first = $service->runDryRun($draft(''), 'user:1', 1, 'super_admin', [])['plan'];
        $this->assertTrue($first['ok'], json_encode($first['errors'] ?? null));
        $id = $draft((string) $first['digest']);
        $this->assertSame('pinned', $service->runDryRun($id, 'user:1', 1, 'super_admin', [])['plan']['state']);
        $token = $service->approve($id, 'founder-go-chain-rebalance-' . substr($id, 0, 8), 'user:2', 'super_admin', $sha, 2, [], 15)['token'];
        $out = ['id' => $id, 'token' => $token, 'sha' => $sha];
        foreach ($phases as $phase) {
            $out[$phase] = $service->run($id, $phase, $token, $sha, 'pop-pi-local');
        }

        return $out;
    }

    // ── Execute and verify (acceptance 10, 11, 12) ──

    public function test_the_worked_example_executes_verifies_and_leaves_money_and_lessons_as_they_were(): void
    {
        $x = $this->decided();
        [$ninth, $cascade] = [$x['sessions'][2][8], $x['sessions'][3][7]];
        $this->mirrors($ninth, $x['contracts'][2], $x['student']);
        $this->invoice($x['contracts'][2], $x['student']);
        $this->invoice($x['contracts'][4], $x['student']);
        $pinned = $this->pinned($x['params']);
        $this->assertSame([8, 8, 9, 8, 1], $this->taughtNow($x['contracts']));
        $money = $this->money();
        $lessons = DB::table('ClassSession')->orderBy('id')->get()->map(fn (\stdClass $s): array => array_diff_key((array) $s, ['StudentClassID' => 1, 'updated_at' => 1]))->all();
        $context = $this->context();

        $result = $this->strategy()->execute($pinned, $context);

        $this->assertTrue($result['ok'], json_encode($result['errors'] ?? null));
        $this->assertSame(['rebalance', 2, $pinned['digest'], $context['operation_id']], [$result['mode'], $result['moved'], $result['digest'], $result['request_id']]);
        $this->assertSame([$x['contracts'][3], $x['contracts'][4]], $this->owners([$ninth, $cascade]));
        $this->assertSame([8, 8, 8, 8, 2], $this->taughtNow($x['contracts']));
        // What the plan promised as "after" is what execute reports and what verify counts again.
        $this->assertSame(array_column($pinned['contracts'], 'after'), array_column($result['contracts'], 'after'));
        $this->assertSame([8, 8, 9, 8, 1], array_map(fn (array $c): int => $c['before']['taught'], $result['contracts']));

        // Mirrors follow the lesson.
        foreach ([['StudentSingIn', 'ClassSessionID', 'StudentClassID'], ['LearningRecord', 'ClassSessionID', 'StudentClassID'],
            ['session_deduction_ledger', 'class_session_id', 'student_class_id']] as [$table, $sessionColumn, $ownerColumn]) {
            $this->assertSame([$x['contracts'][3]], DB::table($table)->where($sessionColumn, $ninth)->pluck($ownerColumn)->map(fn ($id): int => (int) $id)->all(), $table);
        }
        // Counters of the contracts a move touched are the derived values.
        $counters = fn (int $id): array => array_map('intval', (array) DB::table('StudentClass')->where('ID', $id)->first(['UsedSessions', 'RemainingSessions']));
        $this->assertSame([[8, 0], [8, 0], [2, 6]], array_map(fn (int $i): array => array_values($counters($x['contracts'][$i])), [2, 3, 4]));

        // No money write: row counts and checksums of the money tables and of every non-counter contract column are unchanged.
        $this->assertSame($money, $this->money());
        // Date, time, teacher, status and every other lesson column are byte-identical; only the owner moved.
        $this->assertSame($lessons, DB::table('ClassSession')->orderBy('id')->get()->map(fn (\stdClass $s): array => array_diff_key((array) $s, ['StudentClassID' => 1, 'updated_at' => 1]))->all());
        $this->assertSame(6, DB::table('ClassSession')->where('StudentClassID', $x['contracts'][4])->where('Status', 'scheduled')->count(), 'no reservation was cancelled');

        // Snapshot: the exact prior owner of every moved lesson, in the order moved.
        $snapshot = $result['snapshot'];
        $this->assertSame([1, 'rebalance', $context['operation_id'], $pinned['digest']], [$snapshot['version'], $snapshot['mode'], $snapshot['request_id'], $snapshot['digest']]);
        $this->assertSame([[$ninth, $x['contracts'][2], $x['contracts'][3]], [$cascade, $x['contracts'][3], $x['contracts'][4]]],
            array_map(fn (array $r): array => [$r['id'], $r['from_class_id'], $r['to_class_id']], $snapshot['rows']));
        $this->assertSame(['StudentSingIn' => 1, 'LearningRecord' => 1, 'session_deduction_ledger' => 1], $snapshot['rows'][0]['mirrors']);
        $this->assertSame([8, 0], [$snapshot['contracts'][2]['before']['used_sessions'], $snapshot['contracts'][2]['before']['remaining_sessions']]);
        $this->assertSame([], $snapshot['released_reservations']);
        $this->assertMatchesRegularExpression('/^[0-9a-f]{64}$/', $snapshot['money_hash']);

        // Audit trail: one reassignment row and one event per move, one event for the request; ids, a reason code and counts only.
        $this->assertSame(2, DB::table('class_session_reassignments')->where('reason', $snapshot['reason'])->count());
        $this->assertSame(2, DB::table('security_audit_events')->where('event_type', 'class_session.contract_reassigned')->count());
        $event = DB::table('security_audit_events')->where('event_type', self::AUDIT)->get();
        $this->assertCount(1, $event);
        $metadata = json_decode((string) $event[0]->metadata, true);
        $this->assertSame(['chain_overuse_repair', $context['operation_id'], 2, 'digest:' . $pinned['digest'], 'success'],
            [$metadata['reason_code'], $metadata['pop_operation_id'], $metadata['row_count'], $metadata['source'], $metadata['outcome']]);
        $this->assertSame($this->campusId, (int) $event[0]->campus_id);

        // Verify: read-only, and the same per-contract structure the plan promised.
        $before = $this->checksums();
        DB::flushQueryLog();
        DB::enableQueryLog();
        $verify = $this->strategy()->verify($this->strategy()->plan($x['params']), $result);
        $queries = array_column(DB::getQueryLog(), 'query');
        DB::disableQueryLog();
        $this->assertSame([true, []], [$verify['ok'], $verify['errors']]);
        $this->assertSame(array_map(fn (array $c): array => ['contract_id' => $c['contract_id'], 'purchased' => $c['purchased'], 'before' => $c['before'], 'after' => $c['after']],
            $pinned['contracts']), $verify['contracts']);
        $this->assertSame(['moves' => 2, 'taught_before' => 34, 'taught_after' => 34], $verify['totals']);
        $this->assertContains('money_untouched', $verify['checks']);
        foreach ($queries as $sql) {
            $this->assertMatchesRegularExpression('/^\s*select\b/i', $sql, 'verify ran a non-select statement');
        }
        $this->assertSame($before, $this->checksums());
    }

    public function test_verify_fails_without_a_snapshot_and_names_what_no_longer_holds(): void
    {
        $x = $this->decided();
        $result = $this->strategy()->execute($this->pinned($x['params']), $this->context());
        $plan = $this->strategy()->plan($x['params']);

        $this->assertSame(['execution_result_missing'], $this->strategy()->verify($plan, ['ok' => false])['errors']);
        $this->assertSame(['snapshot_missing'], $this->strategy()->verify($plan, ['ok' => true])['errors']);
        $this->assertSame(['snapshot_missing'], $this->strategy()->verify($plan, ['ok' => true, 'snapshot' => ['rows' => []]])['errors']);

        // A mirror that no longer follows its lesson, and a money row that changed after the execute.
        $moved = $result['snapshot']['rows'][0];
        DB::table('session_deduction_ledger')->where('class_session_id', $moved['id'])->update(['student_class_id' => $moved['from_class_id']]);
        DB::table('StudentClass')->where('ID', $x['contracts'][0])->update(['Charge' => 1]);
        $verify = $this->strategy()->verify($plan, $result);
        $this->assertFalse($verify['ok']);
        $this->assertContains('mirror_owner_differs_' . $moved['id'], $verify['errors']);
        $this->assertContains('money_changed', $verify['errors']);
    }

    public function test_execute_refuses_a_plan_that_is_not_pinned_or_a_request_without_an_id(): void
    {
        $x = $this->decided();
        $before = $this->checksums();
        $unpinned = $this->strategy()->plan($x['params']);
        $this->assertSame('unpinned', $unpinned['state']);

        $this->assertSame([false, ['plan_not_pinned'], 0], array_values(array_intersect_key($this->strategy()->execute($unpinned, $this->context()), ['ok' => 1, 'errors' => 1, 'moved' => 1])));
        $mismatch = $this->strategy()->plan(['expected_digest' => str_repeat('a', 64)] + $x['params']);
        $this->assertSame('digest_mismatch', $mismatch['state']);
        $this->assertSame(['plan_not_pinned'], $this->strategy()->execute($mismatch, $this->context())['errors']);
        $this->assertSame(['request_id_invalid'], $this->strategy()->execute($this->pinned($x['params']), ['actor' => 'pop-pi-local'])['errors']);
        $this->assertSame($before, $this->checksums());
    }

    // ── The two Founder decisions hold at execute time ──

    public function test_without_the_cascade_decision_or_with_an_order_only_swap_nothing_executes(): void
    {
        $x = $this->workedExample();
        $before = $this->checksums();
        $moves = [$x['moves'][2], $x['moves'][3]];

        // Cascade into the active contract not allowed: the plan is refused, so there is nothing to execute.
        $plan = $this->strategy()->plan($this->params($x['student'], $x['contracts'], $moves));
        $this->assertSame(['decision_required_active_contract'], $plan['errors']);
        $this->assertSame(['plan_not_pinned'], $this->strategy()->execute($plan, $this->context())['errors']);

        // Order-only swaps (moves 1 and 2) stay refused while the flag is off, with the cascade allowed.
        $plan = $this->strategy()->plan($this->params($x['student'], $x['contracts'], $x['moves'], ['allow_active_contracts' => true]));
        $this->assertSame(['decision_required_order_only'], $plan['errors']);
        $this->assertSame(['plan_not_pinned'], $this->strategy()->execute($plan, $this->context())['errors']);

        // A pinned plan whose flags were switched off afterwards is planned again under lock and refused by the decision itself.
        $pinned = $this->pinned($this->params($x['student'], $x['contracts'], $moves, ['allow_active_contracts' => true]));
        $pinned['manifest']['allow_active_contracts'] = false;
        $refused = $this->strategy()->execute($pinned, $this->context());
        $this->assertSame([false, 'digest_drift'], [$refused['ok'], $refused['errors'][0]]);
        $this->assertContains('decision_required_active_contract', $refused['errors']);

        $both = $this->pinned($this->params($x['student'], $x['contracts'], $x['moves'], ['allow_active_contracts' => true, 'allow_order_only_moves' => true]));
        $both['manifest']['allow_order_only_moves'] = false;
        $refused = $this->strategy()->execute($both, $this->context());
        $this->assertSame([false, 'digest_drift'], [$refused['ok'], $refused['errors'][0]]);
        $this->assertContains('decision_required_order_only', $refused['errors']);

        $this->assertSame($before, $this->checksums());
        $this->assertSame([8, 8, 9, 8, 1], $this->taughtNow($x['contracts']));
    }

    public function test_reservations_beyond_what_is_left_block_the_execute_and_are_never_cancelled_by_it(): void
    {
        // Seven reservations: after the cascade the newest contract would hold 2 taught + 7 reserved of 8.
        $x = $this->decided(7);
        $plan = $this->strategy()->plan($x['params']);
        $this->assertSame(['future_reservations_exceed_remaining'], $plan['errors']);
        $before = $this->checksums();
        $this->assertSame(['plan_not_pinned'], $this->strategy()->execute($plan, $this->context())['errors']);
        $this->assertSame($before, $this->checksums());

        // #5236 review P2a: with the cascade into an active contract, `report` is refused too, so the execute can never
        // leave that contract with taught + reserved above what it bought.
        $report = $this->strategy()->plan(['future_reservation_policy' => 'report'] + $x['params']);
        $this->assertSame(['report_policy_requires_no_active_contract'], $report['errors']);
        $this->assertSame(['plan_not_pinned'], $this->strategy()->execute($report, $this->context())['errors']);
        $this->assertSame($before, $this->checksums());
        // A plan pinned with `block` and switched to `report` afterwards is planned again under lock and refused.
        $y = $this->decided();
        $switched = $this->pinned($y['params']);
        $switched['manifest']['future_reservation_policy'] = 'report';
        $unchanged = $this->checksums();
        $refused = $this->strategy()->execute($switched, $this->context());
        $this->assertSame([false, 'digest_drift'], [$refused['ok'], $refused['errors'][0]]);
        $this->assertContains('report_policy_requires_no_active_contract', $refused['errors']);
        $this->assertSame($unchanged, $this->checksums());

        // Policy `report` on a chain with no active contract: the move runs, the overflow is recorded, and every
        // reservation is still there, untouched.
        $p = $this->endedPairWithReservations();
        $b = (int) $p['b']->ID;
        $reservations = fn (): array => DB::table('ClassSession')->where('StudentClassID', $b)->where('Status', 'scheduled')->orderBy('id')->get()->all();
        $kept = json_encode($reservations());
        $result = $this->strategy()->execute($this->pinned(['future_reservation_policy' => 'report'] + $p['params']), $this->context());
        $this->assertTrue($result['ok'], json_encode($result['errors'] ?? null));
        $this->assertSame([['contract_id' => $b, 'purchased' => 8, 'taught' => 2, 'reserved' => 7]], $result['snapshot']['reservation_overflows']);
        $this->assertSame([], $result['snapshot']['released_reservations']);
        $this->assertSame($kept, json_encode($reservations()));
    }

    // ── Drift, replay, concurrency (acceptance 10, 13) ──

    public function test_a_change_between_the_dry_run_and_the_execute_is_refused_and_writes_nothing(): void
    {
        $changes = [
            'a lesson was taught on a target meanwhile' => fn (array $x) => $this->taught(StudentClass::query()->where('ID', $x['contracts'][4])->firstOrFail()),
            'the moved lesson changed status' => fn (array $x) => DB::table('ClassSession')->where('id', $x['sessions'][2][8])->update(['Status' => 'late']),
            'the moved lesson is on another contract' => fn (array $x) => DB::table('ClassSession')->where('id', $x['sessions'][3][7])->update(['StudentClassID' => $x['contracts'][0]]),
            'a contract was bought since' => fn (array $x) => $this->contract($x['student'], 8, false),
            'the bought count changed' => fn (array $x) => DB::table('StudentClass')->where('ID', $x['contracts'][3])->update(['SessionCount' => 9]),
        ];
        foreach ($changes as $label => $change) {
            $x = $this->decided();
            $pinned = $this->pinned($x['params']);
            $change($x);
            $before = $this->checksums();

            $refused = $this->strategy()->execute($pinned, $this->context());

            $this->assertSame([false, 'digest_drift', 0], [$refused['ok'], $refused['errors'][0], $refused['moved']], $label);
            $this->assertSame($before, $this->checksums(), $label . ': a refused execute changed a row');
        }
    }

    public function test_a_replay_of_the_same_request_is_a_no_op_with_the_same_result(): void
    {
        $x = $this->decided();
        $service = $this->service();
        $run = $this->viaPop($service, $x['params']);
        $this->assertSame(['succeeded', 'succeeded'], [$run['execute']['result'], $run['verify']['result']]);
        $this->assertSame([8, 8, 8, 8, 2], $this->taughtNow($x['contracts']));
        $this->assertSame($run['id'], $run['execute']['snapshot']['request_id']);
        $before = $this->checksums();
        $records = DB::table('pop_execution_records')->count();

        // POP answers the replay from its record.
        $again = $service->run($run['id'], 'execute', $run['token'], $run['sha'], 'pop-pi-local');
        $this->assertSame([$run['execute']['execution_id'], 'succeeded', 2], [$again['execution_id'], $again['result'], $again['moved']]);

        // The strategy itself, handed the same request id again (with a plan that no longer matches), answers the same result.
        $stale = $this->strategy()->plan(['expected_digest' => $run['execute']['digest']] + $x['params']);
        $this->assertFalse($stale['ok']);
        $replay = $this->strategy()->execute($stale, $this->context($run['id']));
        $this->assertSame([true, true, 2, $run['execute']['digest']], [$replay['ok'], $replay['already_applied'], $replay['moved'], $replay['digest']]);
        $this->assertSame($run['execute']['snapshot'], $replay['snapshot']);

        $this->assertSame($before, $this->checksums(), 'a replay moved or recorded something');
        $this->assertSame($records, DB::table('pop_execution_records')->count());
        $this->assertSame(2, DB::table('class_session_reassignments')->count());
        $this->assertSame(1, DB::table('security_audit_events')->where('event_type', self::AUDIT)->count());
    }

    /**
     * Two requests pinned to the same digest. PHPUnit has one connection, so the second runs after the first has
     * committed: that is the state a second runner sees once the first releases its row locks.
     */
    public function test_of_two_executes_with_the_same_digest_one_wins_and_the_other_is_refused(): void
    {
        $x = $this->decided();
        $pinned = $this->pinned($x['params']);

        $first = $this->strategy()->execute($pinned, $this->context());
        $this->assertTrue($first['ok'], json_encode($first['errors'] ?? null));
        $before = $this->checksums();
        $second = $this->strategy()->execute($pinned, $this->context());

        $this->assertSame([false, 'digest_drift', 0], [$second['ok'], $second['errors'][0], $second['moved']]);
        $this->assertContains('move_from_mismatch', $second['errors']);
        $this->assertSame($before, $this->checksums());
        $this->assertSame(2, DB::table('class_session_reassignments')->count());
        $this->assertSame([8, 8, 8, 8, 2], $this->taughtNow($x['contracts']));
    }

    public function test_a_failure_on_the_last_move_undoes_every_move(): void
    {
        $x = $this->decided();
        $this->mirrors($x['sessions'][2][8], $x['contracts'][2], $x['student']);
        $pinned = $this->pinned($x['params']);
        $before = $this->checksums();
        $ownership = $this->ownership();
        $last = $x['sessions'][3][7];
        // The reassigner writes its reassignment row last; failing it on the second lesson fails the last move after the
        // first move and the second lesson's own updates were already made.
        ClassSessionReassignment::creating(function (ClassSessionReassignment $row) use ($last): void {
            if ((int) $row->class_session_id === $last) {
                throw new RuntimeException('injected_failure_on_last_move');
            }
        });

        try {
            $this->strategy()->execute($pinned, $this->context());
            $this->fail('the injected failure must surface');
        } catch (RuntimeException $e) {
            $this->assertSame('injected_failure_on_last_move', $e->getMessage());
        } finally {
            ClassSessionReassignment::flushEventListeners();
        }

        $this->assertSame($before, $this->checksums(), 'a failed execute left a row changed');
        $this->assertSame($ownership, $this->ownership());
        $this->assertSame([$x['contracts'][2], $x['contracts'][3]], $this->owners([$x['sessions'][2][8], $last]));
        $this->assertSame([8, 8, 9, 8, 1], $this->taughtNow($x['contracts']));
        $this->assertSame(0, DB::table('class_session_reassignments')->count());
        $this->assertSame(0, DB::table('security_audit_events')->where('event_type', 'like', self::AUDIT . '%')->count());

        // The same pinned plan still runs once the cause is gone: nothing was half-applied.
        $this->assertTrue($this->strategy()->execute($pinned, $this->context())['ok']);
        $this->assertSame([8, 8, 8, 8, 2], $this->taughtNow($x['contracts']));
    }

    /**
     * #5236 review P1: POP's transaction is the outer one, and Laravel gives a nested "Lock wait timeout" no ROLLBACK TO
     * SAVEPOINT, so the failed receipt used to commit together with the moves made before the failure (no snapshot).
     */
    public function test_a_failure_in_the_middle_of_the_moves_commits_nothing_through_pop(): void
    {
        $timeout = fn (): \Throwable => new QueryException('mysql', 'insert into `class_session_reassignments`', [],
            new \PDOException('SQLSTATE[HY000]: General error: 1205 Lock wait timeout exceeded; try restarting transaction'));
        foreach (['lock-wait timeout' => $timeout, 'other failure' => fn (): \Throwable => new RuntimeException('injected')] as $label => $failure) {
            $x = $this->decided();
            $this->mirrors($x['sessions'][2][8], $x['contracts'][2], $x['student']);
            $before = $this->rowsOf($x['contracts']);
            $service = $this->service();
            // The reassigner writes its reassignment row last: when the second lesson fails there, move 1 is complete.
            ClassSessionReassignment::creating(fn (ClassSessionReassignment $row) => (int) $row->class_session_id === $x['sessions'][3][7] ? throw $failure() : null);
            try {
                $run = $this->viaPop($service, $x['params'], ['execute']);
            } finally {
                ClassSessionReassignment::flushEventListeners();
            }

            $this->assertSame(['failed', ['execution_failed']], [$run['execute']['result'], $run['execute']['errors']], $label);
            $this->assertSame($before, $this->rowsOf($x['contracts']), $label . ': a failed execute left a row of the chain changed');
            $this->assertSame(0, DB::table('class_session_reassignments')->whereIn('class_session_id', [$x['sessions'][2][8], $x['sessions'][3][7]])->count(), $label);
            $this->assertSame([['failed', null]], DB::table('pop_execution_records')->where('operation_id', $run['id'])->where('phase', 'execute')
                ->get(['result', 'snapshot'])->map(fn (\stdClass $r): array => [(string) $r->result, $r->snapshot])->all(), $label . ': failed receipt, no snapshot');
            // Nothing was half-applied: a new request with the same manifest runs once the cause is gone.
            $again = $this->viaPop($service, $x['params']);
            $this->assertSame(['succeeded', 'succeeded', [8, 8, 8, 8, 2]], [$again['execute']['result'], $again['verify']['result'], $this->taughtNow($x['contracts'])], $label);
        }
    }

    /** #5236 review P1, the restore side: a restore request that fails on its second move puts nothing back, and still works afterwards. */
    public function test_a_failure_in_the_middle_of_a_restore_commits_nothing_through_pop(): void
    {
        $x = $this->decided();
        $before = $this->rowsOf($x['contracts']);
        $service = $this->service();
        $run = $this->viaPop($service, $x['params']);
        $this->assertSame(['succeeded', 'succeeded'], [$run['execute']['result'], $run['verify']['result']]);
        $executed = $this->rowsOf($x['contracts']);
        $inverse = $run['execute']['restore_moves'];
        $restore = ['restore_request_id' => $run['id'], 'moves' => $inverse] + $this->params($x['student'], $x['contracts'], $inverse);
        $last = (int) $inverse[1]['session_id'];
        ClassSessionReassignment::creating(function (ClassSessionReassignment $row) use ($last): void {
            if ((int) $row->class_session_id === $last) {
                throw new QueryException('mysql', 'insert into `class_session_reassignments` (`class_session_id`) values (?)', [0],
                    new \PDOException('SQLSTATE[HY000]: General error: 1205 Lock wait timeout exceeded; try restarting transaction'));
            }
        });
        try {
            $failed = $this->viaPop($service, $restore, ['execute']);
        } finally {
            ClassSessionReassignment::flushEventListeners();
        }

        $this->assertSame(['failed', ['execution_failed']], [$failed['execute']['result'], $failed['execute']['errors']]);
        $this->assertSame($executed, $this->rowsOf($x['contracts']), 'a failed restore put a lesson back');
        $this->assertNull(DB::table('pop_execution_records')->where('operation_id', $failed['id'])->where('phase', 'execute')->value('snapshot'));

        // The source is not marked restored by a failed attempt: a new restore request brings the chain back.
        $back = $this->viaPop($service, $restore);
        $this->assertSame(['succeeded', 'succeeded'], [$back['execute']['result'], $back['verify']['result']]);
        $this->assertSame($before, $this->rowsOf($x['contracts']));
    }

    /**
     * #5236 review P2: after a true deadlock MySQL has already dropped the transaction with its savepoints, so the rollback
     * of POP's own transaction throws in place of PopPhaseAborted. The request used to stay `approved` with no receipt.
     * Stood in for here by a rollback listener that throws once POP's transaction has been rolled back.
     */
    public function test_a_failed_execute_gets_its_failed_receipt_also_when_the_rollback_itself_throws(): void
    {
        $x = $this->decided();
        $before = $this->rowsOf($x['contracts']);
        $service = $this->service();
        $base = DB::transactionLevel(); // the test's own transaction; POP's is the one that rolls back to it
        $armed = false;
        ClassSessionReassignment::creating(function (ClassSessionReassignment $row) use ($x, &$armed): void {
            if ((int) $row->class_session_id === $x['sessions'][3][7]) {
                $armed = true;
                throw new RuntimeException('injected');
            }
        });
        Event::listen(TransactionRolledBack::class, function () use (&$armed, $base): void {
            if ($armed && DB::transactionLevel() === $base) {
                $armed = false;
                throw new RuntimeException('rollback_failed');
            }
        });
        try {
            $run = $this->viaPop($service, $x['params'], ['execute']);
        } finally {
            ClassSessionReassignment::flushEventListeners();
            Event::forget(TransactionRolledBack::class);
        }

        $this->assertFalse($armed, 'the rollback of the POP transaction did throw');
        $this->assertSame(['failed', ['execution_failed']], [$run['execute']['result'], $run['execute']['errors']]);
        $this->assertSame($base, DB::transactionLevel());
        $this->assertSame($before, $this->rowsOf($x['contracts']), 'a failed execute left a row of the chain changed');
        $this->assertSame([['failed', null]], DB::table('pop_execution_records')->where('operation_id', $run['id'])->where('phase', 'execute')
            ->get(['result', 'snapshot'])->map(fn (\stdClass $r): array => [(string) $r->result, $r->snapshot])->all());
        $again = $this->viaPop($service, $x['params']);
        $this->assertSame(['succeeded', 'succeeded', [8, 8, 8, 8, 2]], [$again['execute']['result'], $again['verify']['result'], $this->taughtNow($x['contracts'])]);

        // A refusal before the phase begins is still an exception, not a receipt: nothing ran, so there is nothing to record.
        $records = DB::table('pop_execution_records')->count();
        try {
            $service->run($again['id'], 'rollback', null, $again['sha'], 'pop-pi-local');
            $this->fail('a run without an approval token must be refused');
        } catch (RuntimeException $e) {
            $this->assertSame('POP approval token is missing; fail closed.', $e->getMessage());
        }
        $this->assertSame($records, DB::table('pop_execution_records')->count());
    }

    // ── Scope at execute time (acceptance 15; Y10) ──

    public function test_campus_student_and_subject_are_enforced_again_at_execute_time(): void
    {
        $otherCampus = (int) Campus::factory()->create()->id;

        // The student belongs to another campus by the time the request runs.
        $x = $this->decided();
        $pinned = $this->pinned($x['params']);
        DB::table('Student')->where('id', $x['student'])->update(['CampusID' => $otherCampus]);
        $before = $this->checksums();
        $this->assertSame(['digest_drift', 'student_campus_mismatch'], $this->strategy()->execute($pinned, $this->context())['errors']);
        $this->assertSame($before, $this->checksums());

        // A plan handed over with a lesson of another student (same campus), or of a student of another campus.
        foreach (['session_student_mismatch' => $this->campusId, 'session_not_found_in_campus' => $otherCampus] as $code => $campus) {
            $x = $this->decided();
            $pinned = $this->pinned($x['params']);
            $foreign = $this->taught($this->contract($this->student($campus)));
            $pinned['manifest']['moves'][0]['session_id'] = $foreign;
            $before = $this->checksums();
            $refused = $this->strategy()->execute($pinned, $this->context());
            $this->assertSame([false, 'digest_drift'], [$refused['ok'], $refused['errors'][0]], $code);
            $this->assertContains($code, $refused['errors']);
            $this->assertSame($before, $this->checksums(), $code);
        }

        // A plan handed over with another campus than the student's, and one whose contract changed subject since.
        $x = $this->decided();
        $pinned = $this->pinned($x['params']);
        $tampered = $pinned;
        $tampered['manifest']['campus_id'] = $otherCampus;
        $this->assertSame(['digest_drift', 'student_campus_mismatch'], $this->strategy()->execute($tampered, $this->context())['errors']);
        DB::table('StudentClass')->where('ID', $x['contracts'][3])->update(['SubjectID' => self::SUBJECT + 1]);
        $before = $this->checksums();
        $refused = $this->strategy()->execute($pinned, $this->context());
        $this->assertSame([false, 'digest_drift'], [$refused['ok'], $refused['errors'][0]]);
        $this->assertContains('contract_subject_mismatch', $refused['errors']);
        $this->assertSame($before, $this->checksums());
    }

    // ── Restore (acceptance 14) ──

    public function test_rollback_returns_every_lesson_mirror_and_counter_to_the_state_before_the_execute(): void
    {
        $x = $this->decided();
        $this->mirrors($x['sessions'][2][8], $x['contracts'][2], $x['student']);
        $this->invoice($x['contracts'][3], $x['student']);
        $ownership = $this->ownership();
        $money = $this->money();
        $context = $this->context();
        $result = $this->strategy()->execute($this->pinned($x['params']), $context);
        $this->assertNotSame($ownership, $this->ownership());

        $back = $this->strategy()->rollback($result['snapshot'], $context);

        $this->assertSame([true, 2], [$back['ok'], $back['restored']]);
        $this->assertSame([['session_id' => $x['sessions'][2][8], 'state' => 'restorable'], ['session_id' => $x['sessions'][3][7], 'state' => 'restorable']], $back['rows']);
        $this->assertSame($ownership, $this->ownership(), 'owners, mirrors and counters are exactly as before the execute');
        $this->assertSame([8, 8, 9, 8, 1], $this->taughtNow($x['contracts']));
        $this->assertSame($money, $this->money());
        // Undo is appended, not erased: the forward and the backward move are both on record, the last move first.
        $this->assertSame(4, DB::table('class_session_reassignments')->count());
        $this->assertSame([$x['sessions'][3][7], $x['sessions'][2][8]],
            DB::table('class_session_reassignments')->where('reason', 'like', '%.rollback')->orderBy('id')->pluck('class_session_id')->map(fn ($id): int => (int) $id)->all());
        $this->assertSame(1, DB::table('security_audit_events')->where('event_type', self::AUDIT . '.rollback')->count());

        // A second rollback finds nothing on the targets and restores nothing.
        $before = $this->checksums();
        $again = $this->strategy()->rollback($result['snapshot'], $context);
        $this->assertSame([false, 'restore_refused', 0], [$again['ok'], $again['errors'][0], $again['restored']]);
        $this->assertSame(['owner_changed', 'owner_changed'], array_column($again['rows'], 'state'));
        $this->assertSame($before, $this->checksums());
    }

    public function test_rollback_refuses_as_a_whole_with_a_report_per_lesson_when_a_moved_lesson_changed(): void
    {
        $changes = [
            'lesson_changed' => fn (array $r) => DB::table('ClassSession')->where('id', $r['id'])->update(['Status' => 'late']),
            'owner_changed' => fn (array $r) => DB::table('ClassSession')->where('id', $r['id'])->update(['StudentClassID' => $r['from_class_id']]),
            'mirrors_changed' => fn (array $r) => DB::table('session_deduction_ledger')->insert(['student_class_id' => $r['to_class_id'], 'event_type' => 'reverse',
                'source' => 'status_adjust', 'class_session_id' => $r['id'], 'created_at' => now(), 'updated_at' => now()]),
        ];
        foreach ($changes as $state => $change) {
            $x = $this->decided();
            $context = $this->context();
            $result = $this->strategy()->execute($this->pinned($x['params']), $context);
            $rows = $result['snapshot']['rows'];
            $change($rows[1]); // only the second moved lesson changes after the execute
            $before = $this->checksums();

            $back = $this->strategy()->rollback($result['snapshot'], $context);

            $this->assertSame([false, 'restore_refused', 0], [$back['ok'], $back['errors'][0], $back['restored']], $state);
            $this->assertContains('restore_row_changed', $back['errors'], $state);
            $this->assertSame([['session_id' => $rows[0]['id'], 'state' => 'restorable'], ['session_id' => $rows[1]['id'], 'state' => $state]], $back['rows'], $state);
            $this->assertSame($before, $this->checksums(), $state . ': a refused restore changed a row');
            $this->assertSame((int) $rows[0]['to_class_id'], $this->owners([$rows[0]['id']])[0], $state . ': the unchanged lesson was not restored on its own');
        }
    }

    public function test_rollback_refuses_a_snapshot_that_is_not_a_rebalance_run_of_this_operation(): void
    {
        $x = $this->decided();
        $result = $this->strategy()->execute($this->pinned($x['params']), $this->context());
        $before = $this->checksums();

        foreach ([['rows' => []], ['mode' => 'restore'] + $result['snapshot'], ['operation' => 'course-contract-repair'] + $result['snapshot'],
            ['student_id' => 0] + $result['snapshot']] as $snapshot) {
            try {
                $this->strategy()->rollback($snapshot, $this->context());
                $this->fail('an unusable snapshot must be refused');
            } catch (RuntimeException $e) {
                $this->assertSame('chain_rebalance_rollback_snapshot_invalid', $e->getMessage());
            }
        }
        $this->assertSame($before, $this->checksums());
    }

    public function test_a_restore_request_needs_its_own_approval_and_undoes_exactly_the_executed_moves(): void
    {
        $x = $this->decided();
        // Another student of the campus with a contract of the same subject (used below to show the source is bound to the
        // student). Made before the baseline: `ownership()` covers every row of StudentClass, so a contract added after it
        // would move the fingerprint although the restore is exact (#5236 CI). The baseline still covers every column.
        $otherStudent = $this->student();
        $this->contract($otherStudent);
        $ownership = $this->ownership();
        $service = $this->service();
        $run = $this->viaPop($service, $x['params']);
        $this->assertSame(['succeeded', 'succeeded'], [$run['execute']['result'], $run['verify']['result']]);
        $inverse = $run['execute']['restore_moves'];
        $this->assertSame([$x['sessions'][3][7], $x['sessions'][2][8]], array_column($inverse, 'session_id'));
        $restore = ['restore_request_id' => $run['id'], 'moves' => $inverse] + $this->params($x['student'], $x['contracts'], $inverse);

        // The source must be an executed rebalance request of this operation, same campus, student and subject.
        $this->assertSame(['restore_source_not_found'], $this->strategy()->plan(['restore_request_id' => (string) Str::uuid()] + $restore)['errors']);
        $this->assertSame(['restore_source_not_found'], $this->strategy()->plan(['student_id' => $otherStudent] + $restore)['errors']);
        // The list must be the executed moves reversed: nothing else can ride on a restore.
        $this->assertContains('restore_moves_mismatch', $this->strategy()->plan(['moves' => array_reverse($inverse)] + $restore)['errors']);
        $this->assertContains('restore_moves_mismatch', $this->strategy()->plan(['moves' => [$inverse[0]]] + $restore)['errors']);

        // Undo needs neither decision flag (it returns to a state that was there) but its own draft, dry-run and approval.
        $plan = $this->strategy()->plan($restore);
        $this->assertSame([true, 'restore', [8, 8, 9, 8, 1]], [$plan['ok'], $plan['mode'], $this->taughtAfter($plan)]);
        $this->assertSame(['restorable', 'restorable'], array_column($plan['restore_rows'], 'state'));
        $this->assertSame(['plan_not_pinned'], $this->strategy()->execute($plan, $this->context())['errors']);

        $back = $this->viaPop($service, $restore);
        $this->assertSame(['succeeded', 'succeeded'], [$back['execute']['result'], $back['verify']['result']]);
        $this->assertSame(['restore', 2, $run['id']], [$back['execute']['mode'], $back['execute']['moved'], $back['execute']['snapshot']['restore_source_request_id']]);
        $this->assertSame($ownership, $this->ownership(), 'owners, mirrors and counters are exactly as before the execute');
        $this->assertSame(1, DB::table('security_audit_events')->where('event_type', self::AUDIT . '.restore')->count());

        // Restored once: the receipt of that restore names its source, so a second restore request of the same source is refused.
        $again = $this->strategy()->plan($restore);
        $this->assertSame([false, ['restore_already_applied'], 'restore'], [$again['ok'], $again['errors'], $again['mode']]);
        $this->assertSame([['code' => 'restore_already_applied', 'restored_by_request_id' => $back['id']]], $again['findings']);
        // A restore run is not itself a restore source.
        $this->assertSame(['restore_source_not_found'], $this->strategy()->plan(['restore_request_id' => $back['id']] + $restore)['errors']);
    }

    /**
     * #5236 review P2b. "Already restored" used to be read from the rows: after request 1, its restore and a second request
     * moving the same lessons again, the rows are as request 1 left them, and a restore citing request 1 passed.
     */
    public function test_a_source_that_was_restored_once_stays_refused_when_the_same_lessons_are_moved_again(): void
    {
        $x = $this->decided();
        $initial = $this->rowsOf($x['contracts']);
        $service = $this->service();
        $first = $this->viaPop($service, $x['params']);
        $inverse = $first['execute']['restore_moves'];
        $restore = ['restore_request_id' => $first['id'], 'moves' => $inverse] + $this->params($x['student'], $x['contracts'], $inverse);
        $back = $this->viaPop($service, $restore);
        $this->assertSame(['succeeded', 'succeeded'], [$back['execute']['result'], $back['verify']['result']]);
        $second = $this->viaPop($service, $x['params']);
        $this->assertSame(['succeeded', 'succeeded'], [$second['execute']['result'], $second['verify']['result']]);
        $this->assertSame([8, 8, 8, 8, 2], $this->taughtNow($x['contracts']));
        $moved = $this->rowsOf($x['contracts']);

        // Every lesson of request 1 is on its target again, yet request 1 was restored: refused by its record.
        $again = $this->strategy()->plan($restore);
        $this->assertSame([false, ['restore_already_applied'], 'restore'], [$again['ok'], $again['errors'], $again['mode']]);
        $this->assertSame([['code' => 'restore_already_applied', 'restored_by_request_id' => $back['id']]], $again['findings']);
        $draft = (string) $service->createDraft(self::OPERATION, ['expected_digest' => ''] + $restore, 'chain-exec-' . bin2hex(random_bytes(4)), 'user:1', 'super_admin', [], 1)['id'];
        $this->assertSame(['restore_already_applied'], $service->runDryRun($draft, 'user:1', 1, 'super_admin', [])['plan']['errors']);
        $this->assertSame($moved, $this->rowsOf($x['contracts']));

        // The request that did move them is the one to cite.
        $inverse = $second['execute']['restore_moves'];
        $undo = $this->viaPop($service, ['restore_request_id' => $second['id'], 'moves' => $inverse] + $this->params($x['student'], $x['contracts'], $inverse));
        $this->assertSame(['succeeded', 'succeeded'], [$undo['execute']['result'], $undo['verify']['result']]);
        $this->assertSame($initial, $this->rowsOf($x['contracts']));
    }

    /**
     * #5236 review P2c. Execute and restore recompute the counters of the contracts they touch, so the restore is exact only
     * when the counters stored before the execute are the ones a recompute writes. The plan refuses anything else on a
     * contract a move touches. On a contract no move touches (its counters are never written) the same finding is a
     * warning that is bound into the digest (#5124 dry-run 2026-11-13, #5242 review).
     */
    public function test_stored_counters_must_be_the_derived_ones_before_anything_executes(): void
    {
        $p = $this->pair();
        [$a, $b] = [(int) $p['a']->ID, (int) $p['b']->ID];
        $c = (int) $this->contract($p['student'])->ID; // on the chain, touched by no move
        $params = ['contract_ids' => [$a, $b, $c]] + $p['params'];
        $this->assertTrue($this->strategy()->plan($params)['ok']);
        $counters = fn (int $id): array => (array) DB::table('StudentClass')->where('ID', $id)->first(self::COUNTERS);
        $this->assertSame([8, 0, 1, 7, 0, 8], array_map('intval', array_merge(array_slice(array_values($counters($a)), 0, 2),
            array_slice(array_values($counters($b)), 0, 2), array_slice(array_values($counters($c)), 0, 2))));

        $clean = $this->strategy()->plan($params);
        $this->assertSame([], $clean['warnings']);

        // Session and minute counters on the contracts a move touches (both ends): refused, never a warning.
        foreach ([[$a, ['UsedSessions' => 7]], [$b, ['RemainingSessions' => 0]], [$b, ['PurchasedMinutes' => 5]],
            [$a, ['RemainingMinutes' => 5]]] as [$id, $change]) {
            $kept = $counters($id);
            DB::table('StudentClass')->where('ID', $id)->update($change);
            $plan = $this->strategy()->plan($params);
            $this->assertSame(['counters_not_derived'], $plan['errors'], json_encode($change));
            $this->assertSame([['code' => 'counters_not_derived', 'contract_id' => $id]], $plan['findings'], json_encode($change));
            $this->assertSame([], $plan['warnings'], json_encode($change));
            DB::table('StudentClass')->where('ID', $id)->update($kept);
            $this->assertTrue($this->strategy()->plan($params)['ok'], json_encode($change));
        }

        // The same on the contract no move touches: the plan passes, the finding is a warning, and the digest is another
        // one (the stored counters are not part of the observed state, so only the warning itself can make it differ).
        $kept = $counters($c);
        DB::table('StudentClass')->where('ID', $c)->update(['UsedSessions' => 3]);
        $plan = $this->strategy()->plan($params);
        $this->assertSame([true, [], []], [$plan['ok'], $plan['errors'], $plan['findings']]);
        $this->assertSame([['code' => 'counters_not_derived', 'contract_id' => $c, 'session_id' => null]], $plan['warnings']);
        $this->assertNotSame($clean['digest'], $plan['digest'], 'a plan with a warning must not share the digest of the plan without it');
        // An approval of the plan without the warning does not cover the plan with it; nothing moves.
        $before = $this->checksums();
        $this->assertSame(['digest_drift'], $this->strategy()->execute(['expected_digest' => $clean['digest'], 'state' => 'pinned'] + $clean, $this->context())['errors']);
        $this->assertSame($before, $this->checksums());
        DB::table('StudentClass')->where('ID', $c)->update($kept);
        $this->assertSame([$clean['digest'], []], [$this->strategy()->plan($params)['digest'], $this->strategy()->plan($params)['warnings']]);
        // The minute columns of a contract no move touches are not written by execute or restore, so they are not asked for.
        $kept = $counters($c);
        DB::table('StudentClass')->where('ID', $c)->update(['PurchasedMinutes' => null]);
        $this->assertTrue($this->strategy()->plan($params)['ok']);
        DB::table('StudentClass')->where('ID', $c)->update($kept);

        // A counter that drifts after the dry-run is caught again under the execute's locks; nothing moves.
        $pinned = $this->pinned($params);
        DB::table('StudentClass')->where('ID', $a)->update(['UsedSessions' => 7]);
        $before = $this->checksums();
        $this->assertSame(['digest_drift', 'counters_not_derived'], $this->strategy()->execute($pinned, $this->context())['errors']);
        $this->assertSame($before, $this->checksums());
        DB::table('StudentClass')->where('ID', $a)->update(['UsedSessions' => 8]);

        // Undo is never held back by it: an executed request is still rolled back when a counter drifted afterwards.
        $context = $this->context();
        $result = $this->strategy()->execute($pinned, $context);
        $this->assertTrue($result['ok'], json_encode($result['errors'] ?? null));
        DB::table('StudentClass')->where('ID', $c)->update(['UsedSessions' => 3]);
        $this->assertSame([true, 1], array_values(array_intersect_key($this->strategy()->rollback($result['snapshot'], $context), ['ok' => 1, 'restored' => 1])));
    }

    /**
     * #5242 review P2. A contract that was never recomputed since the minute columns were added holds NULL in them; there is
     * no separate recompute operation, so refusing it left no way forward. On a touched contract NULL is accepted, because
     * the recompute of the execute writes the derived minutes; any other value that is not the derived one is still refused.
     */
    public function test_null_minute_counters_on_a_touched_contract_are_accepted_and_recomputed_by_the_execute(): void
    {
        $p = $this->pair();
        [$a, $b] = [(int) $p['a']->ID, (int) $p['b']->ID];
        DB::table('StudentClass')->whereIn('ID', [$a, $b])->update(['PurchasedMinutes' => null, 'RemainingMinutes' => null]);

        $plan = $this->strategy()->plan($p['params']);
        $this->assertSame([true, [], []], [$plan['ok'], $plan['errors'], $plan['warnings']]);

        $result = $this->strategy()->execute($this->pinned($p['params']), $this->context());
        $this->assertTrue($result['ok'], json_encode($result['errors'] ?? null));
        foreach ([$a => 8, $b => 2] as $id => $used) {
            $course = StudentClass::query()->where('ID', $id)->firstOrFail();
            $perLesson = SessionDeductionService::billingStandardMinutes($course);
            $this->assertSame([$used, 8 - $used, 8 * $perLesson, (8 - $used) * $perLesson],
                array_map('intval', [$course->getAttribute('UsedSessions'), $course->getAttribute('RemainingSessions'),
                    $course->getAttribute('PurchasedMinutes'), $course->getAttribute('RemainingMinutes')]), "contract {$id}");
            $this->assertNotNull($course->getAttribute('PurchasedMinutes'));
            $this->assertNotNull($course->getAttribute('RemainingMinutes'));
        }
        $this->assertTrue($this->strategy()->verify([], $result)['ok']);

        // A minute value that is neither NULL nor the derived one is still refused.
        $q = $this->pair();
        DB::table('StudentClass')->where('ID', $q['b']->ID)->update(['PurchasedMinutes' => null, 'RemainingMinutes' => 5]);
        $this->assertSame(['counters_not_derived'], $this->strategy()->plan($q['params'])['errors']);
    }

    // ── Scope of the mirror rule: touched contracts refuse, untouched contracts warn (#5124 dry-run 2026-11-13) ──

    /**
     * The shape of the refused production dry-run, synthetic ids: five contracts taught 8 / 8 / 9 / 8 / 1, two moves on the
     * last three, and the first contract (touched by no move) with one taught lesson that has no deduction (taught 8, ledger 7).
     *
     * @return array{student: int, contracts: list<int>, moves: list<array<string,mixed>>, sessions: list<list<int>>, params: array<string,mixed>, gap: int, warning: array<string,mixed>}
     */
    private function untouchedGap(): array
    {
        $x = $this->decided();
        $x['gap'] = $x['sessions'][0][3];
        $this->removeDeduction($x['gap']);
        $x['warning'] = ['code' => 'mirror_inconsistent', 'contract_id' => $x['contracts'][0], 'session_id' => $x['gap']];

        return $x;
    }

    private function removeDeduction(int $sessionId): void
    {
        $this->assertSame(1, DB::table('session_deduction_ledger')->where('class_session_id', $sessionId)->delete());
    }

    public function test_a_ledger_gap_on_a_contract_no_move_touches_is_a_warning_and_the_plan_passes(): void
    {
        $x = $this->untouchedGap();
        $this->assertSame([8, 7], [$this->taughtNow([$x['contracts'][0]])[0],
            DB::table('session_deduction_ledger')->where('student_class_id', $x['contracts'][0])->count()]);

        $plan = $this->strategy()->plan($x['params']);
        $this->assertSame([true, [], []], [$plan['ok'], $plan['errors'], $plan['findings']]);
        $this->assertSame([$x['warning']], $plan['warnings'], 'one warning, naming the contract and the lesson');
        $this->assertSame([8, 8, 8, 8, 2], $this->taughtAfter($plan));
        $this->assertSame([34, 34], [$plan['totals']['taught_before'], $plan['totals']['taught_after']]);

        // The warning is part of what gets pinned and approved.
        $pinned = $this->pinned($x['params']);
        $this->assertSame([$x['warning']], $pinned['warnings']);
    }

    public function test_the_digest_of_a_plan_with_a_warning_differs_from_the_plan_without_it(): void
    {
        $x = $this->decided();
        $clean = $this->strategy()->plan($x['params']);
        $this->assertSame([true, []], [$clean['ok'], $clean['warnings']]);

        $this->removeDeduction($x['sessions'][0][3]);
        $warned = $this->strategy()->plan($x['params']);
        $this->assertTrue($warned['ok']);
        $this->assertCount(1, $warned['warnings']);
        $this->assertNotSame($clean['digest'], $warned['digest']);
        // A plan pinned before the gap appeared is not the plan that would run now.
        $stale = $this->strategy()->plan(['expected_digest' => $clean['digest']] + $x['params']);
        $this->assertSame([false, ['digest_mismatch'], 'digest_mismatch'], [$stale['ok'], $stale['errors'], $stale['state']]);
    }

    public function test_the_same_gap_on_a_touched_contract_or_on_a_moved_lesson_is_still_refused(): void
    {
        // On the source of a move (a lesson that is not moved), on the target of a move, and on the moved lesson itself.
        foreach ([[2, 0], [3, 0], [4, 0], [2, 8], [3, 7]] as [$contract, $lesson]) {
            $x = $this->decided();
            $sid = $x['sessions'][$contract][$lesson];
            $this->removeDeduction($sid);
            $plan = $this->strategy()->plan($x['params']);
            $this->assertSame([false, ['mirror_inconsistent'], []], [$plan['ok'], $plan['errors'], $plan['warnings']], "contract {$contract} lesson {$lesson}");
            $this->assertContains(['code' => 'mirror_inconsistent', 'session_id' => $sid], $plan['findings']);
            $this->assertContains(['code' => 'mirror_inconsistent', 'contract_id' => $x['contracts'][$contract]], $plan['findings']);
        }

        // A gap on an untouched contract does not hide one on a touched contract: the plan is refused and both are reported.
        $x = $this->untouchedGap();
        $this->removeDeduction($x['sessions'][3][0]);
        $plan = $this->strategy()->plan($x['params']);
        $this->assertSame([false, ['mirror_inconsistent']], [$plan['ok'], $plan['errors']]);
        $this->assertSame([$x['warning']], $plan['warnings']);
        $this->assertContains(['code' => 'mirror_inconsistent', 'session_id' => $x['sessions'][3][0]], $plan['findings']);
    }

    public function test_a_mirror_row_between_an_untouched_and_a_touched_contract_is_refused(): void
    {
        // The deduction of an untouched contract's lesson points at a contract a move touches: that contract's usage is wrong.
        $x = $this->decided();
        $sid = $x['sessions'][0][3];
        DB::table('session_deduction_ledger')->where('class_session_id', $sid)->update(['student_class_id' => $x['contracts'][3]]);
        $plan = $this->strategy()->plan($x['params']);
        $this->assertSame([false, ['mirror_inconsistent']], [$plan['ok'], $plan['errors']]);
        $this->assertContains(['code' => 'mirror_inconsistent', 'session_id' => $sid], $plan['findings']);
        $this->assertContains(['code' => 'mirror_inconsistent', 'contract_id' => $x['contracts'][3]], $plan['findings']);

        // Between two untouched contracts: warnings on both, the plan passes.
        $x = $this->decided();
        $sid = $x['sessions'][0][3];
        DB::table('session_deduction_ledger')->where('class_session_id', $sid)->update(['student_class_id' => $x['contracts'][1]]);
        $plan = $this->strategy()->plan($x['params']);
        $this->assertSame([true, []], [$plan['ok'], $plan['findings']]);
        $this->assertEqualsCanonicalizing([['code' => 'mirror_inconsistent', 'contract_id' => $x['contracts'][0], 'session_id' => $sid],
            ['code' => 'mirror_inconsistent', 'contract_id' => $x['contracts'][1], 'session_id' => null]], $plan['warnings']);
    }

    public function test_execute_under_the_scoped_rule_moves_only_the_listed_lessons_and_leaves_the_untouched_contract_as_it_was(): void
    {
        $x = $this->untouchedGap();
        $untouched = [$x['contracts'][0], $x['contracts'][1]];
        $before = $this->rowsOf($untouched);
        $money = $this->money();
        $moved = [$x['sessions'][2][8], $x['sessions'][3][7]];
        $others = array_values(array_diff(array_merge(...$x['sessions']), $moved));
        $ownersBefore = $this->owners($others);

        $run = $this->viaPop($this->service(), $x['params']);
        $this->assertSame(['succeeded', 'succeeded'], [$run['execute']['result'], $run['verify']['result']]);
        $this->assertSame([8, 8, 8, 8, 2], $this->taughtNow($x['contracts']));
        $this->assertSame([$x['contracts'][3], $x['contracts'][4]], $this->owners($moved));
        $this->assertSame($ownersBefore, $this->owners($others), 'no lesson outside the list changed its contract');
        $this->assertSame(2, DB::table('class_session_reassignments')->count());
        $this->assertSame($before, $this->rowsOf($untouched), 'the untouched contracts, their lessons and their mirror rows are field by field as before');
        $this->assertSame($money, $this->money());
        // The gap is still there, exactly as it was, and the receipt names it.
        $this->assertSame(7, DB::table('session_deduction_ledger')->where('student_class_id', $x['contracts'][0])->count());
        // The receipt and the snapshot are read back from MySQL JSON columns, which store object keys in their own order.
        $this->assertEquals([$x['warning']], $run['execute']['warnings']);
        $this->assertEquals([$x['warning']], $run['execute']['snapshot']['warnings']);
    }

    /** Threat: a narrower check must not let a move hide or worsen a gap on an untouched contract. */
    public function test_verify_holds_an_untouched_contract_to_the_gap_it_had_and_the_undo_still_works(): void
    {
        $x = $this->untouchedGap();
        $chain = $this->rowsOf($x['contracts']);
        $context = $this->context();
        $result = $this->strategy()->execute($this->pinned($x['params']), $context);
        $this->assertTrue($result['ok'], json_encode($result['errors'] ?? null));
        $this->assertSame([$x['warning']], $result['warnings']);
        $this->assertTrue($this->strategy()->verify([], $result)['ok']);

        // The gap grows after the execute: the untouched contract is no longer as recorded.
        $second = $x['sessions'][0][4];
        $row = (array) DB::table('session_deduction_ledger')->where('class_session_id', $second)->first();
        $this->removeDeduction($second);
        $verify = $this->strategy()->verify([], $result);
        $this->assertFalse($verify['ok']);
        $this->assertContains('mirror_inconsistent_' . $x['contracts'][0], $verify['errors']);
        DB::table('session_deduction_ledger')->insert($row);
        $this->assertTrue($this->strategy()->verify([], $result)['ok']);

        // A snapshot without the recorded figures (written before this rule) keeps the strict rule on every contract.
        $old = $result;
        foreach ($old['snapshot']['contracts'] as $i => $contract) {
            unset($old['snapshot']['contracts'][$i]['before']['usage']);
        }
        $this->assertContains('mirror_inconsistent_' . $x['contracts'][0], $this->strategy()->verify([], $old)['errors']);

        // The undo is not held back by the gap either, and returns the whole chain field by field.
        $back = $this->strategy()->rollback($result['snapshot'], $context);
        $this->assertSame([true, 2], [$back['ok'], $back['restored']]);
        $this->assertSame($chain, $this->rowsOf($x['contracts']));

        // Once the gap is repaired, the same manifest plans without a warning.
        DB::table('session_deduction_ledger')->insert(['student_class_id' => $x['contracts'][0], 'event_type' => 'deduct', 'source' => 'attendance',
            'class_session_id' => $x['gap'], 'created_at' => now(), 'updated_at' => now()]);
        $plan = $this->strategy()->plan($x['params']);
        $this->assertSame([true, []], [$plan['ok'], $plan['warnings']]);
    }

    /**
     * #5236 review P2e. The money fingerprint covers the chain's own contracts, invoices, items, payments and payment reports
     * only. A payment taken on the chain after the execute does change it: a later verification then answers
     * `money_changed`, which says the chain's money rows are not the fingerprinted ones, not that the moves wrote money.
     */
    public function test_verify_compares_only_the_money_of_the_chain(): void
    {
        $x = $this->decided();
        $this->invoice($x['contracts'][3], $x['student']);
        $result = $this->strategy()->execute($this->pinned($x['params']), $this->context());
        $this->assertTrue($result['ok'], json_encode($result['errors'] ?? null));
        $plan = $this->strategy()->plan($x['params']);

        // Money of another student, and of this student's contract of another subject, is outside the fingerprint.
        $other = $this->student();
        $elsewhere = (int) $this->contract($other)->ID;
        $this->invoice($elsewhere, $other);
        $otherSubject = (int) $this->contract($x['student'], 8, true, 77)->ID;
        $this->invoice($otherSubject, $x['student']);
        DB::table('StudentClass')->whereIn('ID', [$elsewhere, $otherSubject])->update(['Charge' => 1]);
        $this->assertSame([true, []], array_values(array_intersect_key($this->strategy()->verify($plan, $result), ['ok' => 1, 'errors' => 1])));

        // A payment on an invoice of the chain after the execute.
        $invoice = (int) DB::table('Invoice')->where('StudentClassID', $x['contracts'][3])->value('id');
        DB::table('Payment')->insert(['InvoiceID' => $invoice, 'Amount' => 100, 'Method' => 'cash', 'PaidAt' => now(), 'created_at' => now(), 'updated_at' => now()]);
        $this->assertSame([false, ['money_changed']], array_values(array_intersect_key($this->strategy()->verify($plan, $result), ['ok' => 1, 'errors' => 1])));
    }

    /**
     * Every row of these contracts and of their lessons, sign-ins, learning records and ledger rows, as arrays, so a failed
     * comparison names the table, row and column instead of two checksums. ClassSession.updated_at is left out (as in
     * `ownership()`): the reassigner stamps it on each move, so it says when a lesson last moved, not who owns it.
     *
     * @param list<int> $contracts
     * @return array<string,list<array<string,mixed>>>
     */
    private function rowsOf(array $contracts): array
    {
        $rows = fn (\Illuminate\Database\Query\Builder $query, string $key): array => $query->orderBy($key)->get()->map(fn (\stdClass $r): array => (array) $r)->all();

        return [
            'StudentClass' => $rows(DB::table('StudentClass')->whereIn('ID', $contracts), 'ID'),
            'ClassSession' => array_map(fn (array $r): array => array_diff_key($r, ['updated_at' => 1]),
                $rows(DB::table('ClassSession')->whereIn('StudentClassID', $contracts), 'id')),
            'StudentSingIn' => $rows(DB::table('StudentSingIn')->whereIn('StudentClassID', $contracts), 'id'),
            'LearningRecord' => $rows(DB::table('LearningRecord')->whereIn('StudentClassID', $contracts), 'id'),
            'session_deduction_ledger' => $rows(DB::table('session_deduction_ledger')->whereIn('student_class_id', $contracts), 'id'),
        ];
    }

    public function test_a_restore_request_returns_the_chain_field_by_field_and_touches_nothing_outside_it(): void
    {
        // #5236 CI: the restore test took a whole-table fingerprint, then added another student's contract, so the fingerprint
        // moved although the restore was exact. Here a row outside the chain is added between the execute and the restore on
        // purpose: the chain must come back column by column (counters, sign-in, learning record, ledger) and the outside
        // rows must stay as they are.
        $x = $this->decided();
        $this->mirrors($x['sessions'][2][8], $x['contracts'][2], $x['student']); // a sign-in and a learning record ride on a moved lesson
        $before = $this->rowsOf($x['contracts']);
        $counters = fn (): array => DB::table('StudentClass')->whereIn('ID', $x['contracts'])->orderBy('ID')->get(array_merge(['ID'], self::COUNTERS))
            ->map(fn (\stdClass $c): array => (array) $c)->all();
        $storedCounters = $counters();
        $whole = $this->ownership();
        $service = $this->service();
        $run = $this->viaPop($service, $x['params']);
        $this->assertSame(['succeeded', 'succeeded'], [$run['execute']['result'], $run['verify']['result']]);
        $this->assertNotSame($before, $this->rowsOf($x['contracts']));

        $other = $this->contract($this->student());
        $this->taught($other);
        $outside = $this->rowsOf([(int) $other->ID]);
        $this->assertSame([1, 1], [count($outside['ClassSession']), count($outside['session_deduction_ledger'])]);
        $inverse = $run['execute']['restore_moves'];
        $back = $this->viaPop($service, ['restore_request_id' => $run['id'], 'moves' => $inverse] + $this->params($x['student'], $x['contracts'], $inverse));

        $this->assertSame(['succeeded', 'succeeded', 'restore', 2], [$back['execute']['result'], $back['verify']['result'], $back['execute']['mode'], $back['execute']['moved']]);
        $this->assertSame($before, $this->rowsOf($x['contracts']), 'every contract, lesson, sign-in, learning record and ledger row of the chain is as before the execute');
        $this->assertSame($storedCounters, $counters(), 'the recomputed counters equal the stored pre-execute counters');
        $this->assertSame([8, 8, 9, 8, 1], $this->taughtNow($x['contracts']));
        $this->assertSame($outside, $this->rowsOf([(int) $other->ID]), 'the restore wrote nothing outside the chain');
        // A whole-table fingerprint also counts the row added outside the chain: it differs although the restore is exact.
        $this->assertNotSame($whole, $this->ownership());
    }

    public function test_pop_rollback_of_an_executed_request_restores_and_then_blocks_verify(): void
    {
        $x = $this->decided();
        $ownership = $this->ownership();
        $service = $this->service();
        $run = $this->viaPop($service, $x['params'], ['execute']);
        $this->assertSame('succeeded', $run['execute']['result']);

        $rollback = $service->run($run['id'], 'rollback', $run['token'], $run['sha'], 'pop-pi-local');

        $this->assertSame(['rolled_back', 2], [$rollback['result'], $rollback['restored']]);
        $this->assertSame($ownership, $this->ownership());
        $this->assertSame('rolled_back', DB::table('pop_operation_requests')->where('id', $run['id'])->value('status'));
        // The rollback receipt marks the request as undone: a restore request citing it is refused by record.
        $inverse = $run['execute']['restore_moves'];
        $cited = $this->strategy()->plan(['restore_request_id' => $run['id'], 'moves' => $inverse] + $this->params($x['student'], $x['contracts'], $inverse));
        $this->assertSame([['restore_already_applied'], $run['id']], [$cited['errors'], $cited['findings'][0]['restored_by_request_id']]);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('POP verification is blocked after rollback begins.');
        $service->run($run['id'], 'verify', $run['token'], $run['sha'], 'pop-pi-local');
    }

    // ── Catalog and approval policy ──

    public function test_the_entry_is_active_founder_approved_per_request_and_in_no_standing_or_automatic_policy(): void
    {
        $root = dirname(__DIR__, 4);
        $catalog = new PopOperationCatalog($root . '/operations/catalog.yaml');
        $service = new PopOperationService($catalog);
        $entry = $catalog->operation(self::OPERATION);
        $this->assertSame(['active', 'founder-explicit-chain-rebalance', true, ['super_admin'], 'pop-pi-local'],
            [$entry['lifecycle'], $entry['approval_policy'], $entry['founder_approval_required'], $entry['approver_roles'], $entry['execution_authority']]);
        $this->assertSame(['repair', 'plan', 'dry_run', 'verify', 'rollback', 'snapshot', 'audit'], $entry['capabilities']);
        $this->assertSame([true, true, true, true, true, true],
            [$entry['reversible'], $entry['rollback_supported'], $entry['snapshot_required'], $entry['transaction_required'], $entry['verification_required'], $entry['approval_required']]);
        $this->assertSame(CourseContractChainRebalanceStrategy::class, $entry['strategy_class']);

        // Not governed by the standing policy, not in the automatic-approval allowlists, and no workflow drives it.
        $this->assertFalse(InappStandingPolicy::governs($entry));
        $this->assertStringNotContainsString(self::OPERATION, (string) file_get_contents($root . '/scripts/governance/pop_auto_approve.py'));
        foreach (glob($root . '/.github/workflows/*.yml') ?: [] as $workflow) {
            $this->assertStringNotContainsString(self::OPERATION, (string) file_get_contents($workflow), basename($workflow));
        }
        $this->assertStringContainsString('founder_explicit: true', (string) preg_replace('/.*- id: founder-explicit-chain-rebalance(.*?)\n\n.*/s', '$1',
            (string) file_get_contents($root . '/operations/policies/default.yaml')));

        $roles = new ReflectionMethod($service, 'approvalRoles');
        $this->assertSame(['super_admin'], $roles->invoke($service, $entry));
        foreach ([['blast_radius' => 'global'], ['founder_approval_required' => false], ['rollback_supported' => false], ['execution_authority' => 'github-actions'],
            ['approver_roles' => ['director']]] as $change) {
            try {
                $roles->invoke($service, $change + $entry);
                $this->fail('a catalog shape other than the reviewed one must fail closed: ' . json_encode($change));
            } catch (RuntimeException $e) {
                $this->assertSame('POP approval policy is not supported by this execution slice; fail closed.', $e->getMessage());
            }
        }

        // Approval: a founder-go reference, by someone other than the requester; never a standing or automatic reference.
        $x = $this->decided();
        $draft = fn (string $digest): string => (string) $service->createDraft(self::OPERATION, ['expected_digest' => $digest] + $x['params'],
            'chain-exec-' . bin2hex(random_bytes(4)), 'user:1', 'super_admin', [], 1)['id'];
        $id = $draft((string) $service->runDryRun($draft(''), 'user:1', 1, 'super_admin', [])['plan']['digest']);
        $service->runDryRun($id, 'user:1', 1, 'super_admin', []);
        $sha = str_repeat('c', 40);
        $attempts = [
            'Founder-scoped POP approval requires a founder-go reference; fail closed.' => ['director-ok-' . substr($id, 0, 8), 'user:2', 'super_admin', 2],
            'Standing-A automatic approval is limited to the reviewed append-only operations; fail closed.' => ['standing-a-auto-pop-chain-rebalance-0123456789ab', 'user:2', 'super_admin', 2],
            'POP approval requires separation of duties.' => ['founder-go-chain-rebalance-' . substr($id, 0, 8), 'user:1', 'super_admin', 1],
            'POP approver role is not authorized for this operation.' => ['founder-go-chain-rebalance-' . substr($id, 0, 8), 'user:3', 'director', 3],
        ];
        foreach ($attempts as $message => [$reference, $approver, $role, $approverId]) {
            try {
                $service->approve($id, $reference, $approver, $role, $sha, $approverId, [$this->campusId], 15);
                $this->fail('this approval must be refused: ' . $message);
            } catch (RuntimeException $e) {
                $this->assertSame($message, $e->getMessage());
            }
        }
        $this->assertSame(0, DB::table('pop_approval_events')->where('operation_id', $id)->where('event_type', 'approved')->count());
        $this->assertSame([8, 8, 9, 8, 1], $this->taughtNow($x['contracts']));

        // Without an approval token nothing runs.
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('POP approval token is missing; fail closed.');
        $service->run($id, 'execute', null, $sha, 'pop-pi-local');
    }
}
