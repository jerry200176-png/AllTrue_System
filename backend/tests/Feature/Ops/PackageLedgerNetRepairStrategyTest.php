<?php

namespace Tests\Feature\Ops;

use App\Models\ClassSession;
use App\Models\CoursePackage;
use App\Models\Student;
use App\Models\StudentClass;
use App\Models\User;
use App\Operations\Strategies\PackageLedgerNetRepairManifest;
use App\Operations\Strategies\PackageLedgerNetRepairStrategy;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use RuntimeException;
use Tests\TestCase;

/** GitHub #4455: synthetic rows shaped like the manifest (class A slot + lesson, class B with and without its ClassSession). */
final class PackageLedgerNetRepairStrategyTest extends TestCase
{
    use RefreshDatabase;

    private const PARAMS = ['decision_reference' => 'repair-package-ledger-net-4455'];
    private const GONE = 990001; // a class B session whose ClassSession row no longer exists

    private int $teacherId;
    private CoursePackage $group, $solo, $twice;
    private StudentClass $groupCourse, $soloCourse, $twiceCourse;
    private int $groupLesson, $soloLesson, $otherLesson, $twiceLesson;

    protected function setUp(): void
    {
        parent::setUp();
        $this->teacherId = (int) User::create([
            'LoginName' => 'repair-teacher-' . uniqid() . '@t.com', 'Name' => '合成老師', 'PSW' => 's', 'type' => 'T', 'phone' => 911000000,
        ])->id;
        // Class A, group slot: charged on the course, the pool debit credited back (#4275), so the slot nets to 0.
        [$this->group, $this->groupCourse] = $this->pool('one_on_two', 10);
        $this->groupLesson = $this->lesson($this->groupCourse, '2026-10-06');
        $this->charge($this->groupCourse, $this->groupLesson, ['deduct', 'reverse', 'deduct']);
        $this->ledger($this->group, $this->groupCourse, $this->groupLesson, 'attendance', -1);
        $this->ledger($this->group, $this->groupCourse, $this->groupLesson, 'status_adjust', 1);
        // Class A, one-on-one lesson charged and never debited; a second such lesson is not in the manifest.
        [$this->solo, $this->soloCourse] = $this->pool('one_on_one', 10);
        $this->soloLesson = $this->lesson($this->soloCourse, '2026-10-07');
        $this->charge($this->soloCourse, $this->soloLesson, ['deduct']);
        $this->otherLesson = $this->lesson($this->soloCourse, '2026-10-14');
        $this->charge($this->soloCourse, $this->otherLesson, ['deduct']);
        // Class B: debited as `attended` (rebuild) and `attendance`; one ClassSession row is gone.
        [$this->twice, $this->twiceCourse] = $this->pool('one_on_one', 6);
        $this->twiceLesson = $this->lesson($this->twiceCourse, '2026-10-08');
        foreach ([$this->twiceLesson, self::GONE] as $cs) {
            $this->ledger($this->twice, $cs === self::GONE ? $this->soloCourse : $this->twiceCourse, $cs, 'attended', -1);
            $this->ledger($this->twice, $this->twiceCourse, $cs, 'attendance', -1);
        }
        PackageLedgerNetRepairManifest::useRowsForTesting([
            ['class' => 'A', 'package_id' => (int) $this->group->id, 'class_session_id' => $this->groupLesson, 'scope' => 'slot'],
            ['class' => 'A', 'package_id' => (int) $this->solo->id, 'class_session_id' => $this->soloLesson, 'scope' => 'session'],
            ['class' => 'B', 'package_id' => (int) $this->twice->id, 'class_session_id' => $this->twiceLesson, 'scope' => 'session'],
            ['class' => 'B', 'package_id' => (int) $this->twice->id, 'class_session_id' => self::GONE, 'scope' => 'session'],
        ]);
    }

