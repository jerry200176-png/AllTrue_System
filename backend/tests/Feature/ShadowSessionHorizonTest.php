<?php

namespace Tests\Feature;

use App\Services\Scheduling\ShadowSessionHorizonService;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/** ADR-006 Phase 2 shadow — always read-only. */
class ShadowSessionHorizonTest extends TestCase
{
    use RefreshDatabase;

    private Carbon $today;

    protected function setUp(): void
    {
        parent::setUp();
        $this->today = Carbon::parse('2026-07-13', 'Asia/Taipei');
    }

    public function test_shadow_is_read_only_and_reports_metrics(): void
    {
        $sc = $this->course(['week' => 1, 'time' => '16:00', 'RemainingSessions' => 8]);
        foreach (['2026-06-22', '2026-06-29', '2026-07-06'] as $d) {
            $this->sess($sc, $d, '16:00:00', '18:00:00');
        }
        $before = DB::table('ClassSession')->count();

        $report = app(ShadowSessionHorizonService::class)->run(null, $this->today);
        $this->assertTrue($report['meta']['read_only']);
        $this->assertTrue($report['meta']['production_safe']);
        $this->assertFalse($report['meta']['writes']);
        $this->assertSame(1, $report['meta']['courses_scanned']);
        $this->assertGreaterThanOrEqual(1, $report['metrics']['ensure_eligible_courses']);
        $this->assertSame($before, DB::table('ClassSession')->count());
    }

    public function test_overlapping_slot_surfaces_as_preview_vs_ensure_drift(): void
    {
        // #3727 review r4242402687: drift must compare coverable_new with the net (would_create) count.
        $sc = $this->course(['week' => 1, 'time' => '16:00', 'RemainingSessions' => 8]);
        foreach (['2026-06-22', '2026-06-29', '2026-07-06'] as $d) {
            $this->sess($sc, $d, '16:00:00', '18:00:00');
        }
        $report = app(ShadowSessionHorizonService::class)->run(null, $this->today);
        $this->assertSame(0, $report['metrics']['preview_vs_ensure_drift_courses']);

        $studentId = (int) DB::table('StudentClass')->where('ID', $sc)->value('StudentID');
        $other = $this->course(['StudentID' => $studentId, 'week' => 1, 'time' => '17:00', 'RemainingSessions' => 0, 'Stop' => 1]);
        DB::table('StudentClass')->where('ID', $other)->update(['Stop' => 0]);
        DB::table('ClassSession')->insert([
            'StudentClassID' => $other, 'SessionDate' => '2026-07-20', 'StartTime' => '17:00:00',
            'EndTime' => '18:00:00', 'Status' => 'scheduled', 'Note' => '',
        ]);

        $report = app(ShadowSessionHorizonService::class)->run(null, $this->today);

        $this->assertGreaterThanOrEqual(1, $report['metrics']['preview_vs_ensure_drift_courses']);
    }

