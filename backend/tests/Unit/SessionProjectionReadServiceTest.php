<?php

namespace Tests\Unit;

use App\Services\SessionProjectionReadService;
use Tests\TestCase;

/**
 * Pins the in-app #235 fix: projected (not-yet-materialized) slots must carry
 * branch_id. Before this fix, projectedSlot() never included it, so the
 * frontend fell back to `s.branchId || 0` and rendered "Branch #0" for any
 * upcoming session in the teacher's weekly view.
 */
class SessionProjectionReadServiceTest extends TestCase
{
    public function test_projected_slot_includes_branch_id(): void
    {
        $service = new SessionProjectionReadService();

        $slot = $service->projectedSlot(101, '2026-09-01', '18:00', '20:00', 16);

        $this->assertSame(16, $slot['branch_id']);
        $this->assertSame('projected', $slot['kind']);
    }

    public function test_projected_slot_branch_id_defaults_to_zero_when_unresolved(): void
    {
        $service = new SessionProjectionReadService();

        $slot = $service->projectedSlot(101, '2026-09-01', '18:00', '20:00');

        $this->assertSame(0, $slot['branch_id']);
    }
}
