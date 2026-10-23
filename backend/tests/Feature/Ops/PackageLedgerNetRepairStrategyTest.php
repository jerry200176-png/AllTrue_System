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
        parent::tearDown();
    }

    public function test_dry_run_reports_ids_counts_and_remaining_only_and_writes_nothing(): void
    {
        $s = new PackageLedgerNetRepairStrategy();
        $plan = $s->plan(self::PARAMS);

        self::assertTrue($plan['ok'], implode(',', $plan['errors']));
        self::assertSame('before', $plan['state']);
        self::assertSame(['ok', 'errors', 'state', 'counts', 'digest', 'rows', 'packages'], array_keys($plan));
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

    private function lesson(StudentClass $course, string $date): int
    {
        return (int) ClassSession::create([
            'StudentClassID' => $course->ID, 'SessionDate' => $date, 'StartTime' => '16:00', 'EndTime' => '18:00', 'Status' => 'attended',
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
