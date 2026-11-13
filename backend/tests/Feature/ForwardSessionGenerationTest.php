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

    /**
     * in-app #530: a shared-pool member whose own counter reads 0 left (lessons taught before it joined the pool)
     * still gets its weekly lessons while the pool has lessons nobody planned yet.
     */
    public function test_shared_pool_member_follows_the_pool_not_its_own_stale_counter(): void
    {
        Carbon::setTestNow($this->today);
        try {
            // Pool: 48 bought, 39 used, 9 left; the sibling planned 2 ahead, so 7 are unplanned.
            [, $stale] = $this->sharedPool(total: 48, consumed: 39, staleRemaining: 0, siblingPlanned: 2);

            $plan = $this->gen->planCourse($stale, 4, $this->today);
            $this->assertSame('plan', $plan['status'], (string) ($plan['reason'] ?? ''));
            $this->assertSame(['2026-07-14', '2026-07-21', '2026-07-28', '2026-08-04'], array_column($plan['slots'], 'SessionDate'));
        } finally {
            Carbon::setTestNow();
        }
    }

    public function test_shared_pool_member_is_capped_at_what_the_pool_has_not_planned(): void
    {
        Carbon::setTestNow($this->today);
        try {
            // 9 left, the sibling planned 8, so 1 is unplanned: one lesson, not the horizon's 4.
            [, $stale] = $this->sharedPool(total: 48, consumed: 39, staleRemaining: 0, siblingPlanned: 8);
            $this->assertCount(1, $this->gen->planCourse($stale, 4, $this->today)['slots']);

            // The other direction: the member's own counter says 10 left, the pool is fully planned: nothing more.
            [, $over] = $this->sharedPool(total: 48, consumed: 46, staleRemaining: 10, siblingPlanned: 2);
            $plan = $this->gen->planCourse($over, 4, $this->today);
            $this->assertSame('skip', $plan['status']);
            $this->assertStringContainsString('shared pool', $plan['reason']);
        } finally {
            Carbon::setTestNow();
        }
    }

    /**
     * #5243 review: a sibling's past lesson nobody marked yet still holds a pool lesson (it can be marked late). With 2
     * left and 2 such rows on the sibling, the zero-counter member must not get 2 new lessons.
     */
    public function test_shared_pool_member_counts_a_siblings_unmarked_past_lessons_against_the_pool(): void
    {
        Carbon::setTestNow($this->today);
        try {
            [, $stale, $sibling] = $this->sharedPool(total: 48, consumed: 46, staleRemaining: 0, siblingPlanned: 0);
            $this->sess($sibling, '2026-06-29', '19:00:00', '21:00:00', 'scheduled');
            $this->sess($sibling, '2026-07-06', '19:00:00', '21:00:00', 'scheduled');

            $plan = $this->gen->planCourse($stale, 4, $this->today);
            $this->assertSame('skip', $plan['status']);
            $this->assertStringContainsString('shared pool', $plan['reason']);

            // One of them marked on leave frees its lesson: exactly one new lesson, the member's own rows not counted twice.
            DB::table('ClassSession')->where('StudentClassID', $sibling)->where('SessionDate', '2026-07-06')->update(['Status' => 'leave']);
            $this->assertCount(1, $this->gen->planCourse($stale, 4, $this->today)['slots']);
        } finally {
            Carbon::setTestNow();
        }
    }

    /** A package with one running member is not a shared pool: its own counter and only its own unmarked rows decide, as before. */
    public function test_lone_package_member_keeps_its_own_counter_and_ignores_a_stopped_siblings_rows(): void
    {
        Carbon::setTestNow($this->today);
        try {
            [, $lone, $sibling] = $this->sharedPool(total: 48, consumed: 48, staleRemaining: 3, siblingPlanned: 0);
            DB::table('StudentClass')->where('ID', $sibling)->update(['Stop' => 1]);
            $this->sess($sibling, '2026-06-29', '19:00:00', '21:00:00', 'scheduled');
            $this->sess($sibling, '2026-07-06', '19:00:00', '21:00:00', 'scheduled');
            $this->assertCount(3, $this->gen->planCourse($lone, 4, $this->today)['slots']);

            $this->sess($lone, '2026-07-09', '20:00:00', '22:00:00', 'scheduled'); // its own unmarked past lesson holds one
            $this->assertCount(2, $this->gen->planCourse($lone, 4, $this->today)['slots']);
        } finally {
            Carbon::setTestNow();
        }
    }

    /**
     * #5243 review: the pool figure is a fixed number of count queries, whatever the number of members and lessons
     * (no lesson rows loaded), and its one-query "unmarked past" count agrees with the row-by-row rule.
     */
    public function test_pool_unplanned_is_a_bounded_number_of_queries_and_matches_the_row_rule(): void
    {
        Carbon::setTestNow($this->today);
        try {
            [$pkg, $stale, $sibling] = $this->sharedPool(total: 48, consumed: 40, staleRemaining: 0, siblingPlanned: 1);
            $studentId = (int) DB::table('StudentClass')->where('ID', $stale)->value('StudentID');
            $members = [$stale, $sibling];
            for ($i = 0; $i < 4; $i++) { // four more running members, each with past lessons
                $members[] = $extra = (int) DB::table('StudentClass')->insertGetId([
                    'StudentID' => $studentId, 'GradeID' => 1, 'SubjectID' => 10 + $i, 'TeacherID' => 1, 'by1' => 1, 'Period' => 4,
                    'TotalHours' => 0, 'Charge' => 0, 'Pay' => 0, 'Paid' => 1, 'Rate' => 500, 'ClassType' => 'one_on_one',
                    'StartDate' => '2026-05-01', 'SessionCount' => 48, 'SessionDuration' => 120, 'RemainingSessions' => 48,
                    'UsedSessions' => 0, 'Stop' => 0, 'ScheduleMode' => 'count', 'PackageID' => $pkg, 'PackageTotalSessions' => 48,
                ]);
                foreach (['2026-06-10', '2026-06-17', '2026-06-24'] as $d) {
                    $this->sess($extra, $d, sprintf('%02d:00:00', 8 + $i), sprintf('%02d:00:00', 9 + $i), 'attended');
                }
            }
            $this->sess($sibling, '2026-07-06', '19:00:00', '21:00:00', 'scheduled');   // unmarked: holds one
            $this->sess($members[2], '2026-07-01', '08:00:00', '09:00:00', 'scheduled'); // unmarked: holds one
            $this->sess($members[3], '2026-07-01', '09:00:00', '10:00:00', 'scheduled'); // already deducted: does not
            DB::table('session_deduction_ledger')->insert([
                'student_class_id' => $members[3], 'class_session_id' => (int) DB::table('ClassSession')
                    ->where('StudentClassID', $members[3])->where('SessionDate', '2026-07-01')->value('id'),
                'event_type' => 'deduct', 'source' => 'attendance', 'created_at' => now(), 'updated_at' => now(),
            ]);
            $this->sess($members[4], '2026-07-01', '10:00:00', '11:00:00', 'leave');     // leave: does not

            $rows = \App\Models\ClassSession::query()->whereIn('StudentClassID', $members)
                ->whereRaw("LOWER(Status) NOT IN ('cancelled','voided')")->get();
            $byRows = \App\Services\Scheduling\ContractSessionSchedule::partitionExcessScheduledRows($rows, 0)['past_unrecorded']->count();
            $this->assertSame(2, $byRows);

            $course = \App\Models\StudentClass::query()->findOrFail($stale);
            DB::flushQueryLog();
            DB::enableQueryLog();
            $unplanned = \App\Services\Scheduling\ContractSessionSchedule::sharedPoolUnplanned($course);
            $queries = DB::getQueryLog();
            DB::disableQueryLog();

            $this->assertSame(8 - 1 - $byRows, $unplanned, '8 left, 1 planned ahead, 2 unmarked past lessons');
            $this->assertLessThanOrEqual(8, count($queries), 'package, shared?, members, planned, ledger, unmarked past (6 today): no per-member query');
            foreach ($queries as $q) {
                $this->assertDoesNotMatchRegularExpression('/select \* from `ClassSession`/i', $q['query'], 'no lesson rows are loaded');
            }
        } finally {
            DB::disableQueryLog();
            Carbon::setTestNow();
        }
    }

    /** #5243 review: members of used-up pools must not use up --limit ahead of a real candidate with a higher id. */
    public function test_members_of_used_up_pools_do_not_crowd_out_real_candidates(): void
    {
        Carbon::setTestNow($this->today);
        try {
            for ($i = 0; $i < 3; $i++) {
                [, , $sibling] = $this->sharedPool(total: 48, consumed: 48, staleRemaining: 0, siblingPlanned: 0);
                DB::table('StudentClass')->where('ID', $sibling)->update(['RemainingSessions' => 0, 'UsedSessions' => 48]);
            }
            $real = $this->course(remaining: 4);
            foreach (['2026-06-22', '2026-06-29', '2026-07-06'] as $d) {
                $this->sess($real, $d, '16:00:00', '18:00:00', 'attended');
            }

            $this->assertSame(0, Artisan::call('sessions:generate-forward', ['--execute' => true, '--scheduled' => true, '--limit' => 2]));
            $this->assertSame(4, DB::table('ClassSession')->where('StudentClassID', $real)->where('Note', '系統向前生成 #1062')->count());
        } finally {
            Carbon::setTestNow();
        }
    }

    public function test_scheduled_execute_reaches_a_shared_pool_member_with_a_stale_counter(): void
    {
        Carbon::setTestNow($this->today);
        try {
            [, $stale] = $this->sharedPool(total: 48, consumed: 39, staleRemaining: 0, siblingPlanned: 2);
            $generated = fn (): int => DB::table('ClassSession')->where('StudentClassID', $stale)->where('Note', '系統向前生成 #1062')->count();
            $this->assertSame(0, Artisan::call('sessions:generate-forward', ['--execute' => true, '--scheduled' => true]));
            $this->assertSame(4, $generated());

            // A second night adds nothing: the member now has upcoming lessons.
            $this->assertSame(0, Artisan::call('sessions:generate-forward', ['--execute' => true, '--scheduled' => true]));
            $this->assertSame(4, $generated());
        } finally {
            Carbon::setTestNow();
        }
    }

    /**
     * One student, one shared count package, two running members. The first member (Tue 20:00, three recent taught
     * lessons, no upcoming) carries $staleRemaining on its own counter; the sibling (Mon 19:00) holds $siblingPlanned
     * future lessons. The pool ledger says $consumed of $total are used.
     *
     * @return array{0:int,1:int,2:int} package id, first member id, sibling id
     */
    private function sharedPool(int $total, int $consumed, int $staleRemaining, int $siblingPlanned): array
    {
        $first = $this->course(remaining: $staleRemaining);
        $studentId = (int) DB::table('StudentClass')->where('ID', $first)->value('StudentID');
        $pkg = (int) DB::table('course_packages')->insertGetId([
            'student_id' => $studentId, 'campus_id' => 1, 'name' => 'shared', 'total_sessions' => $total,
            'remaining_sessions' => $total - $consumed, 'used_sessions' => $consumed, 'class_type' => 'one_on_one',
            'billing_mode' => 'count', 'created_at' => now(), 'updated_at' => now(),
        ]);
        $sibling = (int) DB::table('StudentClass')->insertGetId([
            'StudentID' => $studentId, 'GradeID' => 1, 'SubjectID' => 2, 'TeacherID' => 1,
            'by1' => 1, 'Period' => 4, 'TotalHours' => 0, 'Charge' => 0, 'Pay' => 0,
            'Paid' => 1, 'Rate' => 500, 'ClassType' => 'one_on_one',
            'StartDate' => '2026-05-01', 'SessionCount' => $total, 'SessionDuration' => 120,
            'RemainingSessions' => $total, 'UsedSessions' => 0, 'Stop' => 0, 'ScheduleMode' => 'count',
        ]);
        DB::table('StudentClass')->whereIn('ID', [$first, $sibling])->update([
            'PackageID' => $pkg, 'PackageTotalSessions' => $total, 'SessionCount' => $total,
        ]);
        DB::table('StudentClass')->where('ID', $first)->update(['UsedSessions' => $total - $staleRemaining]);
        foreach (['2026-06-23', '2026-06-30', '2026-07-07'] as $d) {
            $this->sess($first, $d, '20:00:00', '22:00:00', 'attended');
        }
        $monday = Carbon::parse('2026-07-20');
        for ($i = 0; $i < $siblingPlanned; $i++) {
            $this->sess($sibling, $monday->copy()->addWeeks($i)->toDateString(), '19:00:00', '21:00:00', 'scheduled');
        }
        DB::table('package_session_ledger')->insert([
            'package_id' => $pkg, 'student_class_id' => $first, 'class_session_id' => null, 'delta' => -$consumed,
            'reason' => 'attendance', 'created_at' => now(), 'updated_at' => now(),
        ]);

        return [$pkg, $first, $sibling];
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
