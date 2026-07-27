<?php

namespace Tests\Unit\Services\Dashboard;

use App\Models\Campus;
use App\Models\CampusSnapshot;
use Carbon\Carbon;
use Database\Factories\CampusSnapshotFactory;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * TrendService — student/revenue/attendance trends with labels, datasets, colors.
 *
 * Tests the documented contract:
 *   - getStudentTrend, getRevenueTrend, getAttendanceTrend
 *   - Labels in YYYY-MM, datasets per campus with color
 *   - Campus colors: 1=#4CAF50, 2=#2196F3, 3=#FF9800, 4=#9C27B0, 0=#212121
 *   - availableMonths tracking, note for < 2 months
 *   - Attendance trend includes 85% baseline
 *   - Empty campus exclusion from datasets
 *
 * ⚠️ Depends on Phase 2 implementation (TrendService class).
 *   Tests marked skipped until class exists. Unskip when Phase 2 is complete.
 */
class TrendServiceTest extends TestCase
{
    use RefreshDatabase;

    private Campus $campus1;
    private Campus $campus2;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow(Carbon::parse('2026-07-15 12:00:00', 'Asia/Taipei'));
        $this->campus1 = Campus::factory()->create(['name' => '大安', 'code' => 'daan']);
        $this->campus2 = Campus::factory()->create(['name' => '新店', 'code' => 'xindian']);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    // ─── Student Trend ────────────────────────────────────────────────────

    /** @test */
    public function it_returns_student_trend_with_correct_structure(): void
    {
        $this->markTestSkipped('Phase 2 not yet implemented — TrendService class not available.');
        $this->seedMultiMonthTrend($this->campus1->id, 'student_count', 6);

        $result = app(\App\Services\Dashboard\TrendService::class)
            ->getStudentTrend($this->campus1->id, 6);

        $this->assertArrayHasKey('labels', $result);
        $this->assertArrayHasKey('datasets', $result);
        $this->assertArrayHasKey('availableMonths', $result);
        $this->assertCount(6, $result['labels']);
        $this->assertSame('2026-02', $result['labels'][0]);
        $this->assertSame('2026-07', $result['labels'][5]);
    }

    /** @test */
    public function student_trend_returns_campus_colors(): void
    {
        $this->markTestSkipped('Phase 2 not yet implemented — TrendService class not available.');
        CampusSnapshotFactory::createSnapshotSet($this->campus1->id, '2026-07-01', ['student_count' => 50]);
        CampusSnapshotFactory::createSnapshotSet($this->campus1->id, '2026-06-01', ['student_count' => 45]);

        $result = app(\App\Services\Dashboard\TrendService::class)
            ->getStudentTrend(null, 12);

        $dataset = collect($result['datasets'])->firstWhere('campusId', $this->campus1->id);
        $this->assertNotNull($dataset);
        $this->assertSame('大安', $dataset['campusName']);
        $this->assertSame('#4CAF50', $dataset['color']);
    }

    /** @test */
    public function student_trend_includes_total_dataset(): void
    {
        $this->markTestSkipped('Phase 2 not yet implemented — TrendService class not available.');
        CampusSnapshotFactory::createSnapshotSet($this->campus1->id, '2026-07-01', ['student_count' => 50]);
        CampusSnapshotFactory::createSnapshotSet($this->campus1->id, '2026-06-01', ['student_count' => 45]);
        CampusSnapshotFactory::createSnapshotSet($this->campus2->id, '2026-07-01', ['student_count' => 30]);
        CampusSnapshotFactory::createSnapshotSet($this->campus2->id, '2026-06-01', ['student_count' => 28]);

        $result = app(\App\Services\Dashboard\TrendService::class)
            ->getStudentTrend(null, 12);

        $total = collect($result['datasets'])->firstWhere('isTotal', true);
        $this->assertNotNull($total);
        $this->assertSame('全部', $total['campusName']);
        $this->assertSame('#212121', $total['color']);
    }

    /** @test */
    public function student_trend_shows_note_when_less_than_two_months(): void
    {
        $this->markTestSkipped('Phase 2 not yet implemented — TrendService class not available.');
        CampusSnapshotFactory::createSnapshotSet($this->campus1->id, '2026-07-01', ['student_count' => 50]);

        $result = app(\App\Services\Dashboard\TrendService::class)
            ->getStudentTrend($this->campus1->id, 12);

        $this->assertSame(1, $result['availableMonths']);
        $this->assertNotNull($result['note']);
        $this->assertStringContainsString('1 個月', $result['note']);
    }

    // ─── Revenue Trend ────────────────────────────────────────────────────

    /** @test */
    public function it_returns_revenue_trend_with_correct_structure(): void
    {
        $this->markTestSkipped('Phase 2 not yet implemented — TrendService class not available.');
        $this->seedMultiMonthTrend($this->campus1->id, 'monthly_revenue', 6);

        $result = app(\App\Services\Dashboard\TrendService::class)
            ->getRevenueTrend($this->campus1->id, 6);

        $this->assertArrayHasKey('labels', $result);
        $this->assertArrayHasKey('datasets', $result);
        $this->assertArrayHasKey('groupBy', $result);
        $this->assertSame('campus', $result['groupBy']);
        $this->assertCount(6, $result['labels']);
    }

