<?php

namespace Tests\Feature;

use App\Services\BusinessDigestService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * ops:business-digest / BusinessDigestService — read-only BI + anomaly metrics.
 * Asserts the service directly (deterministic) and that the command runs read-only.
 */
class OpsBusinessDigestTest extends TestCase
{
    use RefreshDatabase;

    public function test_service_reports_revenue_retention_and_coverage(): void
    {
        $studentId = 95001;
        DB::table('Student')->insert([
            'id' => $studentId, 'name' => 'Digest Test', 'CampusID' => 1, 'ClassID' => 1, 'enable' => 1,
        ]);
        // Stranded prepaid course: active, count-mode, remaining>0, NO upcoming session.
        DB::table('StudentClass')->insert([
            'StudentID' => $studentId, 'GradeID' => 1, 'SubjectID' => 1, 'TeacherID' => 1,
            'by1' => 1, 'Period' => 4, 'TotalHours' => 0, 'Charge' => 0, 'Pay' => 0,
            'Paid' => 1, 'Rate' => 500, 'ClassType' => 'one_on_two',
            'StartDate' => now()->subDays(30)->toDateTimeString(),
            'SessionCount' => 8, 'SessionDuration' => 60,
            'RemainingSessions' => 3, 'UsedSessions' => 5, 'Stop' => 0, 'ScheduleMode' => 'count',
        ]);

        $m = app(BusinessDigestService::class)->metrics();

        $this->assertSame(3, $m['revenue']['stranded_sessions']);
        $this->assertEqualsWithDelta(1500.0, $m['revenue']['stranded_amount'], 0.5); // 3 x NT$500
        $this->assertGreaterThanOrEqual(1, $m['retention']['no_upcoming_students']);
        $this->assertSame(0, $m['coverage']['sessions_next_7d']);
        $this->assertArrayHasKey('attended_without_lr', $m['data_quality']);
        $this->assertArrayHasKey('scheduled_cross_sc', $m['data_quality']);
        $this->assertArrayHasKey('orphan_stop_scheduled', $m['data_quality']);
        $this->assertSame(0, $m['data_quality']['scheduled_cross_sc']);
        $this->assertSame(0, $m['data_quality']['orphan_stop_scheduled']);

        // #1149/#1152 decomposition: the seeded course is a dormant-prepaid case
        // (count-mode, balance 3, no upcoming) → 3 x NT$500 recoverable, 0 re-enroll.
        $this->assertSame(1, $m['retention']['dormant_prepaid_students']);
        $this->assertSame(1500, $m['retention']['dormant_prepaid_recoverable_ntd']);
        $this->assertSame(0, $m['retention']['reenroll_candidates']);

        $anom = app(BusinessDigestService::class)->anomalies($m);
        $this->assertNotEmpty($anom); // stranded>0 and coverage=0 both flag
    }

    public function test_scheduled_cross_sc_and_orphan_stop_metrics(): void
    {
        $studentId = 95020;
        DB::table('Student')->insert([
            'id' => $studentId, 'name' => 'Dup Pending', 'CampusID' => 9, 'ClassID' => 1, 'enable' => 1,
        ]);
        $scA = (int) DB::table('StudentClass')->insertGetId([
            'StudentID' => $studentId, 'GradeID' => 1, 'SubjectID' => 1, 'TeacherID' => 1,
            'by1' => 1, 'Period' => 4, 'TotalHours' => 0, 'Charge' => 0, 'Pay' => 0,
            'Paid' => 1, 'Rate' => 500, 'ClassType' => 'one_on_one',
            'StartDate' => now()->toDateTimeString(), 'SessionCount' => 8, 'SessionDuration' => 60,
            'RemainingSessions' => 4, 'UsedSessions' => 4, 'Stop' => 0, 'ScheduleMode' => 'count',
        ]);
        $scB = (int) DB::table('StudentClass')->insertGetId([
            'StudentID' => $studentId, 'GradeID' => 1, 'SubjectID' => 1, 'TeacherID' => 1,
            'by1' => 1, 'Period' => 4, 'TotalHours' => 0, 'Charge' => 0, 'Pay' => 0,
            'Paid' => 0, 'Rate' => 500, 'ClassType' => 'one_on_one',
            'StartDate' => now()->toDateTimeString(), 'SessionCount' => 0, 'SessionDuration' => 60,
            'RemainingSessions' => 0, 'UsedSessions' => 0, 'Stop' => 1, 'ScheduleMode' => 'count',
        ]);
        $date = now()->addDays(2)->toDateString();
        DB::table('ClassSession')->insert([
            ['StudentClassID' => $scA, 'SessionDate' => $date, 'StartTime' => '13:00:00', 'EndTime' => '15:00:00', 'Status' => 'scheduled', 'created_at' => now(), 'updated_at' => now()],
            ['StudentClassID' => $scB, 'SessionDate' => $date, 'StartTime' => '13:00:00', 'EndTime' => '15:00:00', 'Status' => 'scheduled', 'created_at' => now(), 'updated_at' => now()],
        ]);

        $m = app(BusinessDigestService::class)->metrics(9);
        $this->assertSame(1, $m['data_quality']['scheduled_cross_sc']);
        $this->assertSame(1, $m['data_quality']['orphan_stop_scheduled']);

        $anom = app(BusinessDigestService::class)->anomalies($m);
        $this->assertTrue(collect($anom)->contains(fn ($s) => str_contains($s, 'scheduled cross-SC')));
        $this->assertTrue(collect($anom)->contains(fn ($s) => str_contains($s, 'orphan Stop=1')));
    }

