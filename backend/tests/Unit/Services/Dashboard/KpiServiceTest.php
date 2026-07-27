<?php

namespace Tests\Unit\Services\Dashboard;

use App\Models\Campus;
use App\Models\CampusSnapshot;
use Carbon\Carbon;
use Database\Factories\CampusSnapshotFactory;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * KpiService — KPI summary cards with current/previous/change/trend.
 *
 * Tests the documented contract:
 *   - getSummary($campusId, $period) returns 4 KPI cards
 *   - Each card has: key, label, current, previous, change, changePercent, unit, trend
 *   - Previous month comparison with change/percent calculation
 *   - Trend direction: up/down/flat
 *   - Single-month edge case → null previous
 *
 * ⚠️ Depends on Phase 2 implementation (KpiService class).
 *   Tests marked skipped until class exists. Unskip when Phase 2 is complete.
 */
class KpiServiceTest extends TestCase
{
    use RefreshDatabase;

    private Campus $campus;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow(Carbon::parse('2026-07-15 12:00:00', 'Asia/Taipei'));
        $this->campus = Campus::factory()->create(['name' => '大安', 'code' => 'daan']);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    /** @test */
    public function it_returns_four_kpi_cards_with_correct_structure(): void
    {
        $this->markTestSkipped('Phase 2 not yet implemented — KpiService class not available.');
        // Arrange: seed current and previous month data
        CampusSnapshotFactory::createSnapshotSet($this->campus->id, '2026-07-01', [
            'student_count' => 62,
            'active_teachers' => 12,
            'monthly_revenue' => 240000,
            'monthly_sessions' => 420,
            'monthly_attendance' => 385,
            'monthly_teaching_hours' => 560,
        ]);
        CampusSnapshotFactory::createSnapshotSet($this->campus->id, '2026-06-01', [
            'student_count' => 55,
            'active_teachers' => 11,
            'monthly_revenue' => 215000,
            'monthly_sessions' => 395,
            'monthly_attendance' => 372,
            'monthly_teaching_hours' => 530,
        ]);

        // Act
        $result = app(\App\Services\Dashboard\KpiService::class)
            ->getSummary($this->campus->id, 'month');

        // Assert
        $this->assertArrayHasKey('period', $result);
        $this->assertSame('2026-07', $result['period']);
        $this->assertArrayHasKey('cards', $result);
        $this->assertCount(4, $result['cards']);

        $cardKeys = array_column($result['cards'], 'key');
        $this->assertContains('student_count', $cardKeys);
        $this->assertContains('monthly_revenue', $cardKeys);
        $this->assertContains('attendance_rate', $cardKeys);
        $this->assertContains('teaching_hours', $cardKeys);
    }

    /** @test */
    public function it_calculates_change_and_percentage_correctly(): void
    {
        $this->markTestSkipped('Phase 2 not yet implemented — KpiService class not available.');
        CampusSnapshotFactory::createSnapshotSet($this->campus->id, '2026-07-01', [
            'student_count' => 60,
            'monthly_revenue' => 300000,
            'monthly_attendance' => 360,
            'monthly_teaching_hours' => 600,
        ]);
        CampusSnapshotFactory::createSnapshotSet($this->campus->id, '2026-06-01', [
            'student_count' => 50,
            'monthly_revenue' => 250000,
            'monthly_attendance' => 340,
            'monthly_teaching_hours' => 550,
        ]);

        $result = app(\App\Services\Dashboard\KpiService::class)
            ->getSummary($this->campus->id, 'month');

        $cards = collect($result['cards'])->keyBy('key');

        // student_count: 60 - 50 = 10, (10/50)*100 = 20%
        $studentCard = $cards['student_count'];
        $this->assertEquals(60, $studentCard['current']);
        $this->assertEquals(50, $studentCard['previous']);
        $this->assertEquals(10, $studentCard['change']);
        $this->assertEquals(20.0, $studentCard['changePercent']);
        $this->assertEquals('up', $studentCard['trend']);

        // monthly_revenue: 300000 - 250000 = 50000, (50000/250000)*100 = 20%
        $revenueCard = $cards['monthly_revenue'];
        $this->assertEquals(300000, $revenueCard['current']);
        $this->assertEquals(250000, $revenueCard['previous']);
        $this->assertEquals(50000, $revenueCard['change']);
        $this->assertEquals(20.0, $revenueCard['changePercent']);
        $this->assertEquals('up', $revenueCard['trend']);

        // teaching_hours: 600 - 550 = 50
        $hoursCard = $cards['teaching_hours'];
        $this->assertEquals(600, $hoursCard['current']);
        $this->assertEquals(550, $hoursCard['previous']);
        $this->assertEquals(50, $hoursCard['change']);
        $this->assertEquals('up', $hoursCard['trend']);
    }

