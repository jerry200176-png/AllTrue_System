<?php

namespace App\Services\Scheduling;

use InvalidArgumentException;
use OverflowException;

/**
 * RFC_NONSTANDARD_SESSION_DURATION_BILLING Phase 0B — pure coverage calculator.
 *
 * Answers, for a single course: given a purchased entitlement expressed in
 * "standard lesson" units, and a sequence of scheduled occurrences that may
 * each have a non-standard duration, how many occurrences are fully covered,
 * which occurrence (if any) is the first to only be partially covered, and
 * how many minutes are left uncovered.
 *
 * This is a PREVIEW/DIAGNOSTIC calculator only. It does not read or write
 * any database table, does not call SessionDeductionService, and must never
 * be treated as a second authoritative deduction engine — the only
 * authoritative deduction write path remains
 * SessionDeductionService::recomputeCounters() (see RFC §5, §9). This class
 * exists so that "how much of what was purchased does this schedule use up"
 * can be answered identically at create-time (Phase 0B preview) and in
 * read-only reports (Phase 0A), from the same formula, without duplicating
 * the formula in two places that could drift apart.
 *
 * Distinct from App\Services\Scheduling\CoverageStates /
 * SessionCoverageStateMachine (ADR-006 Phase 3 shared-pool hold/consume/
 * release lifecycle) — that is a different "coverage" concept (pool
 * entitlement custody) and this RFC does not touch it (see D4: CoursePackage
 * is explicitly excluded from this calculator's v1 scope).
 *
 * Invalid input fails fast (throws) rather than silently clamping, per RFC
 * Part C requirement — a caller that passes e.g. a negative duration has a
 * bug that should surface immediately, not produce a plausible-looking
 * wrong number.
 */
final class LessonEntitlementCoverageCalculator
{
    /**
     * Sanity cap on occurrence array size — a DoS/pathological-input guard only,
     * not a business rule (the RFC's "occurrence count upper bound" boundary case).
     * There is deliberately no cap on purchasedStandardUnits/standardLessonMinutes/
     * duration_minutes magnitude beyond genuine int-overflow detection (safeAdd/
     * safeMultiply below) — inventing an arbitrary business-magnitude limit here
     * would itself be an uninvited business rule, which this calculator must not do.
     */
    private const MAX_OCCURRENCES = 20000;

    public const CLASSIFICATION_FULLY_COVERED = 'fully_covered';
    public const CLASSIFICATION_PARTIALLY_COVERED = 'partially_covered';
    public const CLASSIFICATION_FULLY_UNCOVERED = 'fully_uncovered';

