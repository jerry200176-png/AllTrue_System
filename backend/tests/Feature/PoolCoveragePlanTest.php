<?php

namespace Tests\Feature;

use App\Services\Scheduling\CommitmentReasonCodes;
use App\Services\Scheduling\PoolCoveragePlanService;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/** ADR-006 Phase 3 pool coverage plan — read-only. */
class PoolCoveragePlanTest extends TestCase
{
    use RefreshDatabase;

    private Carbon $today;

    protected function setUp(): void
    {
        parent::setUp();
        $this->today = Carbon::parse('2026-07-13', 'Asia/Taipei');
    }

    public function test_pool_plan_is_read_only_and_projects_shortage(): void
    {
        $owner = 98501;
        DB::table('Student')->insert([
            'id' => $owner, 'name' => 'Owner', 'CampusID' => 1, 'ClassID' => 1, 'enable' => 1,
        ]);
        $pkgId = (int) DB::table('course_packages')->insertGetId([
            'student_id' => $owner, 'campus_id' => 1, 'name' => 'P3 pool',
            'total_sessions' => 10, 'remaining_sessions' => 1, 'used_sessions' => 0,
            'stop' => 0, 'created_at' => now(), 'updated_at' => now(),
        ]);
        $sc = $this->member($pkgId);
        foreach (range(1, 9) as $i) {
            DB::table('package_session_ledger')->insert([
                'package_id' => $pkgId, 'student_class_id' => $sc,
                'class_session_id' => null, 'delta' => -1, 'reason' => 'test-consumed-' . $i,
                'created_at' => now(), 'updated_at' => now(),
            ]);
        }
        $before = DB::table('ClassSession')->count();

        $dto = app(PoolCoveragePlanService::class)->planForPackage($pkgId, null, $this->today);
        $this->assertTrue($dto['ok']);
        $this->assertTrue($dto['allocation']['meta']['read_only']);
        $this->assertFalse($dto['allocation']['meta']['writes']);
        $this->assertFalse($dto['pool_projection']['ensure_would_block']);
        $this->assertTrue($dto['pool_projection']['renewal_warning']);
        $this->assertGreaterThan(0, $dto['pool_projection']['overage_sessions']);
        $this->assertNull($dto['pool_projection']['ensure_block_reason']);
        $this->assertArrayNotHasKey('member_pool_remaining', $dto);
        $this->assertSame($before, DB::table('ClassSession')->count());
        $this->assertSame($sc, $dto['members'][0]['student_class_id']);
    }

    public function test_command_read_only(): void
    {
        $owner = 98502;
        DB::table('Student')->insert([
            'id' => $owner, 'name' => 'Owner2', 'CampusID' => 1, 'ClassID' => 1, 'enable' => 1,
        ]);
        $pkgId = (int) DB::table('course_packages')->insertGetId([
            'student_id' => $owner, 'campus_id' => 1, 'name' => 'P3 pool2',
            'total_sessions' => 10, 'remaining_sessions' => 8, 'used_sessions' => 0,
            'stop' => 0, 'created_at' => now(), 'updated_at' => now(),
        ]);
        $this->member($pkgId);
        $before = DB::table('ClassSession')->count();
        Carbon::setTestNow($this->today);
        try {
            $this->assertSame(0, Artisan::call('sessions:plan-coverage', [
                'package_id' => $pkgId, '--as-of' => '2026-07-13', '--summary' => true,
            ]));
        } finally {
            Carbon::setTestNow();
        }
        $this->assertSame($before, DB::table('ClassSession')->count());
        $this->assertStringContainsString('DRY-RUN', Artisan::output());
    }

    public function test_group_package_pool_plan_deduplicates_same_physical_slot(): void
    {
        $owner = 98503;
        DB::table('Student')->insert([
            'id' => $owner, 'name' => 'Owner3', 'CampusID' => 1, 'ClassID' => 1, 'enable' => 1,
        ]);
        $pkgId = (int) DB::table('course_packages')->insertGetId([
            'student_id' => $owner, 'campus_id' => 1, 'name' => 'P3 group pool',
            'class_type' => 'one_on_three', 'total_sessions' => 10,
            'remaining_sessions' => 10, 'used_sessions' => 0,
            'stop' => 0, 'created_at' => now(), 'updated_at' => now(),
        ]);
        $this->member($pkgId);
        $this->member($pkgId);

        $dto = app(PoolCoveragePlanService::class)->planForPackage($pkgId, null, $this->today);
        $allocations = $dto['allocation']['allocations'];
        $slotKeys = array_values(array_unique(array_map(
            static fn (array $row): string => $row['date'] . '|' . $row['start_hm'],
            $allocations
        )));

        $this->assertCount(count($allocations), $slotKeys);
        $this->assertSame(count($allocations), $dto['pool_projection']['horizon_expected_new']);
    }

    private function member(int $pkgId): int
    {
        $studentId = 98600 + random_int(1, 8999);
        DB::table('Student')->insert([
            'id' => $studentId, 'name' => 'P3 member', 'CampusID' => 1, 'ClassID' => 1, 'enable' => 1,
        ]);
        $sc = (int) DB::table('StudentClass')->insertGetId([
            'StudentID' => $studentId, 'GradeID' => 1, 'SubjectID' => 1, 'TeacherID' => 1,
            'by1' => 1, 'Period' => 4, 'TotalHours' => 0, 'Charge' => 0, 'Pay' => 0,
            'Paid' => 1, 'Rate' => 500, 'ClassType' => 'one_on_one',
            'StartDate' => '2026-05-01', 'EndDate' => '2026-12-31',
            'SessionCount' => 20, 'SessionDuration' => 120,
            'RemainingSessions' => 0, 'UsedSessions' => 0, 'Stop' => 0, 'ScheduleMode' => 'count',
            'PackageID' => $pkgId, 'week' => 1, 'time' => '16:00',
        ]);
        foreach (['2026-06-22', '2026-06-29', '2026-07-06'] as $d) {
            DB::table('ClassSession')->insert([
                'StudentClassID' => $sc, 'SessionDate' => $d, 'StartTime' => '16:00:00',
                'EndTime' => '18:00:00', 'Status' => 'attended', 'Note' => '',
                'created_at' => '2026-06-01 10:00:00', 'updated_at' => '2026-06-01 10:00:00',
            ]);
        }

        return $sc;
    }
}
