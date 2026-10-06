<?php

namespace Tests\Feature\Ops;

use App\Models\ClassSession;
use App\Models\LearningRecord;
use App\Models\Schedule;
use App\Models\ScheduleChangeLog;
use App\Models\Student;
use App\Models\StudentClass;
use App\Models\User;
use App\Operations\Strategies\Td076CollisionKeepersStrategy;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use RuntimeException;
use Tests\TestCase;

final class Td076CollisionKeepersStrategyTest extends TestCase
{
    use RefreshDatabase;

    private const REF = 'repair-td076-r1-collision-keepers-20261006';

    private StudentClass $sc;
    private int $contract;
    private int $subB;
    private int $subC;

    protected function setUp(): void
    {
        parent::setUp();
        $mk = fn (string $n) => (int) User::create(['LoginName' => "{$n}@td076.example.com", 'Name' => $n, 'PSW' => 'x', 'type' => 'T',
            'phone' => '09' . random_int(10000000, 99999999), 'MustChangePassword' => false])->id;
        [$this->contract, $this->subB, $this->subC] = [$mk('a'), $mk('b'), $mk('c')];
        $stu = Student::create(['name' => 's', 'CampusID' => 1, 'ClassID' => 1, 'enable' => 1, 'MDT' => now(), 'Notify_Token' => '']);
        $this->sc = StudentClass::create([
            'StudentID' => $stu->id, 'GradeID' => 1, 'SubjectID' => 1, 'TeacherID' => $this->contract, 'by1' => 1,
            'Period' => 8, 'StartDate' => '2026-04-01', 'TotalHours' => 16, 'Charge' => 0, 'Rate' => 1000,
            'SessionCount' => 8, 'RemainingSessions' => 5, 'SessionDuration' => 120, 'week' => 5, 'time' => '16:00:00',
            'class_type' => 'one_on_one', 'ScheduleMode' => 'count', 'Stop' => 0, 'Paid' => 1, 'MDT' => now(),
        ]);
    }

    /** The 41612 shape: two substitute chains on one slot; the session's learning record names the newer chain's teacher. */
    public function test_two_chains_keep_the_newest_effective_teacher_row_and_dry_run_writes_nothing(): void
    {
        $this->twoChains();
        $session = $this->makeSession('2026-04-10');
        $this->lr($session, $this->subC);
        $before = DB::table('schedules')->get()->toArray();

        $plan = $this->strategy()->plan($this->params());

        $this->assertSame('unpinned', $plan['state']);
        $this->assertSame([['type' => 'supersede', 'schedule_id' => 12696, 'keeper_id' => 12698, 'from_status' => 'scheduled', 'teacher_id' => $this->subB]], $plan['manifest']['actions']);
        $this->assertSame([], $plan['manifest']['quarantine']);
        $this->assertEquals($before, DB::table('schedules')->get()->toArray(), 'dry-run writes nothing');
        $this->assertSame(0, ScheduleChangeLog::count());
        $this->assertSame(0, DB::table('security_audit_events')->count());
        $this->assertStringNotContainsString('@', json_encode($plan));

        $pinned = $this->strategy()->plan($this->params($plan['digest']));
        $result = $this->strategy()->execute($pinned, ['operation_id' => 'op-1']);

        $this->assertSame('superseded', Schedule::find(12696)->status);
        $this->assertSame('scheduled', Schedule::find(12698)->status);
        $log = ScheduleChangeLog::firstOrFail();
        $this->assertSame(['repair_supersede', 'scheduled', 'superseded', 12696], [$log->reason, $log->from_status, $log->to_status, (int) $log->schedule_id]);
        $this->assertSame($this->subB, (int) $log->from_teacher_id);
        $this->assertTrue($this->strategy()->verify($this->strategy()->plan($this->params($plan['digest'])), $result)['ok']);

        // Inverse: old status back, plus a log row.
        $this->assertTrue($this->strategy()->rollback($result['snapshot'], [])['ok']);
        $this->assertSame('scheduled', Schedule::find(12696)->status);
        $this->assertSame(2, ScheduleChangeLog::count());
        $this->assertSame('superseded', ScheduleChangeLog::orderByDesc('id')->first()->from_status);
    }