    protected function tearDown(): void
    {
        PackageLedgerNetRepairManifest::useRowsForTesting(null);
        PackageLedgerNetRepairManifest::useRowsForTesting(null, 2);
        PackageLedgerNetRepairManifest::useRowsForTesting(null, 3);
        parent::tearDown();
    }

    /**
     * Round 2, class R: a deleted lesson whose re-created lesson is debited too, a cancelled lesson alone in its slot,
     * a cancelled lesson beside a debited sibling, and a per-lesson package's cancelled lesson each get one +1 row; a
     * cancelled duplicate credited +1 beside the debited lesson it duplicated (slot net 0, #4470) gets one -1 row.
     * @return array{0: PackageLedgerNetRepairStrategy, 1: array<string, int>, 2: array<int, CoursePackage>}
     */
    private function overDebits(): array
    {
        [$group, $course] = $this->pool('one_on_two', 10);
        [, $sibling] = $this->pool('one_on_two', 10);
        DB::table('StudentClass')->where('ID', $sibling->ID)->update(['PackageID' => $group->id]);
        [$solo, $soloCourse] = $this->pool('one_on_one', 10);
        $ids = ['gone' => 990002, 'recreated' => $this->lesson($course, '2026-10-05'), 'alone' => $this->lesson($course, '2026-10-12', 'cancelled'),
            'cancelled' => $this->lesson($course, '2026-10-19', 'cancelled'), 'kept' => $this->lesson($sibling, '2026-10-19'),
            'solo' => $this->lesson($soloCourse, '2026-10-20', 'cancelled'), 'taught' => $this->lesson($course, '2026-10-26'),
            'duplicate' => $this->lesson($sibling, '2026-10-26', 'cancelled')];
        $this->ledger($group, $course, $ids['gone'], 'attended', -1);
        $this->charge($course, $ids['recreated'], ['deduct']);
        $this->ledger($group, $course, $ids['recreated'], 'attendance', -1);
        $this->ledger($group, $course, $ids['alone'], 'attendance', -1);
        $this->ledger($group, $course, $ids['cancelled'], 'attended', -1);
        $this->ledger($group, $sibling, $ids['kept'], 'attendance', -1);
        $this->ledger($solo, $soloCourse, $ids['solo'], 'attendance', -1);
        $this->charge($course, $ids['taught'], ['deduct']);
        $this->ledger($group, $course, $ids['taught'], 'attendance', -1);
        $this->ledger($group, $sibling, $ids['duplicate'], 'duplicate_session', 1);
        $r = fn (CoursePackage $p, int $cs, string $status, string $slot, string $scope = 'slot', int $net = -1): array =>
            ['class' => 'R', 'package_id' => (int) $p->id, 'class_session_id' => $cs, 'scope' => $scope, 'status' => $status, 'slot' => $slot, 'net' => $net];
        PackageLedgerNetRepairManifest::useRowsForTesting([
            $r($group, $ids['gone'], 'deleted', '2026-10-05 16:00'), $r($group, $ids['alone'], 'cancelled', '2026-10-12 16:00'),
            $r($group, $ids['cancelled'], 'cancelled', '2026-10-19 16:00'), $r($solo, $ids['solo'], 'cancelled', '2026-10-20 16:00', 'session'),
            $r($group, $ids['duplicate'], 'cancelled', '2026-10-26 16:00', 'slot', 1),
        ], 2);

        return [new PackageLedgerNetRepairStrategy(), $ids, [(int) $group->id => $group, (int) $solo->id => $solo]];
    }

