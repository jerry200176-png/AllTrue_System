<?php

namespace App\Services\Scheduling;

/**
 * ADR-006 Phase 3 — ReleaseSessionCoverage planner (dry-run).
 * held → released only; never touches consumption ledger.
 */
final class ReleaseSessionCoveragePlanner
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
     *   current_state:string
     * }>  $rows
     * @return array<string,mixed>
     */
    public function plan(array $rows): array
    {
        $out = [];
        $released = 0;
        $blocked = 0;

        foreach ($rows as $r) {
            $from = (string) $r['current_state'];
            $assert = $this->machine->assertTransition($from, CoverageStates::RELEASED);
            $item = [
                'student_class_id' => (int) $r['student_class_id'],
                'date' => (string) $r['date'],
                'start_hm' => (string) $r['start_hm'],
                'class_session_id' => $r['class_session_id'] ?? null,
                'from_state' => $from,
            ];
            if ($assert['ok'] && $from !== CoverageStates::RELEASED) {
                $item['to_state'] = CoverageStates::RELEASED;
                $item['action'] = 'release';
                $item['reason_code'] = 'OK_RELEASE';
                $released++;
            } elseif ($from === CoverageStates::RELEASED) {
                $item['to_state'] = CoverageStates::RELEASED;
                $item['action'] = 'noop';
                $item['reason_code'] = CommitmentReasonCodes::OK_EXISTS;
            } else {
                $item['to_state'] = $from;
                $item['action'] = 'block';
                $item['reason_code'] = $assert['reason'] ?? 'INVALID_COVERAGE_TRANSITION';
                $blocked++;
            }
            $out[] = $item;
        }

        return [
            'meta' => [
                'command' => 'ReleaseSessionCoverage',
                'mode' => 'dry_run',
                'read_only' => true,
                'writes' => false,
                'will_deduct' => false,
                'rule_version' => CommitmentReasonCodes::RULE_VERSION,
            ],
            'summary' => [
                'released_planned' => $released,
                'blocked' => $blocked,
            ],
            'rows' => $out,
        ];
    }
}
