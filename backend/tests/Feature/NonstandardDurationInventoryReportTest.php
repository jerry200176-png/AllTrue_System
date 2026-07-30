<?php

namespace Tests\Feature;

use App\Services\Scheduling\NonstandardDurationInventoryReporter;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * RFC_NONSTANDARD_SESSION_DURATION_BILLING Phase 0A — read-only inventory report.
 * Every test here proves either a specific metric's correctness, or that the
 * command/reporter never writes to the database.
 */
class NonstandardDurationInventoryReportTest extends TestCase
{
    use RefreshDatabase;

    private int $campusA = 41001;
    private int $campusB = 41002;

    protected function setUp(): void
    {
        parent::setUp();
        $this->makeCampus($this->campusA, 'Campus A');
        $this->makeCampus($this->campusB, 'Campus B');
    }

    private function makeCampus(int $id, string $name): void
    {
        $row = [
            'id' => $id, 'name' => $name, 'Current' => 0,
            'LineNotifyID' => '', 'Client_ID' => '', 'Client_Secret' => '',
            'LIFFID' => '', 'LIFF_URL' => '', 'URL' => '', 'Token' => null,
            'TelegramToken' => null, 'TelegramChatID' => null, 'TelegramURL' => '',
            'TeachLIFFID' => '', 'TeachLIFF_URL' => '', 'code' => 'test-' . $id,
            'SwipeWindowMinutes' => 10,
        ];
        foreach (array_keys($row) as $column) {
            if (!Schema::hasColumn('Campus', $column)) {
                unset($row[$column]);
            }
        }
        DB::table('Campus')->insertOrIgnore($row);
    }

    public function test_normal_120_against_120_contract_is_not_a_mismatch(): void
    {
        $sc = $this->course(['SessionDuration' => 120], $this->campusA);
        $this->sess($sc, '2026-06-01', '16:00:00', '18:00:00');

        $report = app(NonstandardDurationInventoryReporter::class)->build(null, null, 2000, false);

        $this->assertSame(0, $report['b1_contract_schedule_mismatch']['mismatched_normal_sessions']);
        $this->assertSame(0, $report['b1_contract_schedule_mismatch']['affected_courses']);
    }

    public function test_normal_120_contract_180_actual_is_a_mismatch(): void
    {
        $sc = $this->course(['SessionDuration' => 120], $this->campusA);
        $this->sess($sc, '2026-06-01', '16:00:00', '19:00:00'); // 180 min, not flagged as makeup

        $report = app(NonstandardDurationInventoryReporter::class)->build(null, null, 2000, true);

        $b1 = $report['b1_contract_schedule_mismatch'];
        $this->assertSame(1, $b1['mismatched_normal_sessions']);
        $this->assertSame(1, $b1['affected_courses']);
        $this->assertArrayHasKey($this->campusA, $b1['by_campus']);
        $this->assertSame(1, $b1['by_campus'][$this->campusA]);
        $this->assertArrayHasKey('sample', $b1);
        $this->assertSame($sc, $b1['sample'][0]['student_class_id']);
        $this->assertSame(60, $b1['sample'][0]['diff_minutes']);
    }

    public function test_makeup_extra_180_excluded_from_normal_mismatch_but_counted_in_partial_adoption(): void
    {
        $sc = $this->course(['SessionDuration' => 120], $this->campusA);
        $csId = $this->sess($sc, '2026-06-01', '16:00:00', '19:00:00');
        // Mark this exact session as a makeup (schedules.type='extra') matching the
        // same date + HH:MM start time SessionDeductionService::resolvePartialMakeupMinutes() checks.
        DB::table('schedules')->insert([
            'student_id' => 0, 'teacher_id' => 0, 'subject' => 'Math', 'day_of_week' => 1,
            'start_time' => '16:00:00', 'end_time' => '19:00:00', 'class_type' => 'one_on_one',
            'status' => 'scheduled', 'type' => 'extra', 'deduction' => 1, 'branch_id' => $this->campusA,
            'schedule_date' => '2026-06-01', 'student_course_id' => $sc, 'original_schedule_id' => 0,
        ]);
        // The 180-minute partial deduction the makeup produced.
        DB::table('session_deduction_ledger')->insert([
            'student_class_id' => $sc, 'class_session_id' => $csId, 'event_type' => 'deduct',
            'source' => 'attendance', 'minutes' => 180, 'created_at' => now(), 'updated_at' => now(),
        ]);

        $report = app(NonstandardDurationInventoryReporter::class)->build(null, null, 2000, false);

        $this->assertSame(0, $report['b1_contract_schedule_mismatch']['mismatched_normal_sessions'], 'makeup must not count as a normal mismatch');
        $b2 = $report['b2_minute_ledger_adoption'];
        $this->assertSame(1, $b2['ledger_rows_with_minutes_set']);
        $this->assertSame(1, $b2['courses_with_minutes_set']);
        $this->assertSame(1, $b2['courses_with_minutes_ne_per_session'], '180 != 120 per-session -> partial adoption');
    }

