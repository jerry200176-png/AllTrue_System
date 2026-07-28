<?php

namespace Tests\Feature;

use App\Services\Scheduling\CommitmentReasonCodes;
use App\Services\Scheduling\PrepaidHorizonPhase0Reporter;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * ADR-006 Phase 0 reporter — read-only evidence; MF excludes legacy_inferred.
 */
class PrepaidHorizonPhase0ReportTest extends TestCase
{
    use RefreshDatabase;

    private Carbon $today;

    protected function setUp(): void
    {
        parent::setUp();
        $this->today = Carbon::parse('2026-07-13', 'Asia/Taipei');
    }

    public function test_q1_counts_only_explicit_materialization_gaps_not_legacy(): void
    {
        // Explicit FRP with Mon 16:00 contract, history aligned, no future sessions → MF
        $explicit = $this->course([
            'week' => 1, 'time' => '16:00', 'RemainingSessions' => 8,
        ]);
        foreach (['2026-06-22', '2026-06-29', '2026-07-06'] as $d) {
            $this->sess($explicit, $d, '16:00:00', '18:00:00', 'attended');
        }

        // Legacy-only: no contract slots, confirmed history, no future → must NOT enter Q1
        $legacy = $this->course(['RemainingSessions' => 5]);
        foreach (['2026-06-22', '2026-06-29', '2026-07-06'] as $d) {
            $this->sess($legacy, $d, '16:00:00', '18:00:00', 'attended');
        }

        $report = app(PrepaidHorizonPhase0Reporter::class)->build(null, $this->today);
        $q1 = $report['questions']['q1_explicit_materialization_gap'];
        $q2 = $report['questions']['q2_no_explicit_commitment_split'];

        $this->assertSame(1, $q1['courses'], 'only explicit gap course');
        $this->assertGreaterThan(0, $q1['missing_occurrences_28d']);
        $this->assertSame(1, $q2[CommitmentReasonCodes::LEGACY_INFERRED_CANDIDATE]);
        $this->assertArrayHasKey(CommitmentReasonCodes::CLASS_EXPLICIT, $report['classifications']);
        $this->assertArrayHasKey(CommitmentReasonCodes::CLASS_LEGACY, $report['classifications']);
    }

    public function test_command_is_read_only(): void
    {
        $sc = $this->course(['week' => 1, 'time' => '16:00', 'RemainingSessions' => 4]);
        foreach (['2026-06-22', '2026-06-29', '2026-07-06'] as $d) {
            $this->sess($sc, $d, '16:00:00', '18:00:00', 'attended');
        }
        $beforeSessions = DB::table('ClassSession')->count();
        $beforeClasses = DB::table('StudentClass')->count();

        Carbon::setTestNow($this->today);
        try {
            $this->assertSame(0, Artisan::call('sessions:report-prepaid-horizon-phase0', [
                '--as-of' => '2026-07-13',
                '--json' => true,
            ]));
        } finally {
            Carbon::setTestNow();
        }

        $this->assertSame($beforeSessions, DB::table('ClassSession')->count());
        $this->assertSame($beforeClasses, DB::table('StudentClass')->count());
        $this->assertStringContainsString('adr006-phase0', Artisan::output());
    }

    public function test_flexible_split_separate_from_incomplete(): void
    {
        // Flexible: no slots, scattered history
        $flex = $this->course(['RemainingSessions' => 3]);
        $this->sess($flex, '2026-07-06', '16:00:00', '18:00:00', 'attended');
        $this->sess($flex, '2026-07-08', '17:00:00', '19:00:00', 'attended');

        // Incomplete: week without time
        $inc = $this->course(['week' => 1, 'time' => null, 'RemainingSessions' => 3]);

        $report = app(PrepaidHorizonPhase0Reporter::class)->build(null, $this->today);
        $q2 = $report['questions']['q2_no_explicit_commitment_split'];
        $this->assertSame(1, $q2[CommitmentReasonCodes::INFO_FLEXIBLE_NO_COMMITMENT]);
        $this->assertSame(1, $q2[CommitmentReasonCodes::BLOCK_COMMITMENT_INCOMPLETE]);
    }

    public function test_adapter_assessment_marks_v1_not_permanent_ssot(): void
    {
        $this->course(['week' => 1, 'time' => '16:00', 'RemainingSessions' => 2]);
        $report = app(PrepaidHorizonPhase0Reporter::class)->build(null, $this->today);
        $a = $report['student_class_adapter_assessment'];
        $this->assertFalse($a['permanent_ssot']);
        $this->assertSame('StudentClass contract fields', $a['v1_adapter']);
    }

    /** @param array<string,mixed> $over */
    private function course(array $over): int
    {
        $studentId = 97000 + random_int(1, 8999);
        DB::table('Student')->insert([
            'id' => $studentId, 'name' => 'P0 Test', 'CampusID' => 1, 'ClassID' => 1, 'enable' => 1,
        ]);
        $base = [
            'StudentID' => $studentId, 'GradeID' => 1, 'SubjectID' => 1, 'TeacherID' => 1,
            'by1' => 1, 'Period' => 4, 'TotalHours' => 0, 'Charge' => 0, 'Pay' => 0,
            'Paid' => 1, 'Rate' => 500, 'ClassType' => 'one_on_one',
            'StartDate' => '2026-05-01', 'EndDate' => '2026-12-31',
            'SessionCount' => 20, 'SessionDuration' => 120,
            'RemainingSessions' => 8, 'UsedSessions' => 0, 'Stop' => 0, 'ScheduleMode' => 'count',
        ];
        foreach (['week', 'time', 'week1', 'time1', 'PackageID'] as $k) {
            if (array_key_exists($k, $over)) {
                $base[$k] = $over[$k];
                unset($over[$k]);
            }
        }
        $base = array_merge($base, $over);

        return (int) DB::table('StudentClass')->insertGetId($base);
    }

    private function sess(int $sc, string $date, string $start, string $end, string $status): void
    {
        DB::table('ClassSession')->insert([
            'StudentClassID' => $sc, 'SessionDate' => $date, 'StartTime' => $start,
            'EndTime' => $end, 'Status' => $status, 'Note' => '',
            'created_at' => '2026-06-01 10:00:00', 'updated_at' => '2026-06-01 10:00:00',
        ]);
    }
}
