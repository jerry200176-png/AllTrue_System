<?php

namespace Tests\Unit\Scheduling;

use App\Services\Scheduling\AllocateSessionCoveragePlanner;
use App\Services\Scheduling\CommitmentReasonCodes;
use App\Services\Scheduling\CoverageStates;
use App\Services\Scheduling\ReleaseSessionCoveragePlanner;
use App\Services\Scheduling\SessionCoverageStateMachine;
use PHPUnit\Framework\TestCase;

/** ADR-006 Phase 3 coverage domain — pure unit tests (no DB). */
class SessionCoverageDomainTest extends TestCase
{
    public function test_state_machine_allows_hold_consume_release(): void
    {
        $m = new SessionCoverageStateMachine();
        $this->assertTrue($m->canTransition(CoverageStates::NONE, CoverageStates::HELD));
        $this->assertTrue($m->canTransition(CoverageStates::HELD, CoverageStates::CONSUMED));
        $this->assertTrue($m->canTransition(CoverageStates::HELD, CoverageStates::RELEASED));
        $this->assertTrue($m->canTransition(CoverageStates::RELEASED, CoverageStates::HELD));
        $this->assertFalse($m->canTransition(CoverageStates::CONSUMED, CoverageStates::HELD));
        $this->assertFalse($m->canTransition(CoverageStates::NONE, CoverageStates::CONSUMED));
    }

    public function test_allocator_orders_deterministically_and_marks_uncovered(): void
    {
        $plan = (new AllocateSessionCoveragePlanner())->plan(10, 2, [
            ['student_class_id' => 2, 'date' => '2026-07-20', 'start_hm' => '16:00'],
            ['student_class_id' => 1, 'date' => '2026-07-13', 'start_hm' => '17:00'],
            ['student_class_id' => 1, 'date' => '2026-07-13', 'start_hm' => '16:00'],
            ['student_class_id' => 3, 'date' => '2026-07-27', 'start_hm' => '16:00'],
        ]);

        $this->assertTrue($plan['meta']['read_only']);
        $this->assertFalse($plan['meta']['will_deduct']);
        $this->assertSame(2, $plan['pool']['held_planned']);
        $this->assertSame(2, $plan['pool']['uncovered_planned']);
        $this->assertTrue($plan['pool']['ensure_would_block']);
        $this->assertSame(CommitmentReasonCodes::BLOCK_POOL_SHORTAGE, $plan['pool']['ensure_block_reason']);

        $keys = array_map(
            static fn ($r) => $r['date'].'|'.$r['start_hm'].'|'.$r['student_class_id'],
            $plan['allocations']
        );
        $this->assertSame([
            '2026-07-13|16:00|1',
            '2026-07-13|17:00|1',
            '2026-07-20|16:00|2',
            '2026-07-27|16:00|3',
        ], $keys);
        $this->assertSame(CoverageStates::HELD, $plan['allocations'][0]['to_state']);
        $this->assertSame(CoverageStates::HELD, $plan['allocations'][1]['to_state']);
        $this->assertSame(CommitmentReasonCodes::OK_PREVIEW_UNCOVERED, $plan['allocations'][2]['reason_code']);
    }

    public function test_release_blocks_consumed(): void
    {
        $plan = (new ReleaseSessionCoveragePlanner())->plan([
            [
                'student_class_id' => 1, 'date' => '2026-07-13', 'start_hm' => '16:00',
                'current_state' => CoverageStates::HELD,
            ],
            [
                'student_class_id' => 1, 'date' => '2026-07-20', 'start_hm' => '16:00',
                'current_state' => CoverageStates::CONSUMED,
            ],
        ]);
        $this->assertSame(1, $plan['summary']['released_planned']);
        $this->assertSame(1, $plan['summary']['blocked']);
        $this->assertSame('release', $plan['rows'][0]['action']);
        $this->assertSame('block', $plan['rows'][1]['action']);
    }
}
