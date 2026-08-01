<?php

namespace App\Services\Scheduling;

use InvalidArgumentException;
use OverflowException;

/**
 * RFC_NONSTANDARD_SESSION_DURATION_BILLING Phase 0B — pure coverage calculator.
 *
 * For one course: given an entitlement expressed in that course's own "standard
 * lesson" units and a sequence of scheduled occurrences of arbitrary durations,
 * reports how many occurrences are fully covered, which one (if any) is the first
 * only partially covered, and how many minutes are left uncovered.
 *
 * `standardLessonMinutes` is ALWAYS the caller's per-course contract value. There
 * is no company-wide constant and no fallback default: the same 180-minute session
 * is worth 2.00 / 1.50 / 1.00 lesson-equivalents for a 90 / 120 / 180-minute course
 * standard. Anything assuming "a lesson is 120 minutes" is a bug.
 *
 * PREVIEW/DIAGNOSTIC only — touches no database, calls no deduction service, and is
 * never a second authoritative engine; SessionDeductionService remains the only
 * deduction write path (RFC §5, §9). It exists so create-time preview and read-only
 * reporting share one formula. Distinct from CoverageStates / SessionCoverageState-
 * Machine (ADR-006 shared-pool custody), which this RFC does not touch.
 *
 * Input is UNTRUSTED and validated at runtime; invalid input throws rather than
 * being silently clamped or dropped.
 */
final class LessonEntitlementCoverageCalculator
{
    /**
     * Pathological-input guard, not a business rule. Entitlement/duration magnitude is
     * capped only by genuine overflow detection — inventing a business-magnitude limit
     * here would be an uninvited product rule.
     */
    private const MAX_OCCURRENCES = 20000;

    public const CLASSIFICATION_FULLY_COVERED = 'fully_covered';
    public const CLASSIFICATION_PARTIALLY_COVERED = 'partially_covered';
    public const CLASSIFICATION_FULLY_UNCOVERED = 'fully_uncovered';

    /**
     * @param  int  $purchasedStandardUnits  Entitlement in this course's standard lesson units. Must be >= 0.
     * @param  int  $standardLessonMinutes  This course's contract value for one standard lesson (>= 1).
     *                                      Deliberately NO fallback default — callers resolve the
     *                                      persisted per-course value (RFC §10 A1) and pass it in; a
     *                                      missing value is a caller bug, not a "use the house default".
     * @param  array<mixed>  $occurrences  Untrusted. Each element: int `duration_minutes` (>= 1), int
     *                                     `sequence` (unique), optional string `date`/`slot`. Coverage is
     *                                     allocated in ascending `sequence` order — not array order, not
     *                                     by duration.
     * @return array<string, mixed>
     *
     * @throws InvalidArgumentException on structurally invalid input.
     * @throws OverflowException if totals would exceed safe integer bounds.
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
                // First occurrence entitlement cannot fully cover. Sequential fill is
                // monotonic, so this transition happens at most once: everything after
                // it is necessarily fully uncovered.
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

                // Entitlement was already exactly exhausted — fully uncovered, not partial.
                $fullyUncoveredOccurrences++;
                $uncoveredTotal = $this->safeAdd($uncoveredTotal, $dur);
                $breakdown[] = $this->breakdownRow($occ, self::CLASSIFICATION_FULLY_UNCOVERED, 0, $dur);

                continue;
            }

            $fullyUncoveredOccurrences++;
            $uncoveredTotal = $this->safeAdd($uncoveredTotal, $dur);
            $breakdown[] = $this->breakdownRow($occ, self::CLASSIFICATION_FULLY_UNCOVERED, 0, $dur);
        }

        // Never transitioned => every occurrence fit (surplus or exact fit).
        $remainingAfterFullyCovered ??= $remaining;

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
            'remaining_entitlement_minutes' => $remaining,
            // Derived conveniences — always recomputable from the fields above.
            'covered_minutes' => $coveredTotal,
            'occurrence_count' => count($normalizedOccurrences),
            'occurrences' => $breakdown,
        ];
    }

    /**
     * Fixed-precision (2dp) decimal-string lesson equivalent, e.g. "6.50", "1.00", "2.00".
     *
     * Integer arithmetic only — never a binary float, which must not be the billing
     * truth. Divides first and scales only the remainder so the scaling multiplication
     * cannot overflow for any input the entitlement arithmetic itself accepts.
     * Rounds half-up, matching SessionDeductionService::roundHalfUp()'s convention.
     */
    public function lessonEquivalent(int $minutes, int $standardLessonMinutes): string
    {
        if ($standardLessonMinutes < 1) {
            throw new InvalidArgumentException('standardLessonMinutes must be >= 1.');
        }
        if ($minutes < 0) {
            throw new InvalidArgumentException('minutes must be >= 0.');
        }
        // The remainder scaling below computes remainder*200 + standard, where
        // remainder < standard. Reject only the (absurd) magnitudes where that
        // could overflow, rather than silently producing a wrapped value.
        if ($standardLessonMinutes > intdiv(PHP_INT_MAX - $standardLessonMinutes, 200)) {
            throw new OverflowException('standardLessonMinutes too large to scale without integer overflow.');
        }

        $whole = intdiv($minutes, $standardLessonMinutes);
        $remainder = $minutes % $standardLessonMinutes;

        // ROUND_HALF_UP(remainder * 100 / standard) == floor((2*(remainder*100) + standard) / (2*standard))
        $hundredths = intdiv($remainder * 200 + $standardLessonMinutes, $standardLessonMinutes * 2);
        if ($hundredths >= 100) {
            // Remainder rounded up to a whole unit — carry it.
            $whole++;
            $hundredths -= 100;
        }

        return sprintf('%d.%02d', $whole, $hundredths);
    }