    public function test_shared_package_overplan_drift_counts_skipped_candidate(): void
    {
        // #3727 review r4244028320: covered + uncovered candidates are all planned; a skipped one is drift.
        $owner = 98777;
        DB::table('Student')->insert(['id' => $owner, 'name' => 'Pkg', 'CampusID' => 1, 'ClassID' => 1, 'enable' => 1]);
        $pkg = (int) DB::table('course_packages')->insertGetId([
            'student_id' => $owner, 'campus_id' => 1, 'name' => 'drift pool', 'total_sessions' => 10,
            'remaining_sessions' => 1, 'used_sessions' => 0, 'stop' => 0, 'created_at' => now(), 'updated_at' => now(),
        ]);
        $sc = $this->course(['week' => 1, 'time' => '16:00', 'RemainingSessions' => 0, 'PackageID' => $pkg]);
        foreach (['2026-06-22', '2026-06-29', '2026-07-06'] as $d) {
            $this->sess($sc, $d, '16:00:00', '18:00:00');
        }
        $ensure = app(\App\Services\Scheduling\EnsureSessionHorizonService::class);
        $n = count($ensure->ensure($sc, null, $this->today, null)['ensure']['candidates']);
        $this->assertGreaterThanOrEqual(3, $n);
        DB::table('course_packages')->where('id', $pkg)->update(['remaining_sessions' => $n - 1]); // covered n-1, uncovered 1

        $control = app(ShadowSessionHorizonService::class)->run(null, $this->today);
        $this->assertSame(0, $control['metrics']['preview_vs_ensure_drift_courses'], 'no conflict, no drift');

        $studentId = (int) DB::table('StudentClass')->where('ID', $sc)->value('StudentID');
        $first = $ensure->ensure($sc, null, $this->today, null)['ensure']['candidates'][0]['date'];
        $other = $this->course(['StudentID' => $studentId, 'week' => 1, 'time' => '17:00', 'RemainingSessions' => 0]);
        DB::table('ClassSession')->insert([
            'StudentClassID' => $other, 'SessionDate' => $first, 'StartTime' => '17:00:00',
            'EndTime' => '18:00:00', 'Status' => 'scheduled', 'Note' => '',
        ]);

        $report = app(ShadowSessionHorizonService::class)->run(null, $this->today);

        $this->assertGreaterThanOrEqual(1, $report['metrics']['preview_vs_ensure_drift_courses']);
    }

    public function test_command_read_only(): void
    {
        $sc = $this->course(['week' => 1, 'time' => '16:00', 'RemainingSessions' => 4]);
        foreach (['2026-06-22', '2026-06-29', '2026-07-06'] as $d) {
            $this->sess($sc, $d, '16:00:00', '18:00:00');
        }
        $before = DB::table('ClassSession')->count();
        Carbon::setTestNow($this->today);
        try {
            $this->assertSame(0, Artisan::call('sessions:shadow-horizon', [
                '--as-of' => '2026-07-13', '--summary' => true,
            ]));
        } finally {
            Carbon::setTestNow();
        }
        $this->assertSame($before, DB::table('ClassSession')->count());
        $this->assertStringContainsString('READ-ONLY', Artisan::output());
    }

    /** @param array<string,mixed> $over */
    private function course(array $over): int
    {
        // Reuse the caller's student; otherwise let the DB assign the PK (a random one collided, PR #3947).
        $studentId = array_key_exists('StudentID', $over)
            ? (int) $over['StudentID']
            : (int) DB::table('Student')->insertGetId([
                'name' => 'P2 Test', 'CampusID' => 1, 'ClassID' => 1, 'enable' => 1,
            ]);
        $base = [
            'StudentID' => $studentId, 'GradeID' => 1, 'SubjectID' => 1, 'TeacherID' => 1,
            'by1' => 1, 'Period' => 4, 'TotalHours' => 0, 'Charge' => 0, 'Pay' => 0,
            'Paid' => 1, 'Rate' => 500, 'ClassType' => 'one_on_one',
            'StartDate' => '2026-05-01', 'EndDate' => '2026-12-31',
            'SessionCount' => 20, 'SessionDuration' => 120,
            'RemainingSessions' => 8, 'UsedSessions' => 0, 'Stop' => 0, 'ScheduleMode' => 'count',
        ];
        foreach (['week', 'time', 'RemainingSessions'] as $k) {
            if (array_key_exists($k, $over)) {
                $base[$k] = $over[$k];
                unset($over[$k]);
            }
        }

        return (int) DB::table('StudentClass')->insertGetId(array_merge($base, $over));
    }

    private function sess(int $sc, string $date, string $start, string $end): void
    {
        DB::table('ClassSession')->insert([
            'StudentClassID' => $sc, 'SessionDate' => $date, 'StartTime' => $start,
            'EndTime' => $end, 'Status' => 'attended', 'Note' => '',
            'created_at' => '2026-06-01 10:00:00', 'updated_at' => '2026-06-01 10:00:00',
        ]);
    }
}
