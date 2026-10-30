<?php

namespace Tests\Feature;

use App\Services\ForwardSessionGenerator;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * #1062 Track A forward-generation: cadence-derived, active-only, capped, idempotent,
 * dry-run read-only. Guards the highest-incident-risk area (materialization).
 */
class ForwardSessionGenerationTest extends TestCase
{
    use RefreshDatabase;

    private ForwardSessionGenerator $gen;
    private Carbon $today;

    protected function setUp(): void
    {
        parent::setUp();
        $this->gen = app(ForwardSessionGenerator::class);
        // Fix "today" to a Monday for deterministic weekday math.
        $this->today = Carbon::parse('2026-07-13'); // Monday
    }

    public function test_confirmed_weekly_cadence_plans_and_executes_capped(): void
    {
        // Course with 3 recent Monday 16:00 sessions, remaining 10, no upcoming.
        $sc = $this->course(remaining: 10);
        foreach (['2026-06-22', '2026-06-29', '2026-07-06'] as $d) {
            $this->sess($sc, $d, '16:00:00', '18:00:00', 'attended');
        }

        $plan = $this->gen->planCourse($sc, 4, $this->today);
        $this->assertSame('plan', $plan['status']);
        $this->assertSame(1, $plan['cadence']['dow']); // Monday
        $this->assertCount(4, $plan['slots'], 'capped at horizon 4, not remaining 10');
        foreach ($plan['slots'] as $s) {
            $this->assertSame(1, Carbon::parse($s['SessionDate'])->dayOfWeek);
            $this->assertSame('16:00:00', $s['StartTime']);
            $this->assertTrue(Carbon::parse($s['SessionDate'])->gt($this->today));
        }

        // Execute → creates 4; re-run is idempotent (0 new).
        $created = $this->gen->executePlan($plan);
        $this->assertCount(4, $created);
        $again = $this->gen->executePlan($plan);
        $this->assertCount(0, $again, 'upsertSlot idempotent — no duplicates');
        $this->assertSame(4, DB::table('ClassSession')->where('StudentClassID', $sc)
            ->where('Note', '系統向前生成 #1062')->count());
    }

    /** in-app #450: after a 課程設定 time edit the generator uses the course's current slot, not the old-time history. */
    public function test_generated_rows_use_the_courses_current_slot_not_the_history_tally(): void
    {
        $sc = $this->course(remaining: 10);
        foreach (['2026-06-22', '2026-06-29', '2026-07-06'] as $d) {
            $this->sess($sc, $d, '16:00:00', '18:00:00', 'attended');
        }
        DB::table('StudentClass')->where('ID', $sc)->update(['week' => 1, 'time' => '20:00:00']);

        $plan = $this->gen->planCourse($sc, 4, $this->today);
        $this->assertSame('plan', $plan['status']);
        foreach ($plan['slots'] as $s) {
            $this->assertSame(['20:00:00', '22:00:00'], [$s['StartTime'], $s['EndTime']]);
        }
    }

    public function test_same_day_multi_slot_keeps_the_slot_the_history_confirms(): void
    {
        $sc = $this->course(remaining: 10);
        foreach (['2026-06-22', '2026-06-29', '2026-07-06'] as $d) {
            $this->sess($sc, $d, '17:00:00', '19:00:00', 'attended');
        }
        DB::table('StudentClass')->where('ID', $sc)->update(['week' => 1, 'time' => '13:00:00', 'week1' => 1, 'time1' => '17:00:00', 'duration1' => 120]);

        $plan = $this->gen->planCourse($sc, 4, $this->today);
        foreach ($plan['slots'] as $s) {
            $this->assertSame('17:00:00', $s['StartTime']);
        }
    }

    public function test_dormant_course_is_skipped(): void
    {
        $sc = $this->course(remaining: 5);
        // last session > 3 weeks before "today"
        $this->sess($sc, '2026-06-01', '16:00:00', '18:00:00', 'attended');
        $this->sess($sc, '2026-06-08', '16:00:00', '18:00:00', 'attended');
        $plan = $this->gen->planCourse($sc, 4, $this->today);
        $this->assertSame('skip', $plan['status']);
        $this->assertStringContainsString('dormant', $plan['reason']);
    }

