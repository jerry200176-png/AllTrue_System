<?php

namespace Tests\Feature;

use App\Services\Scheduling\CommitmentReasonCodes;
use App\Services\Scheduling\PreviewSessionHorizonService;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/** ADR-006 Phase 1A PreviewSessionHorizon — read-only dry-run. */
class PreviewSessionHorizonTest extends TestCase
{
    use RefreshDatabase;

    private Carbon $today;

    protected function setUp(): void
    {
        parent::setUp();
        $this->today = Carbon::parse('2026-07-13', 'Asia/Taipei');
    }

    public function test_explicit_preview_marks_uncovered_when_remaining_exhausted(): void
    {
        $sc = $this->course([
            'week' => 1, 'time' => '16:00', 'RemainingSessions' => 1,
        ]);
        foreach (['2026-06-22', '2026-06-29', '2026-07-06'] as $d) {
            $this->sess($sc, $d, '16:00:00', '18:00:00');
        }

        $dto = app(PreviewSessionHorizonService::class)->preview($sc, null, $this->today);
        $p = $dto['preview'];

        $this->assertTrue($dto['meta']['read_only']);
        $this->assertFalse($dto['meta']['will_create_sessions']);
        $this->assertSame(CommitmentReasonCodes::CLASS_EXPLICIT, $p['classification']);
        $this->assertTrue($p['auto_ensure_eligible']);
        $this->assertGreaterThan(0, $p['occurrence_summary']['expected_new']);
        $this->assertSame(1, $p['occurrence_summary']['coverable_new']);
        $this->assertGreaterThan(0, $p['occurrence_summary']['uncovered_new']);

        $codes = array_column($p['occurrences'], 'reason_code');
        $this->assertContains(CommitmentReasonCodes::OK_PLAN, $codes);
        $this->assertContains(CommitmentReasonCodes::OK_PREVIEW_UNCOVERED, $codes);
    }

    public function test_legacy_is_not_ensure_eligible_and_has_suggested_cadence(): void
    {
        $sc = $this->course(['RemainingSessions' => 5]);
        foreach (['2026-06-22', '2026-06-29', '2026-07-06'] as $d) {
            $this->sess($sc, $d, '16:00:00', '18:00:00');
        }

        $p = app(PreviewSessionHorizonService::class)->preview($sc, null, $this->today)['preview'];
        $this->assertSame(CommitmentReasonCodes::CLASS_LEGACY, $p['classification']);
        $this->assertSame(CommitmentReasonCodes::LEGACY_INFERRED_CANDIDATE, $p['primary_reason']);
        $this->assertFalse($p['auto_ensure_eligible']);
        $this->assertNotNull($p['suggested_cadence']);
        $this->assertSame([], $p['occurrences']);
    }

    public function test_shared_pool_projection_without_member_remaining_field(): void
    {
        $ownerStudent = (int) DB::table('Student')->insertGetId([
            'name' => 'Pkg Owner', 'CampusID' => 1, 'ClassID' => 1, 'enable' => 1,
        ]);
        $pkgId = (int) DB::table('course_packages')->insertGetId([
            'student_id' => $ownerStudent, 'campus_id' => 1, 'name' => 'P1A pool',
            'total_sessions' => 10, 'remaining_sessions' => 2, 'used_sessions' => 0,
            'stop' => 0, 'created_at' => now(), 'updated_at' => now(),
        ]);
        $sc = $this->course([
            'week' => 1, 'time' => '16:00', 'RemainingSessions' => 0, 'PackageID' => $pkgId,
        ]);
        foreach (['2026-06-22', '2026-06-29', '2026-07-06'] as $d) {
            $this->sess($sc, $d, '16:00:00', '18:00:00');
        }

        $p = app(PreviewSessionHorizonService::class)->preview($sc, null, $this->today)['preview'];
        $this->assertNotNull($p['pool_projection']);
        $this->assertSame(2, $p['pool_projection']['horizon_coverable']);
        $this->assertFalse($p['pool_projection']['ensure_would_block']);
        $this->assertTrue($p['pool_projection']['renewal_warning']);
        $this->assertGreaterThan(0, $p['pool_projection']['overage_sessions']);
        $this->assertNull($p['pool_projection']['ensure_block_reason']);
        $this->assertArrayNotHasKey('pool_remaining', $p);
        $this->assertArrayNotHasKey('member_pool_remaining', $p);
    }

    public function test_branch_isolation_fail_closed(): void
    {
        $sc = $this->course(['week' => 1, 'time' => '16:00', 'RemainingSessions' => 2], campusId: 1);
        $p = app(PreviewSessionHorizonService::class)
            ->preview($sc, null, $this->today, requireCampusId: 99)['preview'];
        $this->assertFalse($p['ok']);
        $this->assertSame(CommitmentReasonCodes::FAIL_BRANCH_ISOLATION, $p['primary_reason']);
    }

    public function test_command_is_read_only(): void
    {
        $sc = $this->course(['week' => 1, 'time' => '16:00', 'RemainingSessions' => 2]);
        foreach (['2026-06-22', '2026-06-29', '2026-07-06'] as $d) {
            $this->sess($sc, $d, '16:00:00', '18:00:00');
        }
        $before = DB::table('ClassSession')->count();
        Carbon::setTestNow($this->today);
        try {
            $this->assertSame(0, Artisan::call('sessions:preview-horizon', [
                'student_class_id' => $sc, '--as-of' => '2026-07-13',
            ]));
        } finally {
            Carbon::setTestNow();
        }
        $this->assertSame($before, DB::table('ClassSession')->count());
        $this->assertStringContainsString('PreviewSessionHorizon', Artisan::output());
    }

    /** @param array<string,mixed> $over */
    private function course(array $over, int $campusId = 1): int
    {
        $studentId = (int) DB::table('Student')->insertGetId([
            'name' => 'P1A Test', 'CampusID' => $campusId, 'ClassID' => 1, 'enable' => 1,
        ]);
        $base = [
            'StudentID' => $studentId, 'GradeID' => 1, 'SubjectID' => 1, 'TeacherID' => 1,
            'by1' => 1, 'Period' => 4, 'TotalHours' => 0, 'Charge' => 0, 'Pay' => 0,
            'Paid' => 1, 'Rate' => 500, 'ClassType' => 'one_on_one',
            'StartDate' => '2026-05-01', 'EndDate' => '2026-12-31',
            'SessionCount' => 20, 'SessionDuration' => 120,
            'RemainingSessions' => 8, 'UsedSessions' => 0, 'Stop' => 0, 'ScheduleMode' => 'count',
        ];
        foreach (['week', 'time', 'PackageID'] as $k) {
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
