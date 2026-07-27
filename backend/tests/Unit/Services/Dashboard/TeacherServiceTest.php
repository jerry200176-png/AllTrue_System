<?php

namespace Tests\Unit\Services\Dashboard;

use App\Models\Campus;
use App\Models\ClassSession;
use App\Models\Teacher;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * TeacherService — per-teacher monthly hours report (chart + table).
 *
 * Tests the documented contract:
 *   - getTeacherHours($campusId, $month) returns chartData + tableData
 *   - chartData: labels (campus names), datasets (total hours per campus)
 *   - tableData: per-teacher rows with hours, studentCount, avgHoursPerStudent
 *   - Data sourced directly from ClassSession (not campus_snapshots)
 *
 * ⚠️ Depends on Phase 2 implementation (TeacherService class).
 *   Tests marked skipped until class exists. Unskip when Phase 2 is complete.
 */
class TeacherServiceTest extends TestCase
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

    /** @test */
    public function it_returns_chart_and_table_data(): void
    {
        $this->markTestSkipped('Phase 2 not yet implemented — TeacherService class not available.');
        $this->seedTeacherHours($this->campus1->id, '2026-07', [
            ['teacherId' => 1, 'name' => '王老師', 'hours' => 42, 'students' => 8],
            ['teacherId' => 2, 'name' => '李老師', 'hours' => 36, 'students' => 6],
        ]);

        $result = app(\App\Services\Dashboard\TeacherService::class)
            ->getTeacherHours($this->campus1->id, '2026-07');

        $this->assertArrayHasKey('month', $result);
        $this->assertSame('2026-07', $result['month']);
        $this->assertArrayHasKey('chartData', $result);
        $this->assertArrayHasKey('tableData', $result);
    }

    /** @test */
    public function chart_data_contains_campus_level_aggregation(): void
    {
        $this->markTestSkipped('Phase 2 not yet implemented — TeacherService class not available.');
        $this->seedTeacherHours($this->campus1->id, '2026-07', [
            ['teacherId' => 1, 'name' => '王老師', 'hours' => 42, 'students' => 8],
            ['teacherId' => 2, 'name' => '李老師', 'hours' => 36, 'students' => 6],
        ]);

        $result = app(\App\Services\Dashboard\TeacherService::class)
            ->getTeacherHours(null, '2026-07');

        $this->assertArrayHasKey('labels', $result['chartData']);
        $this->assertArrayHasKey('datasets', $result['chartData']);
        $this->assertContains('大安', $result['chartData']['labels']);
        $this->assertCount(1, $result['chartData']['datasets']);

        $dataset = $result['chartData']['datasets'][0];
        $this->assertSame('總授課時數', $dataset['label']);
        $this->assertEquals(78, $dataset['data'][0]); // 42 + 36
        $this->assertArrayHasKey('color', $dataset);
    }

    /** @test */
    public function table_data_contains_teacher_level_rows(): void
    {
        $this->markTestSkipped('Phase 2 not yet implemented — TeacherService class not available.');
        $this->seedTeacherHours($this->campus1->id, '2026-07', [
            ['teacherId' => 1, 'name' => '王老師', 'hours' => 42, 'students' => 8],
            ['teacherId' => 2, 'name' => '李老師', 'hours' => 36, 'students' => 6],
        ]);

        $result = app(\App\Services\Dashboard\TeacherService::class)
            ->getTeacherHours($this->campus1->id, '2026-07');

        $this->assertCount(2, $result['tableData']);

        $row = $result['tableData'][0];
        $this->assertArrayHasKey('teacherName', $row);
        $this->assertArrayHasKey('campusName', $row);
        $this->assertArrayHasKey('hours', $row);
        $this->assertArrayHasKey('studentCount', $row);
        $this->assertArrayHasKey('avgHoursPerStudent', $row);
        $this->assertEqualsWithDelta(5.25, $row['avgHoursPerStudent'], 0.01);
    }

    /** @test */
    public function it_filters_by_campus(): void
    {
        $this->markTestSkipped('Phase 2 not yet implemented — TeacherService class not available.');
        $this->seedTeacherHours($this->campus1->id, '2026-07', [
            ['teacherId' => 1, 'name' => '王老師', 'hours' => 42, 'students' => 8],
        ]);
        $this->seedTeacherHours($this->campus2->id, '2026-07', [
            ['teacherId' => 2, 'name' => '陳老師', 'hours' => 30, 'students' => 5],
        ]);

        $result = app(\App\Services\Dashboard\TeacherService::class)
            ->getTeacherHours($this->campus2->id, '2026-07');

        $this->assertCount(1, $result['tableData']);
        $this->assertSame('陳老師', $result['tableData'][0]['teacherName']);
        $this->assertEquals(30, $result['tableData'][0]['hours']);
    }

    /** @test */
    public function it_returns_empty_data_when_no_teachers(): void
    {
        $this->markTestSkipped('Phase 2 not yet implemented — TeacherService class not available.');
        $result = app(\App\Services\Dashboard\TeacherService::class)
            ->getTeacherHours($this->campus1->id, '2026-07');

        $this->assertEmpty($result['tableData']);
        $this->assertEmpty($result['chartData']['labels']);
        $this->assertNotEmpty($result['month']);
    }

    /** @test */
    public function it_defaults_to_current_month(): void
    {
        $this->markTestSkipped('Phase 2 not yet implemented — TeacherService class not available.');
        $this->seedTeacherHours($this->campus1->id, '2026-07', [
            ['teacherId' => 1, 'name' => '王老師', 'hours' => 42, 'students' => 8],
        ]);

        $result = app(\App\Services\Dashboard\TeacherService::class)
            ->getTeacherHours($this->campus1->id, null);

        $this->assertSame('2026-07', $result['month']);
        $this->assertCount(1, $result['tableData']);
    }

    /** @test */
    public function avg_hours_per_student_is_zero_when_student_count_is_zero(): void
    {
        $this->markTestSkipped('Phase 2 not yet implemented — TeacherService class not available.');
        $this->seedTeacherHours($this->campus1->id, '2026-07', [
            ['teacherId' => 1, 'name' => '王老師', 'hours' => 10, 'students' => 0],
        ]);

        $result = app(\App\Services\Dashboard\TeacherService::class)
            ->getTeacherHours($this->campus1->id, '2026-07');

        $this->assertEquals(0, $result['tableData'][0]['avgHoursPerStudent']);
    }

    // ─── Helpers ──────────────────────────────────────────────────────────

    private function seedTeacherHours(int $campusId, string $month, array $teachers): void
    {
        foreach ($teachers as $t) {
            // Create teacher profile
            Teacher::create([
                'id' => $t['teacherId'],
                'CampusID' => $campusId,
                'T_Name' => $t['name'],
                'Enable' => 1,
            ]);

            // Create class sessions for this teacher's hours
            // Each "hour" is a session with Duration = 1 hour
            for ($i = 0; $i < $t['hours']; $i++) {
                ClassSession::create([
                    'StudentClassID' => 1,
                    'TeacherID' => $t['teacherId'],
                    'SessionDate' => $month . '-01',
                    'Duration' => 1.0,
                    'Status' => 'attended',
                    'CampusID' => $campusId,
                ]);
            }
        }
    }
}