    public function test_round_two_reverses_each_over_debit_once_verifies_and_rolls_back(): void
    {
        [$s, $ids, $packages] = $this->overDebits();
        [$g, $o] = array_keys($packages);
        $plan = $s->plan(['decision_reference' => 'repair-package-ledger-overdebit-4455']);
        self::assertTrue($plan['ok'], implode(',', $plan['errors']));
        self::assertSame([2, ['rows' => 5, 'to_insert' => 5, 'done' => 0, 'blocked' => 0, 'packages' => 2]], [$plan['round'], $plan['counts']]);
        self::assertSame([$g => ['remaining_before' => 10, 'remaining_after' => 7, 'ledger_net_before' => -5, 'ledger_net_after' => -3],
            $o => ['remaining_before' => 10, 'remaining_after' => 10, 'ledger_net_before' => -1, 'ledger_net_after' => 0]], $plan['packages']);
        self::assertSame(4, $s->plan(self::PARAMS)['counts']['rows'], 'round 1 still reads its own manifest');

        $result = $s->execute($plan, ['operation_id' => 'req-r2']);
        self::assertSame(5, $result['inserted']);
        $rows = DB::table('package_session_ledger')->where('reason', PackageLedgerNetRepairStrategy::REASON_R2)->orderBy('id')
            ->get(['class_session_id', 'delta', 'note'])->map(fn ($r): array => (array) $r)->all();
        self::assertSame(array_map(fn (array $r): array => ['class_session_id' => $r[0], 'delta' => $r[1], 'note' => '#4455 class R'],
            [[$ids['gone'], 1], [$ids['alone'], 1], [$ids['cancelled'], 1], [$ids['solo'], 1], [$ids['duplicate'], -1]]), $rows);
        self::assertSame([[7, 3], [10, 0]], array_map(fn (CoursePackage $p): array => [(int) $p->fresh()->remaining_sessions, (int) $p->fresh()->used_sessions], array_values($packages)));
        self::assertTrue($s->verify($plan, $result)['ok']);
        $again = $s->plan(['decision_reference' => 'repair-package-ledger-overdebit-4455']);
        self::assertSame([true, 'after', 5], [$again['ok'], $again['state'], $again['counts']['done']]);

        self::assertSame(['ok' => true, 'partial' => false, 'deleted' => 5, 'skipped' => []], $s->rollback($result['snapshot'], []));
        self::assertSame(0, DB::table('package_session_ledger')->where('reason', PackageLedgerNetRepairStrategy::REASON_R2)->count());
    }

    public function test_round_two_fails_closed_when_a_lesson_is_taught_charged_or_its_slot_no_longer_holds_one_debit(): void
    {
        [$s, $ids] = $this->overDebits();
        DB::table('ClassSession')->where('id', $ids['alone'])->update(['Status' => 'attended']); // marked taught since the scan
        $this->charge(StudentClass::query()->find(DB::table('ClassSession')->where('id', $ids['solo'])->value('StudentClassID')), $ids['solo'], ['deduct']);
        DB::table('package_session_ledger')->where('class_session_id', $ids['kept'])->delete(); // the sibling no longer holds the slot's debit
        DB::table('package_session_ledger')->where('class_session_id', $ids['recreated'])->delete();
        $this->ledger(CoursePackage::query()->find(DB::table('package_session_ledger')->where('class_session_id', $ids['gone'])->value('package_id')),
            StudentClass::query()->find(DB::table('ClassSession')->where('id', $ids['recreated'])->value('StudentClassID')), $ids['gone'], 'attendance', -1);

        $plan = $s->plan(['decision_reference' => 'repair-package-ledger-overdebit-4455']);
        self::assertSame(["r_net_{$ids['gone']}", "r_slot_net_{$ids['gone']}", "r_status_{$ids['alone']}", "r_slot_net_{$ids['alone']}", "r_slot_net_{$ids['cancelled']}",
            "r_course_net_{$ids['solo']}"], $plan['errors']);
        self::assertSame(0, DB::table('package_session_ledger')->where('reason', PackageLedgerNetRepairStrategy::REASON_R2)->count());
    }