    public function test_retention_decomposition_splits_dormant_and_reenroll(): void
    {
        // Re-enrollment candidate: active count-mode course, balance EXHAUSTED (0),
        // no month course, no upcoming session → counts as re-enroll, NOT dormant-prepaid.
        $reId = 95010;
        DB::table('Student')->insert(['id' => $reId, 'name' => 'Reenroll', 'CampusID' => 1, 'ClassID' => 1, 'enable' => 1]);
        DB::table('StudentClass')->insert([
            'StudentID' => $reId, 'GradeID' => 1, 'SubjectID' => 1, 'TeacherID' => 1, 'by1' => 1, 'Period' => 4,
            'TotalHours' => 0, 'Charge' => 0, 'Pay' => 0, 'Paid' => 1, 'Rate' => 500, 'ClassType' => 'one_on_one',
            'StartDate' => now()->subDays(60)->toDateTimeString(), 'SessionCount' => 8, 'SessionDuration' => 60,
            'RemainingSessions' => 0, 'UsedSessions' => 8, 'Stop' => 0, 'ScheduleMode' => 'count',
        ]);

        $m = app(BusinessDigestService::class)->metrics();

        $this->assertSame(1, $m['retention']['reenroll_candidates']);
        $this->assertSame(0, $m['retention']['dormant_prepaid_students']);
        $this->assertSame(0, $m['retention']['dormant_prepaid_recoverable_ntd']);
        $this->assertGreaterThanOrEqual(1, $m['retention']['no_upcoming_students']);
    }

    public function test_counter_divergence_separates_actionable_exposure_from_legacy_baselines(): void
    {
        $studentId = 95020;
        DB::table('Student')->insert([
            'id' => $studentId, 'name' => 'Counter Drift', 'CampusID' => 1, 'ClassID' => 1, 'enable' => 1,
        ]);

        $base = [
            'StudentID' => $studentId, 'GradeID' => 1, 'SubjectID' => 1, 'TeacherID' => 1,
            'by1' => 1, 'Period' => 4, 'TotalHours' => 0, 'Charge' => 0, 'Pay' => 0,
            'Paid' => 1, 'ClassType' => 'one_on_one', 'StartDate' => now()->subDays(30)->toDateTimeString(),
            'SessionDuration' => 60, 'Stop' => 0, 'ScheduleMode' => 'count',
        ];

        // Expected remaining is 8; stored 5 creates a three-session, NT$1,800
        // actionable discrepancy because the purchased-session baseline is known.
        DB::table('StudentClass')->insert($base + [
            'SessionCount' => 10, 'UsedSessions' => 2, 'RemainingSessions' => 5, 'Rate' => 600,
        ]);

        // A zero SessionCount cannot establish purchased-session truth. It must
        // remain visible, but not inflate the actionable billing exposure.
        DB::table('StudentClass')->insert($base + [
            'SessionCount' => 0, 'UsedSessions' => 4, 'RemainingSessions' => 0, 'Rate' => 700,
        ]);

        $metrics = app(BusinessDigestService::class)->metrics();
        $quality = $metrics['data_quality'];

        $this->assertSame(2, $quality['remaining_divergent']);
        $this->assertSame(1, $quality['remaining_divergent_actionable']);
        $this->assertSame(3, $quality['remaining_divergent_actionable_sessions']);
        $this->assertSame(1800, $quality['remaining_divergent_actionable_ntd']);
        $this->assertSame(1, $quality['remaining_divergent_legacy_baseline']);

        $ledgerDecision = collect($metrics['decision_center']['decisions'])->firstWhere('key', 'ledger_divergent');
        $this->assertNotNull($ledgerDecision);
        $this->assertSame(1, $ledgerDecision['people_total']);
        $this->assertStringContainsString('1 筆', $ledgerDecision['title']);
    }

    public function test_command_runs_read_only(): void
    {
        $before = DB::table('ClassSession')->count();
        $this->assertSame(0, Artisan::call('ops:business-digest'));
        $this->assertSame(0, Artisan::call('ops:business-digest', ['--json' => true]));
        $this->assertSame($before, DB::table('ClassSession')->count());
    }
}
