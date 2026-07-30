<?php

namespace Tests\Unit\Scheduling;

use App\Services\Scheduling\LessonEntitlementCoverageCalculator;
use InvalidArgumentException;
use OverflowException;
use PHPUnit\Framework\TestCase;

/**
 * RFC_NONSTANDARD_SESSION_DURATION_BILLING Phase 0B calculator — pure unit tests (no DB).
 * This calculator is a preview/diagnostic only; it must never be confused with, or
 * duplicate, SessionDeductionService's authoritative runtime deduction formula.
 */
class LessonEntitlementCoverageCalculatorTest extends TestCase
{
    private LessonEntitlementCoverageCalculator $calc;

    protected function setUp(): void
    {
        parent::setUp();
        $this->calc = new LessonEntitlementCoverageCalculator();
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

    /**
     * Required example from the task spec: 8 standard units @ 120min, six 180min
     * occurrences. Every field below must match the Founder-provided numbers exactly.
     */
    public function test_required_example_matches_founder_numbers(): void
    {
        $result = $this->calc->calculate(8, 120, $this->occurrences([180, 180, 180, 180, 180, 180]));

        $this->assertSame(960, $result['entitlement_minutes']);
        $this->assertSame(1080, $result['scheduled_minutes']);
        $this->assertSame(5, $result['fully_covered_occurrences']);
        $this->assertSame(60, $result['remaining_after_fully_covered']);
        $this->assertSame(6, $result['first_partially_covered_occurrence']);
        $this->assertSame(60, $result['partial_covered_minutes']);
        $this->assertSame(120, $result['partial_uncovered_minutes']);
        $this->assertSame(0, $result['fully_uncovered_occurrences']);
        $this->assertSame(120, $result['uncovered_minutes']);
        $this->assertSame(0, $result['remaining_entitlement_minutes']);

        // Conservation invariants (see test_conservation_invariants_hold_across_cases for the
        // generalized/data-provider version; asserted directly here too since this is the
        // canonical example).
        $this->assertSame($result['scheduled_minutes'], $result['covered_minutes'] + $result['uncovered_minutes']);
        $this->assertSame(
            $result['entitlement_minutes'],
            $result['covered_minutes'] + $result['remaining_entitlement_minutes']
        );
    }

    public function test_standard_case_fully_covers_with_no_overage(): void
    {
        $result = $this->calc->calculate(8, 120, $this->occurrences([120, 120, 120, 120, 120, 120, 120, 120]));

        $this->assertSame(960, $result['entitlement_minutes']);
        $this->assertSame(960, $result['scheduled_minutes']);
        $this->assertSame(8, $result['fully_covered_occurrences']);
        $this->assertSame(0, $result['fully_uncovered_occurrences']);
        $this->assertNull($result['first_partially_covered_occurrence']);
        $this->assertSame(0, $result['partial_covered_minutes']);
        $this->assertSame(0, $result['partial_uncovered_minutes']);
        $this->assertSame(0, $result['uncovered_minutes']);
        $this->assertSame(0, $result['remaining_entitlement_minutes']);
        $this->assertSame(0, $result['remaining_after_fully_covered']);
    }

    /**
     * Task-spec literal input (4 units @ 120min = 480 entitlement;
     * occurrences [90,90,90,90,90,30] sum to exactly 480). This is an EXACT-FIT
     * boundary, not a partial split — the sum of the given durations happens to
     * equal the entitlement exactly. Documented here explicitly because the task
     * description says "確認 partial coverage 正確" for this input, but the numbers
     * as given produce zero uncovered minutes; see
     * test_ninety_minute_case_produces_genuine_partial_coverage below for the
     * genuinely-partial variant (six 90-minute sessions, matching RFC Case 3).
     */
    public function test_ninety_minute_case_as_specified_is_an_exact_fit_not_a_partial(): void
    {
        $result = $this->calc->calculate(4, 120, $this->occurrences([90, 90, 90, 90, 90, 30]));

        $this->assertSame(480, $result['entitlement_minutes']);
        $this->assertSame(480, $result['scheduled_minutes']);
        $this->assertSame(6, $result['fully_covered_occurrences']);
        $this->assertSame(0, $result['fully_uncovered_occurrences']);
        $this->assertNull($result['first_partially_covered_occurrence']);
        $this->assertSame(0, $result['uncovered_minutes']);
        $this->assertSame(0, $result['remaining_entitlement_minutes']);
    }

    /**
     * Genuine partial-coverage variant of the 90-minute case (RFC Case 3: a
     * 90-minute session against a 120-minute standard = 0.75 standard lessons).
     * Six 90-minute occurrences (540 scheduled) against a 480-minute entitlement.
     */
    public function test_ninety_minute_case_produces_genuine_partial_coverage(): void
    {
        $result = $this->calc->calculate(4, 120, $this->occurrences([90, 90, 90, 90, 90, 90]));

        $this->assertSame(480, $result['entitlement_minutes']);
        $this->assertSame(540, $result['scheduled_minutes']);
        $this->assertSame(5, $result['fully_covered_occurrences']);
        $this->assertSame(6, $result['first_partially_covered_occurrence']);
        $this->assertSame(30, $result['partial_covered_minutes']);
        $this->assertSame(60, $result['partial_uncovered_minutes']);
        $this->assertSame(0, $result['fully_uncovered_occurrences']);
        $this->assertSame(60, $result['uncovered_minutes']);
        $this->assertSame(0, $result['remaining_entitlement_minutes']);
    }

    /**
     * Mixed duration: standard=120, occurrences [180,120,180,120] in that sequence.
     * Entitlement chosen (3 units = 360 minutes) specifically so that a naive
     * "average duration" implementation (avg=150/occurrence -> ~2.4 occurrences)
     * would report different numbers than the correct sequence-based fill, which
     * must consume 180 (occ1, full), 120 (occ2, full), then split occ3 (180)
     * since only 60 minutes remain.
     */
    public function test_mixed_duration_uses_actual_sequence_not_average(): void
    {
        $result = $this->calc->calculate(3, 120, $this->occurrences([180, 120, 180, 120]));

        $this->assertSame(360, $result['entitlement_minutes']);
        $this->assertSame(600, $result['scheduled_minutes']);
        $this->assertSame(2, $result['fully_covered_occurrences']);
        $this->assertSame(3, $result['first_partially_covered_occurrence'], 'the 3rd occurrence (180min) is the partial one, not a position implied by averaging');
        $this->assertSame(60, $result['partial_covered_minutes']);
        $this->assertSame(120, $result['partial_uncovered_minutes']);
        $this->assertSame(1, $result['fully_uncovered_occurrences'], 'the 4th occurrence is fully uncovered');
        $this->assertSame(240, $result['uncovered_minutes']);
    }

    /**
     * Same multiset of durations {120,180}, entitlement=150 (chosen so that whichever
     * occurrence comes SECOND is the one that ends up only partially covered — proving
     * classification follows `sequence`, not array input order and not duration size).
     */
    public function test_occurrence_order_not_array_order_determines_first_partial(): void
    {
        // array order matches sequence order: 120 first (sequence 1, full), 180 second
        // (sequence 2, only 30 of entitlement remains -> partial).
        $inOrder = $this->calc->calculate(1, 150, [
            ['duration_minutes' => 180, 'sequence' => 2],
            ['duration_minutes' => 120, 'sequence' => 1],
        ]);
        $this->assertSame(1, $inOrder['fully_covered_occurrences']);
        $this->assertSame(2, $inOrder['first_partially_covered_occurrence']);
        $this->assertSame(30, $inOrder['partial_covered_minutes']);
        $this->assertSame(150, $inOrder['partial_uncovered_minutes']);

        // Same array contents, but the 180-minute row now claims to be sequence 1 (i.e.
        // actually scheduled first) -> it must now be the one that's only partially
        // covered (150 of 180 minutes), and the 120-minute row (now sequence 2) is fully
        // uncovered instead of fully covered — proving classification follows `sequence`,
        // not array input order.
        $reordered = $this->calc->calculate(1, 150, [
            ['duration_minutes' => 180, 'sequence' => 1],
            ['duration_minutes' => 120, 'sequence' => 2],
        ]);
        $this->assertSame(0, $reordered['fully_covered_occurrences']);
        $this->assertSame(1, $reordered['first_partially_covered_occurrence']);
        $this->assertSame(150, $reordered['partial_covered_minutes']);
        $this->assertSame(30, $reordered['partial_uncovered_minutes']);
        $this->assertSame(1, $reordered['fully_uncovered_occurrences']);
    }

    public function test_entitlement_exceeds_schedule_leaves_positive_remaining_and_zero_uncovered(): void
    {
        $result = $this->calc->calculate(10, 120, $this->occurrences([120, 120, 120]));

        $this->assertSame(1200, $result['entitlement_minutes']);
        $this->assertSame(360, $result['scheduled_minutes']);
        $this->assertSame(3, $result['fully_covered_occurrences']);
        $this->assertSame(0, $result['uncovered_minutes']);
        $this->assertGreaterThan(0, $result['remaining_entitlement_minutes']);
        $this->assertSame(840, $result['remaining_entitlement_minutes']);
        $this->assertNull($result['first_partially_covered_occurrence']);
    }

    public function test_zero_purchased_units_is_valid_and_everything_is_uncovered(): void
    {
        $result = $this->calc->calculate(0, 120, $this->occurrences([120, 180]));

        $this->assertSame(0, $result['entitlement_minutes']);
        $this->assertSame(300, $result['scheduled_minutes']);
        $this->assertSame(0, $result['fully_covered_occurrences']);
        // remaining is already 0 at the very first occurrence -> fully uncovered,
        // not "partially covered with 0 covered minutes" (0 covered is not a partial state).
        $this->assertNull($result['first_partially_covered_occurrence']);
        $this->assertSame(2, $result['fully_uncovered_occurrences']);
        $this->assertSame(300, $result['uncovered_minutes']);
        $this->assertSame(0, $result['remaining_entitlement_minutes']);
    }

    public function test_zero_occurrences_is_valid(): void
    {
        $result = $this->calc->calculate(8, 120, []);

        $this->assertSame(960, $result['entitlement_minutes']);
        $this->assertSame(0, $result['scheduled_minutes']);
        $this->assertSame(0, $result['fully_covered_occurrences']);
        $this->assertSame(0, $result['fully_uncovered_occurrences']);
        $this->assertSame(0, $result['uncovered_minutes']);
        $this->assertSame(960, $result['remaining_entitlement_minutes']);
        $this->assertSame([], $result['occurrences']);
    }

    public function test_negative_purchased_units_fails_fast(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->calc->calculate(-1, 120, []);
    }

    public function test_zero_standard_lesson_minutes_fails_fast_no_silent_default(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->calc->calculate(8, 0, []);
    }

    public function test_negative_standard_lesson_minutes_fails_fast(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->calc->calculate(8, -120, []);
    }

    public function test_zero_occurrence_duration_fails_fast_not_silently_dropped(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->calc->calculate(8, 120, [['duration_minutes' => 0, 'sequence' => 1]]);
    }

    public function test_negative_occurrence_duration_fails_fast(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->calc->calculate(8, 120, [['duration_minutes' => -30, 'sequence' => 1]]);
    }

    public function test_missing_sequence_key_fails_fast(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->calc->calculate(8, 120, [['duration_minutes' => 120]]);
    }

    public function test_occurrence_count_upper_bound_fails_fast(): void
    {
        $huge = [];
        for ($i = 0; $i < 20001; $i++) {
            $huge[] = ['duration_minutes' => 30, 'sequence' => $i + 1];
        }
        $this->expectException(InvalidArgumentException::class);
        $this->calc->calculate(8, 120, $huge);
    }

    public function test_large_but_non_overflowing_values_are_accepted_not_rejected(): void
    {
        // There is no arbitrary business cap on magnitude — only genuine overflow
        // is rejected. 1,000,000 x 100,000 = 1e11, nowhere near PHP_INT_MAX, so
        // this must compute normally rather than throw.
        $result = $this->calc->calculate(1000000, 100000, []);
        $this->assertSame(100000000000, $result['entitlement_minutes']);
    }

    public function test_entitlement_multiplication_overflow_is_detected(): void
    {
        $this->expectException(OverflowException::class);
        // PHP_INT_MAX x 2 genuinely overflows a 64-bit int; must throw rather than
        // silently wrap to a negative/incorrect number.
        $this->calc->calculate(PHP_INT_MAX, 2, []);
    }

    public function test_occurrence_sum_overflow_is_detected(): void
    {
        $this->expectException(OverflowException::class);
        $near = intdiv(PHP_INT_MAX, 2) + 10;
        // Two individually-valid occurrence durations whose SUM overflows PHP_INT_MAX.
        $this->calc->calculate(1, $near, [
            ['duration_minutes' => $near, 'sequence' => 1],
            ['duration_minutes' => $near, 'sequence' => 2],
        ]);
    }

    /**
     * @dataProvider conservationCaseProvider
     */
    public function test_conservation_invariants_hold_across_cases(int $units, int $standard, array $durations): void
    {
        $result = $this->calc->calculate($units, $standard, $this->occurrences($durations));

        $this->assertSame(
            $result['scheduled_minutes'],
            $result['covered_minutes'] + $result['uncovered_minutes'],
            'covered + uncovered must equal total scheduled minutes'
        );
        $this->assertSame(
            $result['entitlement_minutes'],
            $result['covered_minutes'] + $result['remaining_entitlement_minutes'],
            'covered + remaining entitlement must equal total entitlement minutes'
        );

        // Every occurrence classification must be mutually exclusive and complete:
        // exactly one of fully_covered / partially_covered / fully_uncovered per row,
        // and the counts must add up to the occurrence total.
        $counts = ['fully_covered' => 0, 'partially_covered' => 0, 'fully_uncovered' => 0];
        foreach ($result['occurrences'] as $row) {
            $this->assertArrayHasKey($row['classification'], $counts);
            $counts[$row['classification']]++;
        }
        $this->assertSame($result['fully_covered_occurrences'], $counts['fully_covered']);
        $this->assertSame($result['fully_uncovered_occurrences'], $counts['fully_uncovered']);
        $this->assertSame(
            $result['first_partially_covered_occurrence'] !== null ? 1 : 0,
            $counts['partially_covered']
        );
        $this->assertSame(count($durations), array_sum($counts));
    }

    /** @return array<string, array{0:int,1:int,2:list<int>}> */
    public static function conservationCaseProvider(): array
    {
        return [
            'required example' => [8, 120, [180, 180, 180, 180, 180, 180]],
            'standard 8x120' => [8, 120, [120, 120, 120, 120, 120, 120, 120, 120]],
            'ninety exact fit' => [4, 120, [90, 90, 90, 90, 90, 30]],
            'ninety genuine partial' => [4, 120, [90, 90, 90, 90, 90, 90]],
            'mixed duration' => [3, 120, [180, 120, 180, 120]],
            'entitlement surplus' => [10, 120, [120, 120, 120]],
            'zero units' => [0, 120, [120, 180]],
            'zero occurrences' => [8, 120, []],
            'single occurrence exact fit' => [1, 120, [120]],
            'single occurrence overage' => [1, 120, [121]],
        ];
    }

    public function test_result_is_deterministic_across_repeated_calls(): void
    {
        $occurrences = $this->occurrences([180, 120, 90, 30, 180]);
        $first = $this->calc->calculate(6, 120, $occurrences);
        $second = $this->calc->calculate(6, 120, $occurrences);
        $this->assertSame($first, $second);
    }

    public function test_no_float_types_appear_anywhere_in_result(): void
    {
        $result = $this->calc->calculate(8, 120, $this->occurrences([180, 180, 180, 180, 180, 180]));
        $this->assertResultHasNoFloats($result);
    }

    private function assertResultHasNoFloats(array $result): void
    {
        foreach ($result as $key => $value) {
            if (is_array($value)) {
                foreach ($value as $row) {
                    $this->assertResultHasNoFloats(is_array($row) ? $row : [$row]);
                }
                continue;
            }
            $this->assertIsNotFloat($value, "field '{$key}' must not be a float");
        }
    }

    // ── lessonEquivalent() decimal-string helper ──────────────────────────

    public function test_lesson_equivalent_matches_dry_run_contract_examples(): void
    {
        $this->assertSame('0.00', $this->calc->lessonEquivalent(0, 120));
        $this->assertSame('1.00', $this->calc->lessonEquivalent(120, 120));
        $this->assertSame('6.50', $this->calc->lessonEquivalent(780, 120));
        $this->assertSame('0.50', $this->calc->lessonEquivalent(60, 120));
    }

    public function test_lesson_equivalent_rounds_half_up_to_hundredths(): void
    {
        // 65/120 = 0.541666... -> rounds to 0.54 (nearest hundredth, half-up)
        $this->assertSame('0.54', $this->calc->lessonEquivalent(65, 120));
        // 90/120 = 0.75 exactly
        $this->assertSame('0.75', $this->calc->lessonEquivalent(90, 120));
    }

    public function test_lesson_equivalent_returns_string_type_never_float(): void
    {
        $this->assertIsString($this->calc->lessonEquivalent(780, 120));
    }

    public function test_lesson_equivalent_rejects_invalid_standard_minutes(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->calc->lessonEquivalent(60, 0);
    }

    public function test_lesson_equivalent_rejects_negative_minutes(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->calc->lessonEquivalent(-1, 120);
    }
}
