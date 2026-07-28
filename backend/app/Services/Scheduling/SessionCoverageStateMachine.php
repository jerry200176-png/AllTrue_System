<?php

namespace App\Services\Scheduling;

/**
 * ADR-006 Phase 3 — coverage state machine (pure; no DB).
 * Marks coverage only; never deducts entitlement / ledger.
 */
final class SessionCoverageStateMachine
{
    /**
     * Allowed transitions (from => list of to).
     *
     * @var array<string, list<string>>
     */
    private const ALLOWED = [
        CoverageStates::NONE => [CoverageStates::HELD],
        CoverageStates::HELD => [CoverageStates::CONSUMED, CoverageStates::RELEASED],
        CoverageStates::RELEASED => [CoverageStates::HELD],
        CoverageStates::CONSUMED => [], // terminal for v1; repair is owner-gated
    ];

    public function canTransition(string $from, string $to): bool
    {
        if (!in_array($from, CoverageStates::all(), true) || !in_array($to, CoverageStates::all(), true)) {
            return false;
        }
        if ($from === $to) {
            return true; // idempotent no-op
        }

        return in_array($to, self::ALLOWED[$from] ?? [], true);
    }

    /**
     * @return array{ok:bool,from:string,to:string,reason:?string}
     */
    public function assertTransition(string $from, string $to): array
    {
        if ($this->canTransition($from, $to)) {
            return ['ok' => true, 'from' => $from, 'to' => $to, 'reason' => null];
        }

        return [
            'ok' => false,
            'from' => $from,
            'to' => $to,
            'reason' => 'INVALID_COVERAGE_TRANSITION',
        ];
    }

    /** @return list<string> */
    public function allowedTargets(string $from): array
    {
        return self::ALLOWED[$from] ?? [];
    }
}
