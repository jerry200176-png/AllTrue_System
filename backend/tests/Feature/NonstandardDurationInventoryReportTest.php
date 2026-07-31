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
 * Proves metric correctness, that the reporter never writes, and that its query
 * count stays bounded as the scanned course count grows.
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

    private function report(?int $campusId = null, ?int $scId = null, bool $details = false): array
    {
        return app(NonstandardDurationInventoryReporter::class)->build($campusId, $scId, 2000, $details);
    }

    /**
     * The identical 180-minute session is a mismatch for a 120-minute-contract course
     * and clean for a 180-minute-contract one: comparison is per course, never against
     * a fixed 120. Non-occurring statuses are ignored entirely.
     */
    public function test_mismatch_is_measured_against_each_courses_own_contract(): void
    {
        $at180 = $this->course(['SessionDuration' => 180], $this->campusA);
        $this->sess($at180, '2026-06-01', '16:00:00', '19:00:00'); // 180 == contract -> clean

        $at120 = $this->course(['SessionDuration' => 120], $this->campusA);
        $this->sess($at120, '2026-06-01', '16:00:00', '19:00:00'); // 180 vs 120 -> mismatch
        $this->sess($at120, '2026-06-08', '16:00:00', '18:00:00'); // matches contract -> clean
        $this->sess($at120, '2026-06-15', '16:00:00', '19:00:00', 'cancelled'); // never happened
        $this->sess($at120, '2026-06-22', '16:00:00', '19:00:00', 'leave');     // never happened

        $b1 = $this->report()['b1_contract_schedule_mismatch'];

        $this->assertSame(1, $b1['affected_courses'], 'only the 120-minute-contract course mismatches');
        $this->assertSame(1, $b1['happened']['sessions'], 'cancelled/leave/matching sessions excluded');
        $this->assertSame([$at120], $b1['affected_course_ids']);
    }

    public function test_happened_and_planned_mismatches_are_counted_separately(): void
    {
        $sc = $this->course(['SessionDuration' => 120], $this->campusA);
        $this->sess($sc, '2026-06-01', '16:00:00', '19:00:00', 'attended');   // happened
        $this->sess($sc, '2026-06-08', '16:00:00', '19:00:00', 'completed');  // happened
        $this->sess($sc, '2026-09-01', '16:00:00', '19:00:00', 'scheduled');  // planned

        $b1 = $this->report(null, null, true)['b1_contract_schedule_mismatch'];

        $this->assertSame(2, $b1['happened']['sessions'], 'already-billed mismatches');
        $this->assertSame(1, $b1['happened']['courses']);
        $this->assertSame(1, $b1['planned']['sessions'], 'still-avoidable mismatches');
        $this->assertSame(1, $b1['planned']['courses']);
        $this->assertSame(1, $b1['affected_courses'], 'same course, not double-counted');
        $this->assertSame(['happened', 'happened', 'planned'], array_column($b1['sample'], 'phase'));
        $this->assertSame(120, $b1['sample'][0]['contract_session_minutes']);
        $this->assertSame(180, $b1['sample'][0]['actual_duration_minutes']);
    }

    public function test_makeup_extra_excluded_from_mismatch_but_counted_in_partial_adoption(): void
    {
        $sc = $this->course(['SessionDuration' => 120], $this->campusA);
        $csId = $this->sess($sc, '2026-06-01', '16:00:00', '19:00:00');
        $this->makeupSchedule($sc, '2026-06-01', '16:00:00', '19:00:00');
        $this->ledger($sc, $csId, 'deduct', 180);

        $report = $this->report();

        $this->assertSame(0, $report['b1_contract_schedule_mismatch']['happened']['sessions'], 'makeup is not a normal mismatch');
        $b2 = $report['b2_minute_ledger_adoption'];
        $this->assertSame(1, $b2['ledger_rows_with_minutes_set']);
        $this->assertSame(1, $b2['courses_with_minutes_set']);
        $this->assertSame(1, $b2['courses_with_minutes_ne_per_session']);
    }

    /**
     * Reverse must net by MINUTES. A 180-minute deduct reversed by a 120-minute
     * reverse leaves 60 minutes of drift; an event-count net would call it balanced.
     */
    public function test_reverse_net_is_measured_in_minutes_not_event_count(): void
    {
        $balanced = $this->course(['SessionDuration' => 120], $this->campusA);
        $this->ledger($balanced, 101, 'deduct', 180);
        $this->ledger($balanced, 101, 'reverse', 180);

        $drifting = $this->course(['SessionDuration' => 120], $this->campusA);
        $this->ledger($drifting, 202, 'deduct', 180);
        $this->ledger($drifting, 202, 'reverse', 120);

        $b2 = $this->report()['b2_minute_ledger_adoption'];

        $this->assertSame(1, $b2['courses_with_reverse_net_minutes_zero'], '180/180 truly nets to zero');
        $this->assertSame(
            1,
            $b2['courses_with_reverse_net_minutes_nonzero'],
            '180/120 leaves 60 minutes of drift — an event-count net would have missed it'
        );
    }

    public function test_campus_and_student_class_scopes_filter_correctly(): void
    {
        $scA = $this->course(['SessionDuration' => 120], $this->campusA);
        $this->sess($scA, '2026-06-01', '16:00:00', '19:00:00');
        $scB = $this->course(['SessionDuration' => 120], $this->campusB);
        $this->sess($scB, '2026-06-01', '16:00:00', '19:00:00');

        $byCampus = $this->report($this->campusA);
        $this->assertSame(1, $byCampus['meta']['courses_scanned']);
        $this->assertSame([$scA], $byCampus['b1_contract_schedule_mismatch']['affected_course_ids']);

        $byCourse = $this->report(null, $scB);
        $this->assertSame(1, $byCourse['meta']['courses_scanned']);
        $this->assertSame([$scB], $byCourse['b1_contract_schedule_mismatch']['affected_course_ids']);
    }

    public function test_details_flag_controls_identifier_output(): void
    {
        $sc = $this->course(['SessionDuration' => 120], $this->campusA);
        $this->sess($sc, '2026-06-01', '16:00:00', '19:00:00');

        $this->assertArrayNotHasKey('sample', $this->report()['b1_contract_schedule_mismatch']);

        $with = $this->report(null, null, true);
        // Limited identifiers only — no student name/phone/RFID may leak into CI logs.
        $this->assertSame(
            ['student_class_id', 'class_session_id', 'campus_id', 'phase',
                'contract_session_minutes', 'actual_duration_minutes', 'diff_minutes'],
            array_keys($with['b1_contract_schedule_mismatch']['sample'][0])
        );
    }

    /**
     * Query count must not grow with course count — an earlier draft ran one ledger
     * query per course, which would mean thousands of queries in production.
     */
    public function test_query_count_is_bounded_and_does_not_scale_with_course_count(): void
    {
        $seed = function (int $n, int $base): void {
            for ($i = 0; $i < $n; $i++) {
                $sc = $this->course(['SessionDuration' => 120], $this->campusA);
                $this->sess($sc, '2026-06-01', '16:00:00', '19:00:00');
                $this->ledger($sc, $base + $i, 'deduct', 180);
            }
        };
        $count = function (): int {
            DB::flushQueryLog();
            DB::enableQueryLog();
            try {
                $this->report(null, null, true);

                return count(DB::getQueryLog());
            } finally {
                DB::disableQueryLog();
                DB::flushQueryLog();
            }
        };

        $seed(3, 900);
        $small = $count();
        $seed(12, 800);
        $large = $count();

        $this->assertSame($small, $large, "query count went {$small} -> {$large} for 3 -> 15 courses");
        $this->assertLessThanOrEqual(8, $large, 'a handful of bulk queries, not a per-course loop');
    }

    /** Both output modes must be inert: no row count changes and no row content changes. */
    public function test_command_is_read_only_in_both_output_modes(): void
    {
        $sc = $this->course(['SessionDuration' => 120, 'RemainingMinutes' => 480], $this->campusA);
        $this->sess($sc, '2026-06-01', '16:00:00', '19:00:00');
        $this->ledger($sc, 1, 'deduct', 120);

        $snapshot = fn (): array => [
            'classes' => DB::table('StudentClass')->count(),
            'sessions' => DB::table('ClassSession')->count(),
            'ledger' => DB::table('session_deduction_ledger')->count(),
            'row' => (array) DB::table('StudentClass')->where('ID', $sc)->first(),
        ];
        $before = $snapshot();

        $this->assertSame(0, Artisan::call('sessions:report-nonstandard-duration', [
            '--json' => true, '--details' => true,
        ]));
        $this->assertStringContainsString('"READ_ONLY": true', Artisan::output());
        $this->assertSame($before, $snapshot(), 'json mode must not write');

        $this->assertSame(0, Artisan::call('sessions:report-nonstandard-duration', []));
        $this->assertStringContainsString('READ_ONLY=true', Artisan::output());
        $this->assertSame($before, $snapshot(), 'summary mode must not write');
    }

    // ── fixtures ──────────────────────────────────────────────────────

    private function makeCampus(int $id, string $name): void
    {
        $row = [
            'id' => $id, 'name' => $name, 'Current' => 0, 'LineNotifyID' => '', 'Client_ID' => '',
            'Client_Secret' => '', 'LIFFID' => '', 'LIFF_URL' => '', 'URL' => '', 'Token' => null,
            'TelegramToken' => null, 'TelegramChatID' => null, 'TelegramURL' => '',
            'TeachLIFFID' => '', 'TeachLIFF_URL' => '', 'code' => 'test-' . $id, 'SwipeWindowMinutes' => 10,
        ];
        DB::table('Campus')->insertOrIgnore(array_filter(
            $row,
            static fn ($c) => Schema::hasColumn('Campus', $c),
            ARRAY_FILTER_USE_KEY
        ));
    }

    private function course(array $over, int $campusId): int
    {
        do {
            $studentId = 990000 + random_int(1, 8999);
        } while (DB::table('Student')->where('id', $studentId)->exists());
        DB::table('Student')->insert([
            'id' => $studentId, 'name' => 'P0A Test', 'CampusID' => $campusId, 'ClassID' => 1, 'enable' => 1,
        ]);

        return (int) DB::table('StudentClass')->insertGetId(array_merge([
            'StudentID' => $studentId, 'GradeID' => 1, 'SubjectID' => 1, 'TeacherID' => 1,
            'by1' => 1, 'Period' => 4, 'TotalHours' => 0, 'Charge' => 0, 'Pay' => 0,
            'Paid' => 1, 'Rate' => 500, 'ClassType' => 'one_on_one',
            'StartDate' => '2026-05-01', 'EndDate' => '2026-12-31',
            'SessionCount' => 8, 'SessionDuration' => 120,
            'RemainingSessions' => 8, 'UsedSessions' => 0, 'Stop' => 0, 'ScheduleMode' => 'count',
        ], $over));
    }

    private function sess(int $sc, string $date, string $start, string $end, string $status = 'attended'): int
    {
        return (int) DB::table('ClassSession')->insertGetId([
            'StudentClassID' => $sc, 'SessionDate' => $date, 'StartTime' => $start, 'EndTime' => $end,
            'Status' => $status, 'Note' => '', 'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    private function makeupSchedule(int $sc, string $date, string $start, string $end): void
    {
        DB::table('schedules')->insert([
            'student_id' => 0, 'teacher_id' => 0, 'subject' => 'Math', 'day_of_week' => 1,
            'start_time' => $start, 'end_time' => $end, 'class_type' => 'one_on_one',
            'status' => 'scheduled', 'type' => 'extra', 'deduction' => 1, 'branch_id' => $this->campusA,
            'schedule_date' => $date, 'student_course_id' => $sc, 'original_schedule_id' => 0,
        ]);
    }

    private function ledger(int $sc, int $csId, string $eventType, ?int $minutes): void
    {
        DB::table('session_deduction_ledger')->insert([
            'student_class_id' => $sc, 'class_session_id' => $csId, 'event_type' => $eventType,
            'source' => 'attendance', 'minutes' => $minutes, 'created_at' => now(), 'updated_at' => now(),
        ]);
    }
}
