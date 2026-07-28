<?php

namespace App\Services\Scheduling;

/**
 * ADR-006 Phase 3 — pool coverage states (marking only; not consumption).
 * Consumption remains SessionDeductionService / PackageDeductionService territory.
 */
final class CoverageStates
{
    public const NONE = 'none';
    public const HELD = 'held';
    public const CONSUMED = 'consumed';
    public const RELEASED = 'released';

    /** @return list<string> */
    public static function all(): array
    {
        return [self::NONE, self::HELD, self::CONSUMED, self::RELEASED];
    }
}
