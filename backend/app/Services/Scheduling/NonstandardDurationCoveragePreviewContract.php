<?php

namespace App\Services\Scheduling;

/**
 * RFC_NONSTANDARD_SESSION_DURATION_BILLING Part D — dry-run coverage-preview contract.
 *
 * Defines the future production preview response shape WITHOUT enabling any
 * production write flow. This class:
 *  - does not create a StudentClass or ClassSession,
 *  - does not write session_deduction_ledger,
 *  - does not persist a preview anywhere,
 *  - does not trigger any side effect.
 * It is pure request-in/response-out, built on top of
 * LessonEntitlementCoverageCalculator (never duplicates its formula).
 *
 * There is deliberately no HTTP controller/route here in this RFC-only slice —
 * per RFC Part D scope guidance, only the service contract + tests + spec are
 * built now. When Phase 2 wires this into `POST /api/v1/class-sessions/batch`'s
 * preview path (see RFC Part F Phase 2 plan), the future controller must call
 * this contract and must NOT share a handler with the write-path create endpoint.
 */
final class NonstandardDurationCoveragePreviewContract
{
    public function __construct(
        private ?LessonEntitlementCoverageCalculator $calculator = null,
    ) {
        $this->calculator = $calculator ?? new LessonEntitlementCoverageCalculator();
    }

    /**
     * @param  string  $deductionBasis  Passed through verbatim (e.g. 'actual_duration');
     *                                  this contract does not itself decide opt-in — a
     *                                  caller building this preview for a `fixed_session`
     *                                  course should not call this at all (Phase 2 plan
     *                                  §"create preview integration" covers that branch).
     * @param  array<int, array{duration_minutes:int, sequence:int, date?:string, slot?:string}>  $occurrences
     * @return array<string, mixed>
     */
    public function build(
        string $deductionBasis,
        int $purchasedStandardUnits,
        int $standardLessonMinutes,
        array $occurrences
    ): array {
        $result = $this->calculator->calculate($purchasedStandardUnits, $standardLessonMinutes, $occurrences);

        $remainingEquivalent = $this->calculator->lessonEquivalent(
            $result['remaining_entitlement_minutes'],
            $standardLessonMinutes
        );
        $uncoveredEquivalent = $this->calculator->lessonEquivalent(
            $result['uncovered_minutes'],
            $standardLessonMinutes
        );

        return [
            'deduction_basis' => $deductionBasis,
            'purchased_standard_units' => $purchasedStandardUnits,
            'standard_lesson_minutes' => $standardLessonMinutes,
            'entitlement_minutes' => $result['entitlement_minutes'],
            'scheduled_occurrence_count' => $result['occurrence_count'],
            'scheduled_minutes' => $result['scheduled_minutes'],
            'fully_covered_occurrences' => $result['fully_covered_occurrences'],
            'first_partially_covered_occurrence' => $result['first_partially_covered_occurrence'],
            'partial_covered_minutes' => $result['partial_covered_minutes'],
            'partial_uncovered_minutes' => $result['partial_uncovered_minutes'],
            'fully_uncovered_occurrences' => $result['fully_uncovered_occurrences'],
            'remaining_entitlement_minutes' => $result['remaining_entitlement_minutes'],
            'uncovered_minutes' => $result['uncovered_minutes'],
            'remaining_lesson_equivalent' => $remainingEquivalent,
            'uncovered_lesson_equivalent' => $uncoveredEquivalent,
            // D5: soft-block condition. True whenever the schedule as given would use
            // more minutes than the entitlement covers — the caller (future Phase 2 UI)
            // must require the explicit "我已確認超額部分需要續約、加購或後續處理"
            // checkbox before allowing course creation to proceed. This contract never
            // blocks anything itself — it only reports the condition.
            'requires_overage_confirmation' => $result['scheduled_minutes'] > $result['entitlement_minutes'],
        ];
    }
}