    /** @test */
    public function revenue_trend_aggregates_by_campus(): void
    {
        $this->markTestSkipped('Phase 2 not yet implemented — TrendService class not available.');
        CampusSnapshotFactory::createSnapshotSet($this->campus1->id, '2026-07-01', ['monthly_revenue' => 200000]);
        CampusSnapshotFactory::createSnapshotSet($this->campus1->id, '2026-06-01', ['monthly_revenue' => 180000]);
        CampusSnapshotFactory::createSnapshotSet($this->campus2->id, '2026-07-01', ['monthly_revenue' => 150000]);
        CampusSnapshotFactory::createSnapshotSet($this->campus2->id, '2026-06-01', ['monthly_revenue' => 130000]);

        $result = app(\App\Services\Dashboard\TrendService::class)
            ->getRevenueTrend(null, 12);

        $this->assertCount(2, $result['datasets']); // 2 campuses
        $campusIds = array_column($result['datasets'], 'campusId');
        $this->assertContains($this->campus1->id, $campusIds);
        $this->assertContains($this->campus2->id, $campusIds);
    }

    // ─── Attendance Trend ─────────────────────────────────────────────────

    /** @test */
    public function it_returns_attendance_trend_with_baseline(): void
    {
        $this->markTestSkipped('Phase 2 not yet implemented — TrendService class not available.');
        CampusSnapshotFactory::createSnapshotSet($this->campus1->id, '2026-07-01', [
            'monthly_sessions' => 400,
            'monthly_attendance' => 360, // 90%
        ]);
        CampusSnapshotFactory::createSnapshotSet($this->campus1->id, '2026-06-01', [
            'monthly_sessions' => 380,
            'monthly_attendance' => 342, // 90%
        ]);

        $result = app(\App\Services\Dashboard\TrendService::class)
            ->getAttendanceTrend($this->campus1->id, 12);

        $this->assertArrayHasKey('baseline', $result);
        $this->assertSame(85, $result['baseline']['value']);
        $this->assertSame('85% 警示線', $result['baseline']['label']);
        $this->assertSame('#F44336', $result['baseline']['color']);
        $this->assertTrue($result['baseline']['dashed']);

        $dataset = $result['datasets'][0];
        $this->assertEquals(90.0, $dataset['data'][0]); // first month = 90%
        $this->assertArrayHasKey('campusId', $dataset);
        $this->assertArrayHasKey('campusName', $dataset);
        $this->assertArrayHasKey('color', $dataset);
    }

    /** @test */
    public function attendance_trend_returns_null_when_divisor_is_zero(): void
    {
        $this->markTestSkipped('Phase 2 not yet implemented — TrendService class not available.');
        CampusSnapshotFactory::createSnapshotSet($this->campus1->id, '2026-07-01', [
            'monthly_sessions' => 0,
            'monthly_attendance' => 0,
        ]);

        $result = app(\App\Services\Dashboard\TrendService::class)
            ->getAttendanceTrend($this->campus1->id, 12);

        $this->assertNull($result['datasets'][0]['data'][0],
            'Attendance should be null when sessions=0.');
    }

    // ─── Edge Cases ───────────────────────────────────────────────────────

    /** @test */
    public function it_excludes_campus_with_no_data_from_datasets(): void
    {
        $this->markTestSkipped('Phase 2 not yet implemented — TrendService class not available.');
        CampusSnapshotFactory::createSnapshotSet($this->campus1->id, '2026-07-01', ['student_count' => 50]);

        $result = app(\App\Services\Dashboard\TrendService::class)
            ->getStudentTrend(null, 12);

        // Only campus2 is empty — should not appear in datasets
        $campusIds = array_column($result['datasets'], 'campusId');
        $this->assertContains($this->campus1->id, $campusIds);
        $this->assertNotContains($this->campus2->id, $campusIds,
            'Empty campus should be excluded from datasets.');
    }

    /** @test */
    public function it_respects_campus_filter(): void
    {
        $this->markTestSkipped('Phase 2 not yet implemented — TrendService class not available.');
        CampusSnapshotFactory::createSnapshotSet($this->campus1->id, '2026-07-01', ['student_count' => 50]);
        CampusSnapshotFactory::createSnapshotSet($this->campus1->id, '2026-06-01', ['student_count' => 45]);
        CampusSnapshotFactory::createSnapshotSet($this->campus2->id, '2026-07-01', ['student_count' => 30]);
        CampusSnapshotFactory::createSnapshotSet($this->campus2->id, '2026-06-01', ['student_count' => 28]);

        $result = app(\App\Services\Dashboard\TrendService::class)
            ->getStudentTrend($this->campus2->id, 12);

        $this->assertCount(1, $result['datasets']); // only campus2
        $this->assertSame($this->campus2->id, $result['datasets'][0]['campusId']);
    }

    // ─── Helpers ──────────────────────────────────────────────────────────

    private function seedMultiMonthTrend(int $campusId, string $metric, int $months): void
    {
        for ($i = $months - 1; $i >= 0; $i--) {
            $date = now()->startOfMonth()->subMonths($i)->toDateString();
            $value = 40 + ($months - $i) * 2;
            CampusSnapshot::create([
                'campus_id' => $campusId,
                'snapshot_date' => $date,
                'metric_key' => $metric,
                'metric_value' => $value,
            ]);
        }
    }
}