    private function assertValidScalarInputs(int $purchasedStandardUnits, int $standardLessonMinutes): void
    {
        if ($purchasedStandardUnits < 0) {
            throw new InvalidArgumentException('purchasedStandardUnits must be >= 0.');
        }
        if ($standardLessonMinutes < 1) {
            throw new InvalidArgumentException(
                'standardLessonMinutes must be >= 1 (a missing or zero per-course standard duration is a '
                . 'caller error — this calculator has no fallback default).'
            );
        }
    }

    /**
     * @param  array<mixed>  $occurrences
     * @return list<array{duration_minutes:int, sequence:int, date:?string, slot:?string}>
     */
    private function normalizeOccurrences(array $occurrences): array
    {
        if (count($occurrences) > self::MAX_OCCURRENCES) {
            throw new InvalidArgumentException('occurrences count exceeds sanity cap.');
        }

        $normalized = [];
        $seenSequences = [];
        foreach ($occurrences as $index => $occ) {
            if (!is_array($occ)) {
                throw new InvalidArgumentException("occurrence at index {$index} must be an array.");
            }
            if (!array_key_exists('duration_minutes', $occ) || !array_key_exists('sequence', $occ)) {
                throw new InvalidArgumentException(
                    "occurrence at index {$index} must define both 'duration_minutes' and 'sequence'."
                );
            }

            $duration = $occ['duration_minutes'];
            if (!is_int($duration)) {
                throw new InvalidArgumentException("occurrence[{$index}].duration_minutes must be an int.");
            }
            if ($duration < 1) {
                throw new InvalidArgumentException(
                    "occurrence[{$index}].duration_minutes must be >= 1 (a zero or negative duration is "
                    . 'invalid input, not something to silently drop).'
                );
            }

            $sequence = $occ['sequence'];
            if (!is_int($sequence)) {
                throw new InvalidArgumentException("occurrence[{$index}].sequence must be an int.");
            }
            if (isset($seenSequences[$sequence])) {
                // Duplicate sequence makes the coverage order ambiguous — which of the two
                // occurrences is consumed first changes which one ends up partially covered.
                throw new InvalidArgumentException(
                    "duplicate occurrence sequence {$sequence} — coverage order would be ambiguous."
                );
            }
            $seenSequences[$sequence] = true;

            $normalized[] = [
                'duration_minutes' => $duration,
                'sequence' => $sequence,
                'date' => $this->optionalString($occ, 'date', $index),
                'slot' => $this->optionalString($occ, 'slot', $index),
            ];
        }

        usort($normalized, static fn (array $a, array $b): int => $a['sequence'] <=> $b['sequence']);

        return $normalized;
    }

    /**
     * @param  array<mixed>  $occ
     */
    private function optionalString(array $occ, string $key, int|string $index): ?string
    {
        if (!array_key_exists($key, $occ) || $occ[$key] === null) {
            return null;
        }
        $value = $occ[$key];
        if (!is_string($value)) {
            throw new InvalidArgumentException("occurrence[{$index}].{$key} must be a string or null.");
        }

        return $value;
    }

    /**
     * @param  array{duration_minutes:int, sequence:int, date:?string, slot:?string}  $occ
     * @return array<string, mixed>
     */
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
        if ($a !== 0 && $b !== 0 && $a > intdiv(PHP_INT_MAX, $b)) {
            throw new OverflowException('Integer overflow while computing entitlement_minutes.');
        }

        return $a * $b;
    }
}