    /**
     * @param  int  $purchasedStandardUnits  Entitlement, in standard lesson units. Must be >= 0.
     * @param  int  $standardLessonMinutes  Minutes representing one standard lesson for this
     *                                      course. Must be >= 1. There is deliberately NO fallback
     *                                      default here (e.g. no silent 60 or 120) — callers must
     *                                      resolve `resolved_standard_lesson_minutes` themselves
     *                                      (see RFC §10 A1 snapshot semantics) and pass the
     *                                      resolved value in. A missing/zero value is a caller bug,
     *                                      not a "use the fallback" signal.
     * @param  array<int, array{duration_minutes:int, sequence:int, date?:string, slot?:string}>  $occurrences
     *                                      Scheduled occurrences. Order is normalized by `sequence`
     *                                      (ascending, stable sort — PHP 8.0+ guarantees stable
     *                                      sort), NOT by array input order and NOT by duration, so
     *                                      callers may pass occurrences in any array order as long
     *                                      as `sequence` reflects true chronological order.
     * @return array<string, mixed>
     *
     * @throws InvalidArgumentException on any structurally invalid input.
     * @throws OverflowException if the computed totals would exceed safe integer bounds.
     */
    public function calculate(int $purchasedStandardUnits, int $standardLessonMinutes, array $occurrences): array
    {
        $this->assertValidScalarInputs($purchasedStandardUnits, $standardLessonMinutes);
        $normalizedOccurrences = $this->normalizeOccurrences($occurrences);

        $entitlementMinutes = $this->safeMultiply($purchasedStandardUnits, $standardLessonMinutes);

        $remaining = $entitlementMinutes;
        $fullyCoveredOccurrences = 0;
        $fullyUncoveredOccurrences = 0;
        $firstPartiallyCoveredOccurrence = null;
        $partialCoveredMinutes = 0;
        $partialUncoveredMinutes = 0;
        $remainingAfterFullyCovered = null;
        $coveredTotal = 0;
        $uncoveredTotal = 0;
        $scheduledTotal = 0;
        $breakdown = [];
        $pastFullCoveragePhase = false;

        foreach ($normalizedOccurrences as $occ) {
            $dur = $occ['duration_minutes'];
            $scheduledTotal = $this->safeAdd($scheduledTotal, $dur);

            if (!$pastFullCoveragePhase && $remaining >= $dur) {
                $remaining -= $dur;
                $fullyCoveredOccurrences++;
                $coveredTotal = $this->safeAdd($coveredTotal, $dur);
                $breakdown[] = $this->breakdownRow($occ, self::CLASSIFICATION_FULLY_COVERED, $dur, 0);

                continue;
            }

            if (!$pastFullCoveragePhase) {
                // First occurrence this course cannot fully cover — this is the
                // one-and-only transition point (sequential fill is monotonic:
                // once remaining stops covering an occurrence fully, every
                // subsequent occurrence is necessarily fully uncovered too).
                $remainingAfterFullyCovered = $remaining;
                $pastFullCoveragePhase = true;

                if ($remaining > 0) {
                    $firstPartiallyCoveredOccurrence = $occ['sequence'];
                    $partialCoveredMinutes = $remaining;
                    $partialUncoveredMinutes = $dur - $remaining;
                    $coveredTotal = $this->safeAdd($coveredTotal, $partialCoveredMinutes);
                    $uncoveredTotal = $this->safeAdd($uncoveredTotal, $partialUncoveredMinutes);
                    $remaining = 0;
                    $breakdown[] = $this->breakdownRow(
                        $occ,
                        self::CLASSIFICATION_PARTIALLY_COVERED,
                        $partialCoveredMinutes,
                        $partialUncoveredMinutes
                    );

                    continue;
                }

                // remaining was already exactly 0 — this occurrence is fully uncovered.
                $fullyUncoveredOccurrences++;
                $uncoveredTotal = $this->safeAdd($uncoveredTotal, $dur);
                $breakdown[] = $this->breakdownRow($occ, self::CLASSIFICATION_FULLY_UNCOVERED, 0, $dur);

                continue;
            }

            // Already past the full-coverage phase: every later occurrence is fully uncovered.
            $fullyUncoveredOccurrences++;
            $uncoveredTotal = $this->safeAdd($uncoveredTotal, $dur);
            $breakdown[] = $this->breakdownRow($occ, self::CLASSIFICATION_FULLY_UNCOVERED, 0, $dur);
        }

        if ($remainingAfterFullyCovered === null) {
            // Every occurrence was fully covered (entitlement surplus, or exact fit
            // with nothing scheduled afterward) — the phase never transitioned.
            $remainingAfterFullyCovered = $remaining;
        }

        $remainingEntitlementMinutes = $remaining;

        return [
            'entitlement_minutes' => $entitlementMinutes,
            'scheduled_minutes' => $scheduledTotal,
            'fully_covered_occurrences' => $fullyCoveredOccurrences,
            'remaining_after_fully_covered' => $remainingAfterFullyCovered,
            'first_partially_covered_occurrence' => $firstPartiallyCoveredOccurrence,
            'partial_covered_minutes' => $partialCoveredMinutes,
            'partial_uncovered_minutes' => $partialUncoveredMinutes,
            'fully_uncovered_occurrences' => $fullyUncoveredOccurrences,
            'uncovered_minutes' => $uncoveredTotal,
            'remaining_entitlement_minutes' => $remainingEntitlementMinutes,
            // Convenience derived fields (not independently authoritative — always
            // recomputable from the fields above; kept for invariant tests / API use).
            'covered_minutes' => $coveredTotal,
            'occurrence_count' => count($normalizedOccurrences),
            'occurrences' => $breakdown,
        ];
    }