    public function test_deduct_then_reverse_nets_to_zero(): void
    {
        $sc = $this->course(['SessionDuration' => 120], $this->campusA);
        DB::table('session_deduction_ledger')->insert([
            ['student_class_id' => $sc, 'class_session_id' => 1, 'event_type' => 'deduct', 'source' => 'attendance', 'minutes' => null, 'created_at' => now(), 'updated_at' => now()],
            ['student_class_id' => $sc, 'class_session_id' => 1, 'event_type' => 'reverse', 'source' => 'retro_leave', 'minutes' => null, 'created_at' => now(), 'updated_at' => now()],
        ]);

        $report = app(NonstandardDurationInventoryReporter::class)->build(null, null, 2000, false);

        $this->assertSame(1, $report['b2_minute_ledger_adoption']['courses_with_reverse_net_zero']);
        $this->assertSame(0, $report['b2_minute_ledger_adoption']['courses_with_reverse_net_nonzero']);
    }

    public function test_overage_is_computed_correctly(): void
    {
        // 4 sessions purchased @ 120min = 480 purchased minutes. 5 deducts of 120 each = 600
        // net -> uncovered = 600-480 = 120.
        $sc = $this->course(['SessionDuration' => 120, 'SessionCount' => 4], $this->campusA);
        $rows = [];
        for ($i = 1; $i <= 5; $i++) {
            $rows[] = [
                'student_class_id' => $sc, 'class_session_id' => $i, 'event_type' => 'deduct',
                'source' => 'attendance', 'minutes' => 120, 'created_at' => now(), 'updated_at' => now(),
            ];
        }
        DB::table('session_deduction_ledger')->insert($rows);

        $report = app(NonstandardDurationInventoryReporter::class)->build(null, null, 2000, true);

        $b3 = $report['b3_existing_overage'];
        $this->assertSame(1, $b3['affected_course_count']);
        $this->assertSame(120, $b3['total_uncovered_minutes']);
        $this->assertSame($sc, $b3['largest_uncovered_cases'][0]['student_class_id']);
        $this->assertSame(120, $b3['largest_uncovered_cases'][0]['uncovered_minutes']);
    }

    public function test_course_package_exposure_is_reported_without_touching_package_tables(): void
    {
        $pkgId = DB::table('course_packages')->insertGetId([
            'student_id' => 990001, 'campus_id' => $this->campusA, 'name' => 'Pkg',
            'total_sessions' => 10, 'created_at' => now(), 'updated_at' => now(),
        ]);
        $beforePkg = DB::table('course_packages')->where('id', $pkgId)->first();

        $sc = $this->course(['SessionDuration' => 120, 'PackageID' => $pkgId], $this->campusA);
        $this->sess($sc, '2026-06-01', '16:00:00', '19:00:00'); // mismatch -> flagged

        $report = app(NonstandardDurationInventoryReporter::class)->build(null, null, 2000, false);

        $b5 = $report['b5_course_package_exposure'];
        $this->assertSame(1, $b5['nonstandard_or_partial_courses_in_package']);
        $this->assertSame(1, $b5['packages_with_drift_risk']);

        $afterPkg = DB::table('course_packages')->where('id', $pkgId)->first();
        $this->assertEquals($beforePkg, $afterPkg, 'package row must be byte-identical after report runs');
    }

    public function test_b6_reports_no_authoritative_branch_setting_exists(): void
    {
        $report = app(NonstandardDurationInventoryReporter::class)->build(null, null, 2000, false);
        $this->assertFalse($report['b6_mutable_default_risk']['authoritative_branch_or_system_setting_found']);
    }

    public function test_campus_scope_filters_correctly(): void
    {
        $scA = $this->course(['SessionDuration' => 120], $this->campusA);
        $this->sess($scA, '2026-06-01', '16:00:00', '19:00:00');
        $scB = $this->course(['SessionDuration' => 120], $this->campusB);
        $this->sess($scB, '2026-06-01', '16:00:00', '19:00:00');

        $report = app(NonstandardDurationInventoryReporter::class)->build($this->campusA, null, 2000, false);

        $this->assertSame(1, $report['b1_contract_schedule_mismatch']['affected_courses']);
        $this->assertSame(1, $report['meta']['courses_scanned']);
    }