    public function test_round_three_reverses_its_own_manifest_under_its_own_reason(): void
    {
        [$group, $course] = $this->pool('one_on_three', 10);
        $this->ledger($group, $course, 990003, 'attended', -1); // a creation seed whose lesson was deleted
        PackageLedgerNetRepairManifest::useRowsForTesting([['class' => 'R', 'package_id' => (int) $group->id, 'class_session_id' => 990003,
            'scope' => 'slot', 'status' => 'deleted', 'slot' => '2026-07-12 16:00', 'net' => -1]], 3);
        $s = new PackageLedgerNetRepairStrategy();
        $plan = $s->plan(['decision_reference' => 'repair-package-ledger-overdebit-r3-4455']);
        self::assertSame([true, 3, 1], [$plan['ok'], $plan['round'], $plan['counts']['to_insert']]);
        $result = $s->execute($plan, ['operation_id' => 'req-r3']);
        self::assertSame([[990003, 1]], DB::table('package_session_ledger')->where('reason', PackageLedgerNetRepairStrategy::REASON_R3)
            ->get(['class_session_id', 'delta'])->map(fn ($r): array => [(int) $r->class_session_id, (int) $r->delta])->all());
        self::assertTrue($s->verify($plan, $result)['ok']);
        self::assertSame(1, $s->rollback($result['snapshot'], [])['deleted']);
    }

    public function test_round_two_keeps_one_debit_in_a_slot_whose_reversed_lesson_is_attended(): void
    {
        [$group, $course] = $this->pool('one_on_three', 10);
        [, $sibling] = $this->pool('one_on_three', 10);
        DB::table('StudentClass')->where('ID', $sibling->ID)->update(['PackageID' => $group->id]);
        [$second, $first] = [$this->lesson($course, '2026-10-05'), $this->lesson($sibling, '2026-10-05')];
        $this->ledger($group, $course, $second, 'attended', -1);
        $this->ledger($group, $sibling, $first, 'attended', -1);
        PackageLedgerNetRepairManifest::useRowsForTesting([['class' => 'R', 'package_id' => (int) $group->id, 'class_session_id' => $second,
            'scope' => 'slot', 'status' => 'attended', 'slot' => '2026-10-05 16:00', 'net' => -1]], 2);
        $s = new PackageLedgerNetRepairStrategy();
        self::assertTrue($s->plan(['decision_reference' => 'repair-package-ledger-overdebit-4455'])['ok'], 'second debit of a slot');

        DB::table('ClassSession')->where('id', $first)->update(['Status' => 'cancelled']); // the sibling cancelled and credited since
        $this->ledger($group, $sibling, $first, 'retro_leave', 1);
        self::assertSame(["r_slot_net_{$second}"], $s->plan(['decision_reference' => 'repair-package-ledger-overdebit-4455'])['errors']);
    }

    public function test_dry_run_reports_ids_counts_and_remaining_only_and_writes_nothing(): void
    {
        $s = new PackageLedgerNetRepairStrategy();
        $plan = $s->plan(self::PARAMS);

        self::assertTrue($plan['ok'], implode(',', $plan['errors']));
        self::assertSame('before', $plan['state']);
        self::assertSame(['ok', 'errors', 'state', 'counts', 'digest', 'round', 'rows', 'packages'], array_keys($plan));
        self::assertSame(['rows' => 4, 'to_insert' => 4, 'done' => 0, 'blocked' => 0, 'packages' => 3], $plan['counts']);
        self::assertSame(array_fill_keys([
            "A:{$this->group->id}:{$this->groupLesson}", "A:{$this->solo->id}:{$this->soloLesson}",
            "B:{$this->twice->id}:{$this->twiceLesson}", "B:{$this->twice->id}:" . self::GONE,
        ], 'insert'), $plan['rows']);
        self::assertSame([
            (int) $this->group->id => ['remaining_before' => 10, 'remaining_after' => 9, 'ledger_net_before' => 0, 'ledger_net_after' => -1],
            (int) $this->solo->id => ['remaining_before' => 10, 'remaining_after' => 9, 'ledger_net_before' => 0, 'ledger_net_after' => -1],
            (int) $this->twice->id => ['remaining_before' => 6, 'remaining_after' => 8, 'ledger_net_before' => -4, 'ledger_net_after' => -2],
        ], $plan['packages']);
        self::assertSame(['case_parameters_mismatch'], $s->plan([])['errors']);
        self::assertSame(0, $this->repairRows()->count());
    }