    public function test_scattered_history_has_no_confirmed_cadence(): void
    {
        $sc = $this->course(remaining: 5);
        // Recent but different weekdays each time → no confirmed cadence.
        $this->sess($sc, '2026-07-06', '16:00:00', '18:00:00', 'attended'); // Mon
        $this->sess($sc, '2026-07-08', '17:00:00', '19:00:00', 'attended'); // Wed
        $this->sess($sc, '2026-07-11', '10:00:00', '12:00:00', 'attended'); // Sat
        $plan = $this->gen->planCourse($sc, 4, $this->today);
        $this->assertSame('skip', $plan['status']);
        $this->assertStringContainsString('cadence', $plan['reason']);
    }

    public function test_course_with_upcoming_session_is_skipped(): void
    {
        $sc = $this->course(remaining: 5);
        $this->sess($sc, '2026-07-06', '16:00:00', '18:00:00', 'attended');
        $this->sess($sc, '2026-07-13', '16:00:00', '18:00:00', 'attended');
        $this->sess($sc, '2026-07-20', '16:00:00', '18:00:00', 'scheduled'); // upcoming
        $plan = $this->gen->planCourse($sc, 4, $this->today);
        $this->assertSame('skip', $plan['status']);
        $this->assertStringContainsString('upcoming', $plan['reason']);
    }

    public function test_past_unmarked_session_holds_a_remaining_session(): void
    {
        // #4515 (course 2739): last lesson passed unmarked, RemainingSessions still 1 → the
        // 03:45 run added a 9th lesson; the unmarked one was later moved and both got deducted.
        $sc = $this->course(remaining: 1);
        $this->sess($sc, '2026-06-29', '16:00:00', '18:00:00', 'attended');
        $this->sess($sc, '2026-07-06', '16:00:00', '18:00:00', 'scheduled');
        $plan = $this->gen->planCourse($sc, 4, $this->today);
        $this->assertSame('skip', $plan['status']);
        $this->assertSame([], $plan['slots']);

        $sc2 = $this->course(remaining: 3);
        $this->sess($sc2, '2026-06-29', '16:00:00', '18:00:00', 'attended');
        $this->sess($sc2, '2026-07-06', '16:00:00', '18:00:00', 'scheduled');
        $this->assertCount(2, $this->gen->planCourse($sc2, 4, $this->today)['slots']);
    }

    public function test_past_scheduled_row_already_deducted_does_not_hold_twice(): void
    {
        // #4552 review: a legacy `scheduled` row with a positive ledger net is already in RemainingSessions.
        $sc = $this->course(remaining: 1);
        $this->sess($sc, '2026-06-29', '16:00:00', '18:00:00', 'attended');
        $this->sess($sc, '2026-07-06', '16:00:00', '18:00:00', 'scheduled');
        $used = (int) DB::table('ClassSession')->where('StudentClassID', $sc)->where('SessionDate', '2026-07-06')->value('id');
        DB::table('session_deduction_ledger')->insert([
            'student_class_id' => $sc, 'class_session_id' => $used, 'event_type' => 'deduct', 'source' => 'attendance',
            'created_at' => now(), 'updated_at' => now(),
        ]);
        $this->assertCount(1, $this->gen->planCourse($sc, 4, $this->today)['slots']);
    }

    public function test_command_dry_run_is_read_only(): void
    {
        $sc = $this->course(remaining: 4);
        foreach (['2026-06-22', '2026-06-29', '2026-07-06'] as $d) {
            $this->sess($sc, $d, '16:00:00', '18:00:00', 'attended');
        }
        $before = DB::table('ClassSession')->count();
        $this->assertSame(0, Artisan::call('sessions:generate-forward'));
        $this->assertSame($before, DB::table('ClassSession')->count(), 'dry-run must not write');
    }

    public function test_plan_skips_slot_when_other_contract_already_occupies_student_slot(): void
    {
        // Two contracts for the same student; other SC already has Monday 16:00 upcoming.
        $scA = $this->course(remaining: 4);
        $studentId = (int) DB::table('StudentClass')->where('ID', $scA)->value('StudentID');
        $scB = (int) DB::table('StudentClass')->insertGetId([
            'StudentID' => $studentId, 'GradeID' => 1, 'SubjectID' => 1, 'TeacherID' => 1,
            'by1' => 1, 'Period' => 4, 'TotalHours' => 0, 'Charge' => 0, 'Pay' => 0,
            'Paid' => 1, 'Rate' => 500, 'ClassType' => 'one_on_one',
            'StartDate' => '2026-05-01', 'SessionCount' => 10, 'SessionDuration' => 120,
            'RemainingSessions' => 2, 'UsedSessions' => 0, 'Stop' => 0, 'ScheduleMode' => 'count',
        ]);
        foreach (['2026-06-22', '2026-06-29', '2026-07-06'] as $d) {
            $this->sess($scA, $d, '16:00:00', '18:00:00', 'attended');
        }
        // Other contract owns the next Monday slot → forward-gen for A must not plan it.
        $this->sess($scB, '2026-07-20', '16:00:00', '18:00:00', 'scheduled');

        $plan = $this->gen->planCourse($scA, 4, $this->today);
        $dates = array_column($plan['slots'], 'SessionDate');
        $this->assertNotContains('2026-07-20', $dates, 'must not amplify cross-SC duplicate slot');
    }

