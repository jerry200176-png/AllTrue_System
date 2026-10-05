<?php

namespace Tests\Feature;

use App\Models\ClassSession;
use App\Services\Scheduling\ContractSessionSchedule;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Characterization of the quota helpers moved out of StudentClassController (ADR-003 / #966).
 */
class ContractSessionScheduleQuotaTest extends TestCase
{
    use RefreshDatabase;

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function make(string $date, string $status): ClassSession
    {
        return ClassSession::create([
            'StudentClassID' => 801, 'SessionDate' => $date, 'StartTime' => '13:00:00', 'EndTime' => '14:00:00', 'Status' => $status,
        ]);
    }

    public function test_cancel_excess_cancels_only_scheduled_rows_beyond_count_ignoring_non_quota_rows(): void
    {
        Carbon::setTestNow('2026-09-01 08:00:00');
        $a = $this->make('2026-09-02', 'attended');
        $leave = $this->make('2026-09-03', 'leave');
        $b = $this->make('2026-09-09', 'scheduled');
        $c = $this->make('2026-09-16', 'scheduled');

        app(ContractSessionSchedule::class)->cancelExcessScheduledSessions(801, 2);

        $this->assertSame('attended', $a->fresh()->Status);
        $this->assertSame('leave', $leave->fresh()->Status);
        $this->assertSame('scheduled', $b->fresh()->Status);
        $this->assertSame('cancelled', $c->fresh()->Status);
    }

    public function test_beyond_count_preflight_is_read_only_and_future_scheduled_only(): void
    {
        Carbon::setTestNow('2026-09-10 08:00:00');
        $this->make('2026-09-02', 'scheduled');
        $future = $this->make('2026-09-16', 'scheduled');

        $out = app(ContractSessionSchedule::class)->scheduledSessionsBeyondCount(801, 1);

        $this->assertSame([[
            'session_id' => (int) $future->id, 'session_date' => '2026-09-16',
            'start_time' => '13:00', 'end_time' => '14:00', 'status' => 'scheduled',
        ]], $out);
        $this->assertSame('scheduled', $future->fresh()->Status);
    }
}
