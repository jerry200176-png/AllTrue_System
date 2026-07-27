<?php

namespace Tests\Unit\Jobs;

use App\Models\Campus;
use App\Models\CampusSnapshot;
use App\Models\ClassSession;
use App\Models\Payment;
use App\Models\Student;
use App\Models\Teacher;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * GenerateCampusSnapshots Job — monthly KPI aggregation into campus_snapshots.
 *
 * Tests the documented aggregation logic from the technical spec:
 *   student_count     — COUNT(Student) WHERE Stop=0
 *   active_teachers   — COUNT(Teacher) WHERE Enable=1
 *   monthly_revenue   — SUM(Payment.Amount) for the month
 *   monthly_sessions  — COUNT(ClassSession) for the month
 *   monthly_attendance— COUNT(ClassSession) WHERE Status='attended'
 *   monthly_teaching_hours — SUM(ClassSession.Duration)
 *
 * ⚠️ Depends on Phase 1 implementation (CampusSnapshot model, GenerateCampusSnapshots job).
 *   Tests marked skipped until job class exists. Unskip when Phase 1 is complete.
 */
class GenerateCampusSnapshotsTest extends TestCase
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
    public function it_generates_all_six_metrics_for_a_campus(): void
    {
        $this->markTestSkipped('Phase 1 not yet implemented — GenerateCampusSnapshots job class not available.');
        // Arrange: seed June 2026 data
        $this->seedJuneData($this->campus->id);

        // Act
        $job = app(\App\Jobs\GenerateCampusSnapshots::class);
        $job->handle();

        // Assert: 6 metric rows for campus + snapshot_date = 2026-06-01
        $snapshots = CampusSnapshot::where('campus_id', $this->campus->id)
            ->where('snapshot_date', '2026-06-01')
            ->get()
            ->keyBy('metric_key');

        $this->assertCount(6, $snapshots);
        $this->assertEquals(52, (float) $snapshots['student_count']->metric_value);
        $this->assertEquals(8, (float) $snapshots['active_teachers']->metric_value);
        $this->assertEqualsWithDelta(185000.0, (float) $snapshots['monthly_revenue']->metric_value, 0.5);
        $this->assertEquals(95, (float) $snapshots['monthly_sessions']->metric_value);
        $this->assertEquals(85, (float) $snapshots['monthly_attendance']->metric_value);
        $this->assertEqualsWithDelta(190.5, (float) $snapshots['monthly_teaching_hours']->metric_value, 0.5);
    }

    /** @test */
    public function it_upserts_instead_of_duplicating_when_re_run(): void
    {
        $this->markTestSkipped('Phase 1 not yet implemented — GenerateCampusSnapshots job class not available.');
        $this->seedJuneData($this->campus->id);

        $job = app(\App\Jobs\GenerateCampusSnapshots::class);
        $job->handle();
        $firstCount = CampusSnapshot::count();

        // Re-run
        $job->handle();

        $this->assertSame($firstCount, CampusSnapshot::count(),
            'Re-running should UPSERT, not create duplicates.');
    }

    /** @test */
    public function it_handles_zero_data_for_a_new_campus(): void
    {
        $this->markTestSkipped('Phase 1 not yet implemented — GenerateCampusSnapshots job class not available.');
        // New campus with no student/payment/session data yet
        $newCampus = Campus::factory()->create(['name' => '新竹', 'code' => 'hsinchu']);

        $job = app(\App\Jobs\GenerateCampusSnapshots::class);
        $job->handle();

        $snapshots = CampusSnapshot::where('campus_id', $newCampus->id)
            ->where('snapshot_date', now()->startOfMonth()->subMonth()->toDateString())
            ->get();

        $this->assertCount(6, $snapshots);
        foreach ($snapshots as $snapshot) {
            $this->assertEquals(0, (float) $snapshot->metric_value,
                "Metric {$snapshot->metric_key} should be 0 for empty campus.");
        }
    }

    /** @test */
    public function it_generates_snapshots_for_all_campuses(): void
    {
        $this->markTestSkipped('Phase 1 not yet implemented — GenerateCampusSnapshots job class not available.');
        $campus2 = Campus::factory()->create(['name' => '新店', 'code' => 'xindian']);

        // Only seed campus1, campus2 has nothing
        $this->seedJuneData($this->campus->id);

        $job = app(\App\Jobs\GenerateCampusSnapshots::class);
        $job->handle();

        $this->assertCount(6, CampusSnapshot::where('campus_id', $this->campus->id)->get());
        $this->assertCount(6, CampusSnapshot::where('campus_id', $campus2->id)->get(),
            'All campuses should have snapshots even if data is zero.');
    }

    /** @test */
    public function it_handles_backfill_for_historical_months(): void
    {
        $this->markTestSkipped('Phase 1 not yet implemented — backfill mode not available.');
        // Seed data across 3 months
        $this->seedMultiMonthData($this->campus->id, ['2026-04', '2026-05', '2026-06']);

        $job = app(\App\Jobs\GenerateCampusSnapshots::class);
        // Backfill via artisan command or method parameter per spec
        // $job->handle(true); // hypothetical backfill mode

        // Assert: 3 months × 6 metrics = 18 rows
        // $this->assertCount(18, CampusSnapshot::where('campus_id', $this->campus->id)->get());
        $this->assertTrue(true); // placeholder
    }

    // ── Helpers ────────────────────────────────────────────────────────────

    private function seedJuneData(int $campusId): void
    {
        // 52 active students (Stop=0), 3 stopped students (should be excluded)
        for ($i = 1; $i <= 52; $i++) {
            Student::create([
                'name' => "Active Student {$i}",
                'CampusID' => $campusId,
                'ClassID' => 1,
                'Stop' => 0,
                'enable' => 1,
            ]);
        }
        for ($i = 1; $i <= 3; $i++) {
            Student::create([
                'name' => "Stopped Student {$i}",
                'CampusID' => $campusId,
                'ClassID' => 1,
                'Stop' => 1,
                'enable' => 1,
            ]);
        }

        // 8 active teachers
        for ($i = 1; $i <= 8; $i++) {
            Teacher::create([
                'CampusID' => $campusId,
                'T_Name' => "Teacher {$i}",
                'Enable' => 1,
            ]);
        }

        // $185,000 in payments for June
        Payment::create(['InvoiceID' => 1, 'Amount' => 100000, 'PaidAt' => '2026-06-05']);
        Payment::create(['InvoiceID' => 2, 'Amount' => 50000, 'PaidAt' => '2026-06-12']);
        Payment::create(['InvoiceID' => 3, 'Amount' => 35000, 'PaidAt' => '2026-06-20']);

        // 95 sessions: 85 attended, 10 other statuses
        for ($i = 1; $i <= 85; $i++) {
            ClassSession::create([
                'StudentClassID' => $i,
                'SessionDate' => "2026-06-{$i}",
                'StartTime' => '10:00',
                'EndTime' => '11:30',
                'Status' => 'attended',
                'Duration' => 1.5,
                'CampusID' => $campusId,
            ]);
        }
        for ($i = 86; $i <= 90; $i++) {
            ClassSession::create([
                'StudentClassID' => $i,
                'SessionDate' => "2026-06-{$i}",
                'StartTime' => '10:00',
                'EndTime' => '11:30',
                'Status' => 'absent',
                'Duration' => 1.5,
                'CampusID' => $campusId,
            ]);
        }
        for ($i = 91; $i <= 95; $i++) {
            ClassSession::create([
                'StudentClassID' => $i,
                'SessionDate' => "2026-06-{$i}",
                'StartTime' => '10:00',
                'EndTime' => '11:30',
                'Status' => 'cancelled',
                'Duration' => 1.5,
                'CampusID' => $campusId,
            ]);
        }
    }

    private function seedMultiMonthData(int $campusId, array $months): void
    {
        foreach ($months as $month) {
            $start = Carbon::parse($month . '-01');
            $end = $start->copy()->endOfMonth();

            // 45-55 active students each month
            $count = 45 + array_search($month, $months) * 5;
            Student::create([
                'name' => "Student {$month}",
                'CampusID' => $campusId,
                'ClassID' => 1,
                'Stop' => 0,
                'enable' => 1,
                'created_at' => $start->toDateTimeString(),
            ]);

            // 1 payment per month
            Payment::create([
                'InvoiceID' => array_search($month, $months) + 1,
                'Amount' => 150000 + array_search($month, $months) * 10000,
                'PaidAt' => $start->addDays(15)->toDateString(),
            ]);

            ClassSession::create([
                'StudentClassID' => 100 + array_search($month, $months),
                'SessionDate' => $start->subDays(15)->toDateString(),
                'StartTime' => '10:00',
                'EndTime' => '11:30',
                'Status' => 'attended',
                'Duration' => 1.5,
                'CampusID' => $campusId,
            ]);
        }
    }
}