    public function test_plan_skips_slot_partially_overlapping_another_contract(): void
    {
        // #380 family: the write guard rejects any overlap, not only the same start; the plan must not offer it.
        $scA = $this->course(remaining: 4);
        $studentId = (int) DB::table('StudentClass')->where('ID', $scA)->value('StudentID');
        $scB = (int) DB::table('StudentClass')->insertGetId([
            'StudentID' => $studentId, 'GradeID' => 1, 'SubjectID' => 1, 'TeacherID' => 1,
            'by1' => 1, 'Period' => 4, 'TotalHours' => 0, 'Charge' => 0, 'Pay' => 0,
            'Paid' => 1, 'Rate' => 500, 'ClassType' => 'one_on_one',
            'StartDate' => '2026-05-01', 'SessionCount' => 10, 'SessionDuration' => 120,
            'RemainingSessions' => 2, 'UsedSessions' => 0, 'Stop' => 0, 'ScheduleMode' => 'count',
        ]);
        foreach (['2026-06-22', '2026-06-29', '2026-07-06'] as $d) {
            $this->sess($scA, $d, '16:00:00', '18:00:00', 'attended');
        }
        $this->sess($scB, '2026-07-20', '17:00:00', '18:00:00', 'scheduled');

        $plan = $this->gen->planCourse($scA, 4, $this->today);
        $this->assertNotContains('2026-07-20', array_column($plan['slots'], 'SessionDate'));
        $this->assertNotSame([], $this->gen->executePlan($plan), 'the remaining slots still materialize without a conflict');
    }

    public function test_scheduled_execute_generates_sessions(): void
    {
        // #1062 durable closure: the nightly `--execute --scheduled` path writes.
        $sc = $this->course(remaining: 4);
        foreach (['2026-06-22', '2026-06-29', '2026-07-06'] as $d) {
            $this->sess($sc, $d, '16:00:00', '18:00:00', 'attended');
        }
        \Carbon\Carbon::setTestNow(\Carbon\Carbon::parse('2026-07-13'));
        try {
            $this->assertSame(0, Artisan::call('sessions:generate-forward', ['--execute' => true, '--scheduled' => true]));
            $this->assertGreaterThan(0, DB::table('ClassSession')
                ->where('StudentClassID', $sc)->where('Note', '系統向前生成 #1062')->count());
        } finally {
            \Carbon\Carbon::setTestNow();
        }
    }

    private function course(int $remaining): int
    {
        $studentId = 96000 + random_int(1, 8999);
        DB::table('Student')->insert([
            'id' => $studentId, 'name' => 'FWD Test', 'CampusID' => 1, 'ClassID' => 1, 'enable' => 1,
        ]);
        return (int) DB::table('StudentClass')->insertGetId([
            'StudentID' => $studentId, 'GradeID' => 1, 'SubjectID' => 1, 'TeacherID' => 1,
            'by1' => 1, 'Period' => 4, 'TotalHours' => 0, 'Charge' => 0, 'Pay' => 0,
            'Paid' => 1, 'Rate' => 500, 'ClassType' => 'one_on_one',
            'StartDate' => '2026-05-01', 'SessionCount' => 20, 'SessionDuration' => 120,
            'RemainingSessions' => $remaining, 'UsedSessions' => 0, 'Stop' => 0, 'ScheduleMode' => 'count',
        ]);
    }

    private function sess(int $sc, string $date, string $start, string $end, string $status): void
    {
        DB::table('ClassSession')->insert([
            'StudentClassID' => $sc, 'SessionDate' => $date, 'StartTime' => $start,
            'EndTime' => $end, 'Status' => $status, 'Note' => '',
            'created_at' => now(), 'updated_at' => now(),
        ]);
    }
}
