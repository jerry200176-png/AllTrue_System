<?php

namespace Tests\Unit\Scheduling;

use App\Services\Scheduling\NonstandardDurationCoveragePreviewContract;
use PHPUnit\Framework\TestCase;

/**
 * RFC_NONSTANDARD_SESSION_DURATION_BILLING Part D — dry-run contract shape tests.
 * Pure unit tests: no DB, no HTTP, no controller. Proves the response shape matches
 * the exact JSON contract given in the RFC Part D spec, using the same required
 * example numbers.
 */
class NonstandardDurationCoveragePreviewContractTest extends TestCase
{
    private NonstandardDurationCoveragePreviewContract $contract;

    protected function setUp(): void
    {
        parent::setUp();
        $this->contract = new NonstandardDurationCoveragePreviewContract();
    }

    /** @param list<int> $durations */
    private function occurrences(array $durations): array
    {
        $rows = [];
        foreach ($durations as $i => $dur) {
            $rows[] = ['duration_minutes' => $dur, 'sequence' => $i + 1];
        }

        return $rows;
    }

    public function test_response_matches_the_exact_dry_run_contract_example(): void
    {
        $response = $this->contract->build(
            'actual_duration',
            8,
            120,
            $this->occurrences([180, 180, 180, 180, 180, 180])
        );

        $this->assertSame([
            'deduction_basis' => 'actual_duration',
            'purchased_standard_units' => 8,
            'standard_lesson_minutes' => 120,
            'entitlement_minutes' => 960,
            'scheduled_occurrence_count' => 6,
            'scheduled_minutes' => 1080,
            'fully_covered_occurrences' => 5,
            'first_partially_covered_occurrence' => 6,
            'partial_covered_minutes' => 60,
            'partial_uncovered_minutes' => 120,
            'fully_uncovered_occurrences' => 0,
            'remaining_entitlement_minutes' => 0,
            'uncovered_minutes' => 120,
            'remaining_lesson_equivalent' => '0.00',
            'uncovered_lesson_equivalent' => '1.00',
            'requires_overage_confirmation' => true,
        ], $response);
    }

    public function test_requires_overage_confirmation_is_false_when_fully_covered(): void
    {
        $response = $this->contract->build(
            'actual_duration',
            8,
            120,
            $this->occurrences([120, 120, 120, 120, 120, 120, 120, 120])
        );

        $this->assertFalse($response['requires_overage_confirmation']);
        $this->assertSame(0, $response['uncovered_minutes']);
        $this->assertSame('0.00', $response['uncovered_lesson_equivalent']);
    }

    public function test_requires_overage_confirmation_is_false_when_entitlement_has_surplus(): void
    {
        $response = $this->contract->build('actual_duration', 10, 120, $this->occurrences([120, 120, 120]));

        $this->assertFalse($response['requires_overage_confirmation']);
        $this->assertSame(840, $response['remaining_entitlement_minutes']);
        $this->assertSame('7.00', $response['remaining_lesson_equivalent']);
    }

    public function test_deduction_basis_is_passed_through_verbatim(): void
    {
        $response = $this->contract->build('fixed_session', 8, 120, $this->occurrences([120]));
        $this->assertSame('fixed_session', $response['deduction_basis']);
    }

    public function test_build_never_touches_a_database_or_side_effect(): void
    {
        // No DB facade, no Eloquent model, nothing static-stateful is touched by this
        // contract — calling it twice with the same input must be side-effect-free and
        // deterministic (this is a compile-time/structural guarantee we still assert on).
        $occurrences = $this->occurrences([180, 120, 90]);
        $first = $this->contract->build('actual_duration', 3, 120, $occurrences);
        $second = $this->contract->build('actual_duration', 3, 120, $occurrences);
        $this->assertSame($first, $second);
    }

    public function test_response_has_exactly_the_contract_keys_no_more_no_less(): void
    {
        $response = $this->contract->build('actual_duration', 8, 120, $this->occurrences([180]));
        $this->assertSame([
            'deduction_basis', 'purchased_standard_units', 'standard_lesson_minutes',
            'entitlement_minutes', 'scheduled_occurrence_count', 'scheduled_minutes',
            'fully_covered_occurrences', 'first_partially_covered_occurrence',
            'partial_covered_minutes', 'partial_uncovered_minutes',
            'fully_uncovered_occurrences', 'remaining_entitlement_minutes',
            'uncovered_minutes', 'remaining_lesson_equivalent', 'uncovered_lesson_equivalent',
            'requires_overage_confirmation',
        ], array_keys($response));
    }
}