    public function test_execute_writes_exactly_the_expected_rows_and_counters_verifies_and_re_execute_is_idempotent(): void
    {
        [$s, $plan, $result] = $this->applied();

        self::assertSame(4, $result['inserted']);
        [$g, $o, $t] = [(int) $this->groupCourse->ID, (int) $this->soloCourse->ID, (int) $this->twiceCourse->ID];
        self::assertSame([
            [(int) $this->group->id, $g, $this->groupLesson, -1, '#4455 class A', 'req-4455'],
            [(int) $this->solo->id, $o, $this->soloLesson, -1, '#4455 class A', 'req-4455'],
            [(int) $this->twice->id, $t, $this->twiceLesson, 1, '#4455 class B', 'req-4455'],
            [(int) $this->twice->id, $t, self::GONE, 1, '#4455 class B', 'req-4455'], // student class from the `attendance` row
        ], $this->repairRows()->map(fn ($r): array => [(int) $r->package_id, (int) $r->student_class_id, (int) $r->class_session_id,
            (int) $r->delta, (string) $r->note, (string) $r->request_id])->all());
        self::assertSame([[9, 1], [9, 1], [8, 2]], $this->allCounters());
        self::assertSame(0, DB::table('package_session_ledger')->where('class_session_id', $this->otherLesson)->count());
        self::assertTrue($s->verify($plan, $result)['ok']);

        $again = $s->plan(self::PARAMS);
        self::assertSame([true, 'after', 4], [$again['ok'], $again['state'], $again['counts']['done']]);
        $retry = $s->execute($again, ['operation_id' => 'req-4455']);
        self::assertSame([0, true], [$retry['inserted'], $retry['already_applied']]);
        self::assertSame($result['snapshot']['rows'], $retry['snapshot']['rows'], 'a retry of the same request keeps its rollback rows');
        self::assertSame([], $s->execute($again, ['operation_id' => 'req-other'])['snapshot']['rows'], 'another request owns no row');
        self::assertSame([4, [[9, 1], [9, 1], [8, 2]]], [$this->repairRows()->count(), $this->allCounters()]);
    }

    public function test_a_precondition_mismatch_fails_closed_in_the_plan_and_again_under_lock(): void
    {
        $s = new PackageLedgerNetRepairStrategy();
        $stale = $s->plan(self::PARAMS);
        self::assertTrue($stale['ok']);
        $this->ledger($this->group, $this->groupCourse, $this->groupLesson, 'attendance#2', -1); // class A already debited
        DB::table('ClassSession')->where('id', $this->soloLesson)->update(['Status' => 'cancelled']);
        $this->charge($this->soloCourse, $this->soloLesson, ['reverse']);
        DB::table('package_session_ledger')->where('class_session_id', self::GONE)->where('reason', 'attended')->delete(); // B net -1

        $plan = $s->plan(self::PARAMS);
        self::assertFalse($plan['ok']);
        self::assertSame(["a_package_net_{$this->groupLesson}", "a_session_net_{$this->groupLesson}", "a_status_{$this->soloLesson}",
            "a_course_net_{$this->soloLesson}", 'b_net_' . self::GONE, 'b_rows_' . self::GONE], $plan['errors']);
        self::assertSame(['blocked', 'blocked', 'insert', 'blocked'], array_values($plan['rows']));
        try {
            $s->execute($stale, ['operation_id' => 'req-4455']);
            self::fail('a stale plan must be re-checked under lock');
        } catch (RuntimeException $e) {
            self::assertStringStartsWith('package_ledger_drift:', $e->getMessage());
        }
        self::assertSame([0, [[10, 0], [10, 0], [6, 4]]], [$this->repairRows()->count(), $this->allCounters()]);
    }