    /**
     * Fixed-precision decimal-string lesson equivalent, e.g. "6.50" or "1.00".
     * Uses ROUND_HALF_UP on integer minutes*100 arithmetic — never a binary float.
     * Mirrors the same round-half-up shape already used by
     * SessionDeductionService::roundHalfUp() so both stay consistent if the
     * house rounding convention ever changes.
     */
    public function lessonEquivalent(int $minutes, int $standardLessonMinutes): string
    {
        if ($standardLessonMinutes < 1) {
            throw new InvalidArgumentException('standardLessonMinutes must be >= 1.');
        }
        if ($minutes < 0) {
            throw new InvalidArgumentException('minutes must be >= 0.');
        }

        $hundredths = intdiv($minutes * 200 + $standardLessonMinutes, $standardLessonMinutes * 2);

        return sprintf('%d.%02d', intdiv($hundredths, 100), $hundredths % 100);
    }

    private function assertValidScalarInputs(int $purchasedStandardUnits, int $standardLessonMinutes): void
    {
        if ($purchasedStandardUnits < 0) {
            throw new InvalidArgumentException('purchasedStandardUnits must be >= 0.');
        }
        if ($standardLessonMinutes < 1) {
            throw new InvalidArgumentException(
                'standardLessonMinutes must be >= 1 (missing/zero standard duration is a caller '
                . 'error — this calculator has no fallback default).'
            );
        }
    }

    /**
     * @param  array<int, array{duration_minutes:int, sequence:int, date?:string, slot?:string}>  $occurrences
     * @return array<int, array{duration_minutes:int, sequence:int, date:?string, slot:?string}>
     */
    private function normalizeOccurrences(array $occurrences): array
    {
        if (count($occurrences) > self::MAX_OCCURRENCES) {
            throw new InvalidArgumentException('occurrences count exceeds sanity cap.');
        }

        $normalized = [];
        foreach ($occurrences as $index => $occ) {
            if (!is_array($occ) || !array_key_exists('duration_minutes', $occ) || !array_key_exists('sequence', $occ)) {
                throw new InvalidArgumentException(
                    "occurrence at index {$index} must be an array with 'duration_minutes' and 'sequence'."
                );
            }
            $duration = $occ['duration_minutes'];
            if (!is_int($duration)) {
                throw new InvalidArgumentException("occurrence[{$index}].duration_minutes must be an int.");
            }
            if ($duration < 1) {
                throw new InvalidArgumentException(
                    "occurrence[{$index}].duration_minutes must be >= 1 (zero/negative duration is invalid, "
                    . 'not silently dropped).'
                );
            }
            $sequence = $occ['sequence'];
            if (!is_int($sequence)) {
                throw new InvalidArgumentException("occurrence[{$index}].sequence must be an int.");
            }

            $normalized[] = [
                'duration_minutes' => $duration,
                'sequence' => $sequence,
                'date' => $occ['date'] ?? null,
                'slot' => $occ['slot'] ?? null,
            ];
        }

        usort($normalized, static fn (array $a, array $b): int => $a['sequence'] <=> $b['sequence']);

        return $normalized;
    }

    /** @param array{duration_minutes:int, sequence:int, date:?string, slot:?string} $occ */
    private function breakdownRow(array $occ, string $classification, int $coveredMinutes, int $uncoveredMinutes): array
    {
        return [
            'sequence' => $occ['sequence'],
            'duration_minutes' => $occ['duration_minutes'],
            'date' => $occ['date'],
            'slot' => $occ['slot'],
            'classification' => $classification,
            'covered_minutes' => $coveredMinutes,
            'uncovered_minutes' => $uncoveredMinutes,
        ];
    }

    private function safeAdd(int $a, int $b): int
    {
        if ($b > 0 && $a > PHP_INT_MAX - $b) {
            throw new OverflowException('Integer overflow while summing minutes.');
        }

        return $a + $b;
    }

    private function safeMultiply(int $a, int $b): int
    {
        if ($a !== 0 && $b !== 0 && (abs($a) > intdiv(PHP_INT_MAX, abs($b)))) {
            throw new OverflowException('Integer overflow while computing entitlement_minutes.');
        }

        return $a * $b;
    }
}
