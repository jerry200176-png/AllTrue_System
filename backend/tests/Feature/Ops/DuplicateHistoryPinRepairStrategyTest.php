<?php

namespace Tests\Feature\Ops;

use App\Operations\Strategies\DuplicateHistoryPinRepairStrategy;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/** GitHub #4901 (in-app #499): detect and delete the duplicate history pins; synthetic ids shaped like the real course 3399 case. */
final class DuplicateHistoryPinRepairStrategyTest extends TestCase
{
    use RefreshDatabase;

    private const REF = 'repair-duplicate-history-pins-20261104';
    private const OLD = 246;
    private const NEW = 67;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow(Carbon::parse('2026-11-04 10:00:00', 'Asia/Taipei'));
        DB::table('Student')->insert(['id' => 248, 'name' => 's', 'CampusID' => 17, 'ClassID' => 1, 'enable' => 1]);
        DB::table('Student')->insert(['id' => 250, 'name' => 's', 'CampusID' => 17, 'ClassID' => 1, 'enable' => 1]);
        DB::table('Student')->insert(['id' => 249, 'name' => 's', 'CampusID' => 18, 'ClassID' => 1, 'enable' => 1]);
        $this->course(3399, 248, self::NEW);
        // 10-18: older pin pair 14047/14048 at the real teacher, duplicate pair 15638/15639 at the former one.
        $this->slot(3399, 44047, '2026-10-18', self::NEW, [[14047, 14048, self::NEW], [15638, 15639, self::OLD]]);
        // 10-25: same shape, ids 14855/14856 and 15640/15641.
        $this->slot(3399, 44048, '2026-10-25', self::NEW, [[14855, 14856, self::NEW], [15640, 15641, self::OLD]]);
        // a lone pin at a third teacher over a sign-in: review only.
        $this->course(3400, 249, self::OLD);
        $this->slot(3400, 44100, '2026-10-18', self::NEW, [[16000, 16001, 999]]);
        // a real substitute pin: sign-in stamped the contract teacher, no live record. Single pin naming someone else: review only.
        $this->course(3401, 250, self::NEW);
        $this->slot(3401, 44200, '2026-10-18', self::NEW, [[16100, 16101, 999]]);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function params(int $campus = 0, string $digest = ''): array
    {
        return ['campus_id' => $campus, 'decision_reference' => self::REF, 'expected_digest' => $digest];
    }

    private function course(int $id, int $student, int $teacher): void
    {
        DB::table('StudentClass')->insert(['ID' => $id, 'StudentID' => $student, 'GradeID' => 1, 'SubjectID' => 1, 'TeacherID' => $teacher, 'by1' => 1,
            'Period' => 4, 'Pay' => 0, 'Rate' => 1500, 'TotalHours' => 4, 'StartDate' => '2026-08-15', 'ClassType' => 'one_on_one', 'ScheduleMode' => 'count',
            'SessionCount' => 40, 'Stop' => 0, 'Paid' => 1, 'Charge' => 60000]);
    }

    /** @param list<array{0:int,1:int,2:int}> $pairs (anchor id, child id, teacher id) */
    private function slot(int $course, int $session, string $date, int $signInTeacher, array $pairs): void
    {
        DB::table('ClassSession')->insert(['id' => $session, 'StudentClassID' => $course, 'SessionDate' => $date, 'StartTime' => '13:00:00',
            'EndTime' => '15:00:00', 'Status' => 'attended', 'created_at' => now(), 'updated_at' => now()]);
        DB::table('StudentSingIn')->insert(['StudentClassID' => $course, 'StudentID' => [3399 => 248, 3400 => 249, 3401 => 250][$course], 'TeacherID' => $signInTeacher, 'ClassSessionID' => $session,
            'SignInDT' => "$date 13:00:00", 'Status' => 'present', 'GradeID' => 1, 'SubjectID' => 1, 'MDT' => now()]);
        foreach ($pairs as [$anchor, $child, $teacher]) {
            $row = ['student_id' => [3399 => 248, 3400 => 249, 3401 => 250][$course], 'teacher_id' => $teacher, 'subject' => 'x', 'day_of_week' => 7, 'start_time' => '13:00',
                'end_time' => '15:00', 'duration_hours' => 2, 'class_type' => 'one_on_one', 'type' => 'normal', 'deduction' => 1, 'branch_id' => 17,
                'schedule_date' => $date, 'student_course_id' => $course, 'created_at' => now(), 'updated_at' => now()];
            DB::table('schedules')->insert($row + ['id' => $anchor, 'status' => 'rescheduled', 'original_schedule_id' => null]);
            DB::table('schedules')->insert($row + ['id' => $child, 'status' => 'scheduled', 'original_schedule_id' => $anchor]);
        }
    }