    public function test_ambiguous_groups_are_quarantined_and_left_alone(): void
    {
        $this->twoChains(); // no session at all
        $plan = $this->strategy()->plan($this->params());
        $this->assertSame([], $plan['manifest']['actions']);
        $this->assertSame([['reason' => 'no_session', 'schedule_ids' => [12696, 12698]]], $plan['manifest']['quarantine']);

        $this->lr($this->makeSession('2026-04-10'), $this->contract); // effective teacher matches neither substitute row
        $plan = $this->strategy()->plan($this->params());
        $this->assertSame('teacher_conflict', $plan['manifest']['quarantine'][0]['reason']);

        $result = $this->strategy()->execute($this->strategy()->plan($this->params($plan['digest'])), []);
        $this->assertSame(0, $result['applied']);
        $this->assertSame(['scheduled', 'scheduled'], [Schedule::find(12696)->status, Schedule::find(12698)->status]);
    }

    public function test_frozen_identity_that_differs_from_the_anchor_slot_is_quarantined(): void
    {
        $this->twoChains();
        $this->lr($this->makeSession('2026-04-10'), $this->subC);
        Schedule::whereKey(12696)->update(['original_schedule_date' => '2026-04-03']);

        $plan = $this->strategy()->plan($this->params());

        $this->assertSame([], $plan['manifest']['actions']);
        $this->assertSame('identity_anchor_mismatch', $plan['manifest']['quarantine'][0]['reason']);
    }

    public function test_execute_refuses_unpinned_or_wrong_digest(): void
    {
        $this->twoChains();
        $this->lr($this->makeSession('2026-04-10'), $this->subC);

        $wrong = $this->strategy()->plan($this->params(str_repeat('a', 64)));
        $this->assertSame(['digest_mismatch'], $wrong['errors']);
        foreach ([$wrong, $this->strategy()->plan($this->params())] as $plan) {
            try {
                $this->strategy()->execute($plan, []);
                $this->fail('execute must refuse');
            } catch (RuntimeException $e) {
                $this->assertSame('td076_plan_not_pinned', $e->getMessage());
            }
        }
        $this->assertSame('scheduled', Schedule::find(12696)->status);
    }

    private function strategy(): Td076CollisionKeepersStrategy
    {
        return app(Td076CollisionKeepersStrategy::class);
    }

    /** @return array<string,mixed> */
    private function params(string $digest = ''): array
    {
        return ['campus_id' => 1, 'decision_reference' => self::REF, 'expected_digest' => $digest];
    }

    private function makeSession(string $date): ClassSession
    {
        return ClassSession::create(['StudentClassID' => $this->sc->ID, 'SessionDate' => $date,
            'StartTime' => '16:00:00', 'EndTime' => '18:00:00', 'Status' => 'attended']);
    }

    private function lr(ClassSession $session, int $teacher): void
    {
        LearningRecord::create(['StudentClassID' => $this->sc->ID, 'ClassSessionID' => $session->id, 'TeacherID' => $teacher,
            'Status' => 'approved', 'Content' => 'x', 'SessionDate' => '2026-04-10', 'StartTime' => '16:00', 'EndTime' => '18:00']);
    }

    private function twoChains(): void
    {
        $base = ['student_id' => $this->sc->StudentID, 'day_of_week' => 5, 'type' => 'normal', 'branch_id' => 1, 'student_course_id' => $this->sc->ID,
            'schedule_date' => '2026-04-10', 'start_time' => '16:00', 'end_time' => '18:00', 'subject' => 'Math', 'class_type' => 'one_on_one',
            'created_at' => now(), 'updated_at' => now()];
        foreach ([[12695, 12696, $this->subB], [12697, 12698, $this->subC]] as [$anchor, $live, $teacher]) {
            DB::table('schedules')->insert($base + ['id' => $anchor, 'status' => 'rescheduled', 'teacher_id' => $this->contract, 'deduction' => 0]);
            DB::table('schedules')->insert($base + ['id' => $live, 'status' => 'scheduled', 'teacher_id' => $teacher, 'deduction' => 1,
                'original_schedule_id' => $anchor, 'original_schedule_date' => '2026-04-10', 'original_start_time' => '16:00:00']);
        }
    }
}
