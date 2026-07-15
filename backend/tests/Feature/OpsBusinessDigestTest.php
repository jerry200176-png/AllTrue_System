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

        // #1149/#1152 decomposition: the seeded course is a dormant-prepaid case
        // (count-mode, balance 3, no upcoming) → 3 x NT$500 recoverable, 0 re-enroll.
        $this->assertSame(1, $m['retention']['dormant_prepaid_students']);
        $this->assertSame(1500, $m['retention']['dormant_prepaid_recoverable_ntd']);
        $this->assertSame(0, $m['retention']['reenroll_candidates']);

        $anom = app(BusinessDigestService::class)->anomalies($m);
        $this->assertNotEmpty($anom); // stranded>0 and coverage=0 both flag
        $this->assertArrayHasKey('trust', $m);
        $this->assertSame('red', $m['trust']['paid_class_visibility']['status']);
        $this->assertSame('forward_only_no_historical_amend', $m['trust']['invoice_trust']['policy']);
        $this->assertFalse($m['trust']['dormant_hold']['auto_generate']);
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

    public function test_command_runs_read_only(): void
    {
        $before = DB::table('ClassSession')->count();
        $this->assertSame(0, Artisan::call('ops:business-digest'));
        $this->assertSame(0, Artisan::call('ops:business-digest', ['--json' => true]));
        $this->assertSame($before, DB::table('ClassSession')->count());
    }
}