    public function test_plan_lists_exactly_the_duplicate_pairs_and_only_reviews_the_rest(): void
    {
        $plan = (new DuplicateHistoryPinRepairStrategy())->plan($this->params());
        self::assertTrue($plan['ok']);
        $delete = array_values(array_filter($plan['manifest'], fn ($r) => $r['class'] === 'delete'));
        self::assertSame([[15639, 15638], [15641, 15640]], array_map(fn ($r) => [$r['pin_id'], $r['anchor_id']], $delete));
        self::assertEquals(['delete' => 2, 'review' => 2, 'rows' => 4], $plan['totals']);
        self::assertSame([16001, 16101], array_column(array_filter($plan['manifest'], fn ($r) => $r['class'] === 'review'), 'pin_id'));
        self::assertSame(2, (new DuplicateHistoryPinRepairStrategy())->plan($this->params(17))['totals']['delete']);
        self::assertSame(0, (new DuplicateHistoryPinRepairStrategy())->plan($this->params(18))['totals']['delete']);
    }

    public function test_execute_is_pinned_deletes_exactly_the_listed_rows_audits_and_rolls_back(): void
    {
        $s = new DuplicateHistoryPinRepairStrategy();
        $digest = $s->plan($this->params())['digest'];
        self::assertSame('digest_mismatch', $s->plan($this->params(0, str_repeat('a', 64)))['state']);
        $before = DB::table('schedules')->count();
        $plan = $s->plan($this->params(0, $digest));
        self::assertSame('pinned', $plan['state']);

        $result = $s->execute($plan, ['operation_id' => 't']);
        self::assertSame(4, $result['deleted']);
        self::assertSame($before - 4, DB::table('schedules')->count());
        foreach ([15638, 15639, 15640, 15641] as $id) {
            self::assertFalse(DB::table('schedules')->where('id', $id)->exists());
        }
        foreach ([14047, 14048, 14855, 14856, 16000, 16001, 16100, 16101] as $id) {
            self::assertTrue(DB::table('schedules')->where('id', $id)->exists());
        }
        self::assertTrue(DB::table('security_audit_events')->where('event_type', 'pop.duplicate_history_pin_repair')->exists());
        self::assertTrue($s->verify($plan, $result)['ok']);
        self::assertSame('after', $s->plan($this->params(0, $digest))['state']);

        self::assertSame(4, $s->rollback($result['snapshot'], [])['restored']);
        self::assertSame($before, DB::table('schedules')->count());
    }

    public function test_an_anchor_with_another_live_child_is_reviewed_not_deleted(): void
    {
        DB::table('schedules')->where('id', 15639)->get()->each(function ($r) {
            $copy = (array) $r;
            $copy['id'] = 17000;
            DB::table('schedules')->insert($copy);
        });
        $plan = (new DuplicateHistoryPinRepairStrategy())->plan($this->params());
        $reasons = array_column(array_filter($plan['manifest'], fn ($r) => $r['anchor_id'] === 15638), 'reason');
        self::assertSame(['shared_anchor', 'shared_anchor'], $reasons);
    }

    public function test_execute_refuses_a_drifted_digest(): void
    {
        $s = new DuplicateHistoryPinRepairStrategy();
        $plan = $s->plan($this->params(0, $s->plan($this->params())['digest']));
        DB::table('StudentSingIn')->where('ClassSessionID', 44047)->update(['TeacherID' => self::OLD]);
        $this->expectExceptionMessage('duplicate_pin_digest_drift');
        $s->execute($plan, []);
    }
}