    /** @test */
    public function it_shows_down_trend_when_current_is_lower(): void
    {
        $this->markTestSkipped('Phase 2 not yet implemented — KpiService class not available.');
        CampusSnapshotFactory::createSnapshotSet($this->campus->id, '2026-07-01', [
            'student_count' => 45,
            'monthly_revenue' => 180000,
            'monthly_attendance' => 300,
            'monthly_teaching_hours' => 480,
        ]);
        CampusSnapshotFactory::createSnapshotSet($this->campus->id, '2026-06-01', [
            'student_count' => 55,
            'monthly_revenue' => 220000,
            'monthly_attendance' => 370,
            'monthly_teaching_hours' => 530,
        ]);

        $result = app(\App\Services\Dashboard\KpiService::class)
            ->getSummary($this->campus->id, 'month');

        $cards = collect($result['cards'])->keyBy('key');

        $this->assertEquals(45, $cards['student_count']['current']);
        $this->assertEquals(55, $cards['student_count']['previous']);
        $this->assertEquals(-10, $cards['student_count']['change']);
        $this->assertEquals(-18.2, $cards['student_count']['changePercent']);
        $this->assertEquals('down', $cards['student_count']['trend']);
    }

    /** @test */
    public function it_handles_flat_trend_when_no_change(): void
    {
        $this->markTestSkipped('Phase 2 not yet implemented — KpiService class not available.');
        CampusSnapshotFactory::createSnapshotSet($this->campus->id, '2026-07-01', [
            'student_count' => 50,
        ]);
        CampusSnapshotFactory::createSnapshotSet($this->campus->id, '2026-06-01', [
            'student_count' => 50,
        ]);

        $result = app(\App\Services\Dashboard\KpiService::class)
            ->getSummary($this->campus->id, 'month');

        $card = collect($result['cards'])->firstWhere('key', 'student_count');
        $this->assertEquals(0, $card['change']);
        $this->assertEquals(0.0, $card['changePercent']);
        $this->assertEquals('flat', $card['trend']);
    }

    /** @test */
    public function it_returns_null_previous_when_only_one_month_exists(): void
    {
        $this->markTestSkipped('Phase 2 not yet implemented — KpiService class not available.');
        CampusSnapshotFactory::createSnapshotSet($this->campus->id, '2026-07-01', [
            'student_count' => 50,
            'monthly_revenue' => 200000,
        ]);

        $result = app(\App\Services\Dashboard\KpiService::class)
            ->getSummary($this->campus->id, 'month');

        $cards = collect($result['cards'])->keyBy('key');

        $this->assertEquals(50, $cards['student_count']['current']);
        $this->assertNull($cards['student_count']['previous']);
        $this->assertNull($cards['student_count']['change']);
        $this->assertNull($cards['student_count']['changePercent']);
        $this->assertEquals('flat', $cards['student_count']['trend']);
    }

    /** @test */
    public function it_filters_by_campus(): void
    {
        $this->markTestSkipped('Phase 2 not yet implemented — KpiService class not available.');
        $campus2 = Campus::factory()->create(['name' => '新店', 'code' => 'xindian']);

        CampusSnapshotFactory::createSnapshotSet($this->campus->id, '2026-07-01', [
            'student_count' => 60,
        ]);
        CampusSnapshotFactory::createSnapshotSet($this->campus->id, '2026-06-01', [
            'student_count' => 50,
        ]);
        CampusSnapshotFactory::createSnapshotSet($campus2->id, '2026-07-01', [
            'student_count' => 30,
        ]);

        $result = app(\App\Services\Dashboard\KpiService::class)
            ->getSummary($this->campus->id, 'month');

        $card = collect($result['cards'])->firstWhere('key', 'student_count');
        $this->assertEquals(60, $card['current']);
    }

    /** @test */
    public function it_includes_attendance_rate_calculated_from_sessions(): void
    {
        $this->markTestSkipped('Phase 2 not yet implemented — KpiService class not available.');
        // attendance_rate = monthly_attendance / monthly_sessions * 100
        // 360 / 400 = 90%
        CampusSnapshotFactory::createSnapshotSet($this->campus->id, '2026-07-01', [
            'monthly_sessions' => 400,
            'monthly_attendance' => 360,
        ]);

        $result = app(\App\Services\Dashboard\KpiService::class)
            ->getSummary($this->campus->id, 'month');

        $card = collect($result['cards'])->firstWhere('key', 'attendance_rate');
        $this->assertEquals(90.0, $card['current']);
        $this->assertEquals('%', $card['unit']);
    }

    /** @test */
    public function it_handles_null_campus_id_as_all(): void
    {
        $this->markTestSkipped('Phase 2 not yet implemented — KpiService class not available.');
        $campus2 = Campus::factory()->create(['name' => '新店', 'code' => 'xindian']);

        CampusSnapshotFactory::createSnapshotSet($this->campus->id, '2026-07-01', [
            'student_count' => 50,
            'monthly_revenue' => 200000,
        ]);
        CampusSnapshotFactory::createSnapshotSet($campus2->id, '2026-07-01', [
            'student_count' => 30,
            'monthly_revenue' => 150000,
        ]);

        // null = all campuses
        $result = app(\App\Services\Dashboard\KpiService::class)
            ->getSummary(null, 'month');

        $card = collect($result['cards'])->firstWhere('key', 'student_count');
        $this->assertEquals(80, $card['current']); // 50 + 30
        $this->assertEquals('人', $card['unit']);
    }
}
