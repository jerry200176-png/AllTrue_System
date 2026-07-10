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