    public function test_details_flag_controls_sample_and_largest_cases_presence(): void
    {
        $sc = $this->course(['SessionDuration' => 120], $this->campusA);
        $this->sess($sc, '2026-06-01', '16:00:00', '19:00:00');

        $withoutDetails = app(NonstandardDurationInventoryReporter::class)->build(null, null, 2000, false);
        $this->assertArrayNotHasKey('sample', $withoutDetails['b1_contract_schedule_mismatch']);
        $this->assertArrayNotHasKey('largest_uncovered_cases', $withoutDetails['b3_existing_overage']);

        $withDetails = app(NonstandardDurationInventoryReporter::class)->build(null, null, 2000, true);
        $this->assertArrayHasKey('sample', $withDetails['b1_contract_schedule_mismatch']);
    }

    public function test_command_is_read_only_and_prints_read_only_marker(): void
    {
        $sc = $this->course(['SessionDuration' => 120, 'RemainingMinutes' => 480, 'PurchasedMinutes' => 480], $this->campusA);
        $this->sess($sc, '2026-06-01', '16:00:00', '19:00:00');
        DB::table('session_deduction_ledger')->insert([
            'student_class_id' => $sc, 'class_session_id' => 1, 'event_type' => 'deduct',
            'source' => 'attendance', 'minutes' => 120, 'created_at' => now(), 'updated_at' => now(),
        ]);

        $beforeClasses = DB::table('StudentClass')->count();
        $beforeSessions = DB::table('ClassSession')->count();
        $beforeLedger = DB::table('session_deduction_ledger')->count();
        $beforeCourse = (array) DB::table('StudentClass')->where('ID', $sc)->first();

        $exit = Artisan::call('sessions:report-nonstandard-duration', ['--json' => true, '--details' => true]);

        $this->assertSame(0, $exit);
        $output = Artisan::output();
        $this->assertStringContainsString('"READ_ONLY": true', $output);

        $this->assertSame($beforeClasses, DB::table('StudentClass')->count());
        $this->assertSame($beforeSessions, DB::table('ClassSession')->count());
        $this->assertSame($beforeLedger, DB::table('session_deduction_ledger')->count());
        $afterCourse = (array) DB::table('StudentClass')->where('ID', $sc)->first();
        $this->assertSame($beforeCourse, $afterCourse, 'the scanned course row must be byte-identical after the report runs');
    }

    public function test_command_summary_mode_does_not_write_either(): void
    {
        $sc = $this->course(['SessionDuration' => 120], $this->campusA);
        $this->sess($sc, '2026-06-01', '16:00:00', '19:00:00');
        $beforeSessions = DB::table('ClassSession')->count();

        $exit = Artisan::call('sessions:report-nonstandard-duration', []);

        $this->assertSame(0, $exit);
        $this->assertStringContainsString('READ_ONLY=true', Artisan::output());
        $this->assertSame($beforeSessions, DB::table('ClassSession')->count());
    }

    // ── fixtures ──────────────────────────────────────────────────────

    private function course(array $over, int $campusId): int
    {
        $studentId = 990000 + random_int(1, 8999);
        DB::table('Student')->insert([
            'id' => $studentId, 'name' => 'P0A Test', 'CampusID' => $campusId, 'ClassID' => 1, 'enable' => 1,
        ]);
        $base = [
            'StudentID' => $studentId, 'GradeID' => 1, 'SubjectID' => 1, 'TeacherID' => 1,
            'by1' => 1, 'Period' => 4, 'TotalHours' => 0, 'Charge' => 0, 'Pay' => 0,
            'Paid' => 1, 'Rate' => 500, 'ClassType' => 'one_on_one',
            'StartDate' => '2026-05-01', 'EndDate' => '2026-12-31',
            'SessionCount' => 8, 'SessionDuration' => 120,
            'RemainingSessions' => 8, 'UsedSessions' => 0, 'Stop' => 0, 'ScheduleMode' => 'count',
        ];

        return (int) DB::table('StudentClass')->insertGetId(array_merge($base, $over));
    }

    private function sess(int $sc, string $date, string $start, string $end): int
    {
        return (int) DB::table('ClassSession')->insertGetId([
            'StudentClassID' => $sc, 'SessionDate' => $date, 'StartTime' => $start,
            'EndTime' => $end, 'Status' => 'attended', 'Note' => '',
            'created_at' => '2026-06-01 10:00:00', 'updated_at' => '2026-06-01 10:00:00',
        ]);
    }
}