    public function test_a_slot_netting_zero_over_a_sibling_credit_is_not_debited_again(): void
    {
        // The target already carries a debit; a +1 on another member's lesson in the same slot brings the slot net to 0.
        $this->ledger($this->group, $this->groupCourse, $this->groupLesson, 'attendance#2', -1);
        [, $sibling] = $this->pool('one_on_two', 10);
        DB::table('StudentClass')->where('ID', $sibling->ID)->update(['PackageID' => $this->group->id]);
        $this->ledger($this->group, $sibling, $this->lesson($sibling, '2026-10-06'), 'status_adjust', 1);

        $plan = (new PackageLedgerNetRepairStrategy())->plan(self::PARAMS);
        self::assertSame(["a_session_net_{$this->groupLesson}"], $plan['errors'], 'the slot nets 0 but the lesson is already debited');
    }

    public function test_the_committed_manifest_is_the_scanned_case(): void
    {
        PackageLedgerNetRepairManifest::useRowsForTesting(null);
        $rows = PackageLedgerNetRepairManifest::rows();
        self::assertSame([52, 15, 37, 52, 15], [count($rows), count(array_filter($rows, fn (array $r): bool => $r['class'] === 'A')),
            count(array_filter($rows, fn (array $r): bool => $r['class'] === 'B')), count(array_unique(array_column($rows, 'class_session_id'))),
            count(array_unique(array_column($rows, 'package_id')))]);
        $r2 = PackageLedgerNetRepairManifest::rows(2);
        self::assertSame([39, 39, 8, 37, 2], [count($r2), count(array_unique(array_column($r2, 'class_session_id'))),
            count(array_unique(array_column($r2, 'package_id'))), count(array_filter($r2, fn (array $r): bool => $r['net'] === -1)),
            count(array_filter($r2, fn (array $r): bool => $r['net'] === 1))]);
        self::assertSame([], array_intersect(array_column($r2, 'class_session_id'), array_column($rows, 'class_session_id')), 'no lesson in both rounds');
        $r3 = PackageLedgerNetRepairManifest::rows(3);
        self::assertSame([5, 2, -5], [count($r3), count(array_unique(array_column($r3, 'package_id'))), array_sum(array_column($r3, 'net'))]);
        self::assertSame([], array_intersect(array_column($r3, 'class_session_id'), [...array_column($rows, 'class_session_id'),
            ...array_column($r2, 'class_session_id')]), 'no lesson in two rounds');
    }

    public function test_rollback_restores_the_exact_prior_state_and_leaves_a_row_that_moved_on(): void
    {
        $before = $this->state();
        [$s, , $result] = $this->applied();
        self::assertNotSame($before, $this->state());
        self::assertSame(['ok' => true, 'partial' => false, 'deleted' => 4, 'skipped' => []], $s->rollback($result['snapshot'], []));
        self::assertSame($before, $this->state());

        [, $plan, $again] = $this->applied('req-second');
        $this->ledger($this->solo, $this->soloCourse, $this->soloLesson, 'retro_leave', 1); // the lesson moved on since the repair
        self::assertSame(["net_{$this->soloLesson}", "counters_{$this->solo->id}"], $s->verify($plan, $again)['errors']);
        $partial = $s->rollback($again['snapshot'], []);
        self::assertSame([false, true, 3, ["A:{$this->solo->id}:{$this->soloLesson}"]],
            [$partial['ok'], $partial['partial'], $partial['deleted'], $partial['skipped']]);

        $this->expectException(RuntimeException::class);
        $s->rollback(['rows' => ['Z:0:0' => 5]], []);
    }

