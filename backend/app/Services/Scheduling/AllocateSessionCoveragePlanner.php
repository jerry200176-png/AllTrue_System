<?php

namespace App\Services\Scheduling;

/**
 * ADR-006 Phase 3 — AllocateSessionCoverage planner (dry-run / default-off).
 *
 * Deterministic pool-level allocation: order candidates by (date, start_hm, student_class_id),
 * hold until pool budget exhausted; remainder uncovered. Never writes; never deducts.
 */
final class AllocateSessionCoveragePlanner
{
    public function __construct(
        private ?SessionCoverageStateMachine $machine = null,
    ) {
        $this->machine = $machine ?? new SessionCoverageStateMachine();
    }

    /**
     * @param  list<array{
     *   student_class_id:int,
     *   date:string,
     *   start_hm:string,
     *   class_session_id?:int|null,
     *   current_state?:string
     * }>  $candidates
     * @return array<string,mixed>
     */
    public function plan(int $packageId, int $poolRemaining, array $candidates, ?int $campusId = null): array
    {
        $sorted = $candidates;
        usort($sorted, static function (array $a, array $b): int {
            return [$a['date'], $a['start_hm'], (int) $a['student_class_id']]
                <=> [$b['date'], $b['start_hm'], (int) $b['student_class_id']];
        });

        $budget = max(0, $poolRemaining);
        $allocations = [];
        $held = 0;
        $uncovered = 0;
        $skipped = 0;

        foreach ($sorted as $c) {
            $from = $c['current_state'] ?? CoverageStates::NONE;
            $row = [
                'student_class_id' => (int) $c['student_class_id'],
                'date' => (string) $c['date'],
                'start_hm' => (string) $c['start_hm'],
                'class_session_id' => $c['class_session_id'] ?? null,
                'from_state' => $from,
            ];

            if ($from === CoverageStates::HELD || $from === CoverageStates::CONSUMED) {
                $row['to_state'] = $from;
                $row['action'] = 'noop_already_covered';
                $row['reason_code'] = CommitmentReasonCodes::OK_EXISTS;
                $allocations[] = $row;
                $skipped++;
                continue;
            }

            if ($budget > 0) {
                $assert = $this->machine->assertTransition($from, CoverageStates::HELD);
                if (!$assert['ok']) {
                    $row['to_state'] = $from;
                    $row['action'] = 'block';
                    $row['reason_code'] = $assert['reason'];
                    $allocations[] = $row;
                    $skipped++;
                    continue;
                }
                $budget--;
                $held++;
                $row['to_state'] = CoverageStates::HELD;
                $row['action'] = 'allocate_hold';
                $row['reason_code'] = CommitmentReasonCodes::OK_PLAN;
            } else {
                $row['to_state'] = CoverageStates::NONE;
                $row['action'] = 'leave_uncovered';
                $row['reason_code'] = CommitmentReasonCodes::OK_PREVIEW_UNCOVERED;
                $uncovered++;
            }
            $allocations[] = $row;
        }

        return [
            'meta' => [
                'command' => 'AllocateSessionCoverage',
                'mode' => 'dry_run',
                'read_only' => true,
                'writes' => false,
                'will_mutate_coverage' => false,
                'will_deduct' => false,
                'rule_version' => CommitmentReasonCodes::RULE_VERSION,
                'ordering' => 'date,start_hm,student_class_id',
            ],
            'pool' => [
                'package_id' => $packageId,
                'campus_id' => $campusId,
                'pool_remaining_input' => max(0, $poolRemaining),
                'held_planned' => $held,
                'uncovered_planned' => $uncovered,
                'noop_or_blocked' => $skipped,
                'budget_remaining_after' => $budget,
                'ensure_would_block' => $uncovered > 0,
                'ensure_block_reason' => $uncovered > 0
                    ? CommitmentReasonCodes::BLOCK_POOL_SHORTAGE
                    : null,
            ],
            'allocations' => $allocations,
        ];
    }
}