    /** @return array{0: PackageLedgerNetRepairStrategy, 1: array, 2: array} */
    private function applied(string $requestId = 'req-4455'): array
    {
        $s = new PackageLedgerNetRepairStrategy();
        $plan = $s->plan(self::PARAMS);
        self::assertTrue($plan['ok'], implode(',', $plan['errors']));

        return [$s, $plan, $s->execute($plan, ['operation_id' => $requestId])];
    }

    private function repairRows(): \Illuminate\Support\Collection
    {
        return DB::table('package_session_ledger')->where('reason', PackageLedgerNetRepairStrategy::REASON)->orderBy('id')->get();
    }

    /** @return list<array{0: int, 1: int}> remaining, used of the group, solo and twice packages */
    private function allCounters(): array
    {
        return array_map(fn (CoursePackage $p): array => array_values(array_map('intval', (array) DB::table('course_packages')
            ->where('id', $p->id)->first(['remaining_sessions', 'used_sessions']))), [$this->group, $this->solo, $this->twice]);
    }

    private function state(): array
    {
        return [DB::table('package_session_ledger')->orderBy('id')->get(['id', 'package_id', 'class_session_id', 'reason', 'delta'])
            ->map(fn ($r): array => (array) $r)->all(), $this->allCounters()];
    }

    /** @return array{0: CoursePackage, 1: StudentClass} */
    private function pool(string $classType, int $remaining): array
    {
        $student = Student::create([
            'name' => '合成學生' . uniqid(), 'CampusID' => 1, 'ClassID' => 1, 'enable' => 1, 'MDT' => now(), 'Notify_Token' => '',
        ]);
        $package = CoursePackage::create([
            'student_id' => $student->id, 'campus_id' => 1, 'name' => '合成方案', 'total_sessions' => 10, 'remaining_sessions' => $remaining,
            'used_sessions' => 10 - $remaining, 'rate' => 500, 'rate_unit' => 'session', 'class_type' => $classType,
            'paid' => false, 'stop' => false, 'enabled' => true,
        ]);

        return [$package, StudentClass::create([
            'StudentID' => $student->id, 'GradeID' => 1, 'SubjectID' => 1, 'TeacherID' => $this->teacherId, 'by1' => 1, 'Period' => 4,
            'StartDate' => Carbon::today()->toDateString(), 'TotalHours' => 0, 'ScheduleMode' => 'count', 'SessionCount' => 10,
            'RemainingSessions' => 10, 'UsedSessions' => 0, 'SessionDuration' => 120, 'ClassType' => 'one_on_one', 'Rate' => 500,
            'rate_unit' => 'session', 'Charge' => 0, 'Pay' => 0, 'Paid' => 0, 'Stop' => 0,
            'PackageID' => $package->id, 'PackageTotalSessions' => 10, 'PackageName' => $package->name,
        ])];
    }

    private function lesson(StudentClass $course, string $date, string $status = 'attended'): int
    {
        return (int) ClassSession::create([
            'StudentClassID' => $course->ID, 'SessionDate' => $date, 'StartTime' => '16:00', 'EndTime' => '18:00', 'Status' => $status,
        ])->id;
    }

    private function ledger(CoursePackage $package, StudentClass $course, int $sessionId, string $reason, int $delta): void
    {
        DB::table('package_session_ledger')->insert(['package_id' => $package->id, 'student_class_id' => $course->ID,
            'class_session_id' => $sessionId, 'delta' => $delta, 'reason' => $reason, 'created_at' => now(), 'updated_at' => now()]);
    }

    /** @param list<string> $events course ledger events for the lesson, in order */
    private function charge(StudentClass $course, int $sessionId, array $events): void
    {
        foreach ($events as $event) {
            DB::table('session_deduction_ledger')->insert(['student_class_id' => $course->ID, 'class_session_id' => $sessionId,
                'event_type' => $event, 'source' => 'attendance', 'created_at' => now(), 'updated_at' => now()]);
        }
    }
}
