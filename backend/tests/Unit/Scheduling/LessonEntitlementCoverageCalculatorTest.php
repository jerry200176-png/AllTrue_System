<?php

namespace Tests\Unit\Scheduling;

use App\Services\Scheduling\LessonEntitlementCoverageCalculator;
use InvalidArgumentException;
use OverflowException;
use PHPUnit\Framework\TestCase;

/**
 * RFC_NONSTANDARD_SESSION_DURATION_BILLING Phase 0B calculator — pure unit tests (no DB).
 *
 * Central property under test: the lesson-equivalent of a session depends on THAT
 * COURSE's own standard lesson length. There is no company-wide 120-minute rule.
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

    // ── coverage allocation ───────────────────────────────────────────

    /**
     * @dataProvider coverageScenarioProvider
     *
     * @param  list<int>  $durations
     * @param  array<string, mixed>  $expected
     */
    public function test_coverage_scenarios(int $units, int $standard, array $durations, array $expected): void
    {
        $result = $this->calc->calculate($units, $standard, $this->occurrences($durations));

        foreach ($expected as $field => $value) {
            $this->assertSame($value, $result[$field], "field '{$field}'");
        }

        // Conservation must hold for every scenario.
        $this->assertSame(
            $result['scheduled_minutes'],
            $result['covered_minutes'] + $result['uncovered_minutes'],
            'covered + uncovered == scheduled'
        );
        $this->assertSame(
            $result['entitlement_minutes'],
            $result['covered_minutes'] + $result['remaining_entitlement_minutes'],
            'covered + remaining == entitlement'
        );

        // Classifications must be mutually exclusive and complete.
        $counts = ['fully_covered' => 0, 'partially_covered' => 0, 'fully_uncovered' => 0];
        foreach ($result['occurrences'] as $row) {
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

    /** @return array<string, array{0:int,1:int,2:list<int>,3:array<string,mixed>}> */
    public static function coverageScenarioProvider(): array
    {
        return [
            // The acceptance case: 8 units of a 120-minute standard against six 180-minute sessions.
            'acceptance case (8 x 120 standard, six 180min)' => [8, 120, [180, 180, 180, 180, 180, 180], [
                'entitlement_minutes' => 960,
                'scheduled_minutes' => 1080,
                'fully_covered_occurrences' => 5,
                'remaining_after_fully_covered' => 60,
                'first_partially_covered_occurrence' => 6,
                'partial_covered_minutes' => 60,
                'partial_uncovered_minutes' => 120,
                'fully_uncovered_occurrences' => 0,
                'uncovered_minutes' => 120,
                'remaining_entitlement_minutes' => 0,
            ]],
            // Same schedule, but the course's standard IS 180 — now fully covered with surplus.
            'same schedule with a 180min course standard is a surplus' => [8, 180, [180, 180, 180, 180, 180, 180], [
                'entitlement_minutes' => 1440,
                'scheduled_minutes' => 1080,
                'fully_covered_occurrences' => 6,
                'first_partially_covered_occurrence' => null,
                'uncovered_minutes' => 0,
                'remaining_entitlement_minutes' => 360,
            ]],
            // Same schedule against a 90-minute standard — each session costs 2 lessons.
            'same schedule with a 90min course standard runs out sooner' => [8, 90, [180, 180, 180, 180, 180, 180], [
                'entitlement_minutes' => 720,
                'fully_covered_occurrences' => 4,
                'first_partially_covered_occurrence' => null,
                'fully_uncovered_occurrences' => 2,
                'uncovered_minutes' => 360,
                'remaining_entitlement_minutes' => 0,
            ]],
            'standard whole-session course is exactly covered' => [8, 120, [120, 120, 120, 120, 120, 120, 120, 120], [
                'entitlement_minutes' => 960,
                'scheduled_minutes' => 960,
                'fully_covered_occurrences' => 8,
                'first_partially_covered_occurrence' => null,
                'uncovered_minutes' => 0,
                'remaining_entitlement_minutes' => 0,
            ]],
            'exact fit across uneven durations' => [4, 120, [90, 90, 90, 90, 90, 30], [
                'entitlement_minutes' => 480,
                'scheduled_minutes' => 480,
                'fully_covered_occurrences' => 6,
                'first_partially_covered_occurrence' => null,
                'uncovered_minutes' => 0,
            ]],
            'genuine partial on the last of six 90min sessions' => [4, 120, [90, 90, 90, 90, 90, 90], [
                'entitlement_minutes' => 480,
                'scheduled_minutes' => 540,
                'fully_covered_occurrences' => 5,
                'first_partially_covered_occurrence' => 6,
                'partial_covered_minutes' => 30,
                'partial_uncovered_minutes' => 60,
                'uncovered_minutes' => 60,
            ]],
            // Mixed durations must be consumed in sequence, never averaged: averaging 150/session
            // would imply a different partial occurrence than the real 180/120/180/120 order.
            'mixed durations follow sequence not average' => [3, 120, [180, 120, 180, 120], [
                'entitlement_minutes' => 360,
                'scheduled_minutes' => 600,
                'fully_covered_occurrences' => 2,
                'first_partially_covered_occurrence' => 3,
                'partial_covered_minutes' => 60,
                'partial_uncovered_minutes' => 120,
                'fully_uncovered_occurrences' => 1,
                'uncovered_minutes' => 240,
            ]],
            'entitlement surplus leaves everything covered' => [10, 120, [120, 120, 120], [
                'entitlement_minutes' => 1200,
                'fully_covered_occurrences' => 3,
                'uncovered_minutes' => 0,
                'remaining_entitlement_minutes' => 840,
                'first_partially_covered_occurrence' => null,
            ]],
            // Zero entitlement: nothing is "partially" covered — 0 covered minutes is not a partial.
            'zero units leaves everything uncovered' => [0, 120, [120, 180], [
                'entitlement_minutes' => 0,
                'fully_covered_occurrences' => 0,
                'first_partially_covered_occurrence' => null,
                'fully_uncovered_occurrences' => 2,
                'uncovered_minutes' => 300,
            ]],
            'no occurrences leaves the whole entitlement intact' => [8, 120, [], [
                'entitlement_minutes' => 960,
                'scheduled_minutes' => 0,
                'fully_covered_occurrences' => 0,
                'uncovered_minutes' => 0,
                'remaining_entitlement_minutes' => 960,
                'occurrence_count' => 0,
            ]],
            'single occurrence one minute over' => [1, 120, [121], [
                'fully_covered_occurrences' => 0,
                'first_partially_covered_occurrence' => 1,
                'partial_covered_minutes' => 120,
                'partial_uncovered_minutes' => 1,
            ]],
        ];
    }

    /**
     * Purchasing 8 units means a different number of minutes for every course
     * standard — "8 lessons" is never inherently 16 hours.
     *
     * @dataProvider entitlementByCourseStandardProvider
     */
    public function test_eight_units_means_different_total_minutes_per_course_standard(
        int $standard,
        int $expectedMinutes
    ): void {
        $this->assertSame($expectedMinutes, $this->calc->calculate(8, $standard, [])['entitlement_minutes']);
    }

    /** @return array<string, array{0:int,1:int}> */
    public static function entitlementByCourseStandardProvider(): array
    {
        return [
            '8 x 90min = 720min (12h)' => [90, 720],
            '8 x 120min = 960min (16h)' => [120, 960],
            '8 x 180min = 1440min (24h)' => [180, 1440],
            '8 x 45min = 360min (6h)' => [45, 360],
        ];
    }

    /** Classification follows `sequence`, not array order and not duration size. */
    public function test_occurrence_order_is_determined_by_sequence_field(): void
    {
        // Entitlement 150: whichever occurrence is scheduled SECOND ends up partial.
        $inOrder = $this->calc->calculate(1, 150, [
            ['duration_minutes' => 180, 'sequence' => 2],
            ['duration_minutes' => 120, 'sequence' => 1],
        ]);
        $this->assertSame(1, $inOrder['fully_covered_occurrences']);
        $this->assertSame(2, $inOrder['first_partially_covered_occurrence']);
        $this->assertSame(30, $inOrder['partial_covered_minutes']);

        // Swap only the sequence numbers: now the 180-minute row is first and partial.
        $reordered = $this->calc->calculate(1, 150, [
            ['duration_minutes' => 180, 'sequence' => 1],
            ['duration_minutes' => 120, 'sequence' => 2],
        ]);
        $this->assertSame(0, $reordered['fully_covered_occurrences']);
        $this->assertSame(1, $reordered['first_partially_covered_occurrence']);
        $this->assertSame(150, $reordered['partial_covered_minutes']);
        $this->assertSame(1, $reordered['fully_uncovered_occurrences']);
    }

    public function test_optional_fields_are_preserved_and_may_be_null(): void
    {
        $result = $this->calc->calculate(1, 120, [
            ['duration_minutes' => 120, 'sequence' => 1, 'date' => '2026-08-04', 'slot' => '16:00'],
            ['duration_minutes' => 120, 'sequence' => 2, 'date' => null, 'slot' => null],
        ]);
        $this->assertSame('2026-08-04', $result['occurrences'][0]['date']);
        $this->assertSame('16:00', $result['occurrences'][0]['slot']);
        $this->assertNull($result['occurrences'][1]['date']);
    }

    public function test_result_is_deterministic_and_free_of_floats(): void
    {
        $occurrences = $this->occurrences([180, 120, 90, 30, 180]);
        $first = $this->calc->calculate(6, 120, $occurrences);
        $this->assertSame($first, $this->calc->calculate(6, 120, $occurrences));
        $this->assertNoFloats($first);
    }

    private function assertNoFloats(array $result): void
    {
        foreach ($result as $key => $value) {
            if (is_array($value)) {
                foreach ($value as $row) {
                    $this->assertNoFloats(is_array($row) ? $row : [$row]);
                }

                continue;
            }
            $this->assertIsNotFloat($value, "field '{$key}' must not be a float");
        }
    }

    // ── fail-fast input validation ────────────────────────────────────

    /**
     * Invalid input must throw rather than be silently clamped or dropped — a
     * plausible-looking wrong number is worse than a loud failure for billing.
     *
     * @dataProvider invalidInputProvider
     */
    public function test_invalid_input_fails_fast(int $units, int $standard, array $occurrences): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->calc->calculate($units, $standard, $occurrences);
    }

    /** @return array<string, array{0:int,1:int,2:array<mixed>}> */
    public static function invalidInputProvider(): array
    {
        return [
            'negative purchased units' => [-1, 120, []],
            'zero standard (no silent house default)' => [8, 0, []],
            'negative standard' => [8, -120, []],
            'zero duration' => [8, 120, [['duration_minutes' => 0, 'sequence' => 1]]],
            'negative duration' => [8, 120, [['duration_minutes' => -30, 'sequence' => 1]]],
            'non-int duration' => [8, 120, [['duration_minutes' => '120', 'sequence' => 1]]],
            'missing sequence' => [8, 120, [['duration_minutes' => 120]]],
            'non-int sequence' => [8, 120, [['duration_minutes' => 120, 'sequence' => '1']]],
            'non-array occurrence' => [8, 120, ['not-an-array']],
            'non-string optional date' => [8, 120, [['duration_minutes' => 120, 'sequence' => 1, 'date' => 12345]]],
            'non-string optional slot' => [8, 120, [['duration_minutes' => 120, 'sequence' => 1, 'slot' => ['x']]]],
            // Duplicate sequence makes it ambiguous which occurrence is consumed first,
            // and therefore which one ends up partially covered.
            'duplicate sequence' => [1, 120, [
                ['duration_minutes' => 120, 'sequence' => 1],
                ['duration_minutes' => 180, 'sequence' => 1],
            ]],
        ];
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

    public function test_large_but_non_overflowing_values_are_accepted(): void
    {
        // Only genuine overflow is rejected — there is no arbitrary business cap.
        $this->assertSame(100000000000, $this->calc->calculate(1000000, 100000, [])['entitlement_minutes']);
    }

    public function test_entitlement_multiplication_overflow_is_detected(): void
    {
        $this->expectException(OverflowException::class);
        $this->calc->calculate(PHP_INT_MAX, 2, []);
    }

    public function test_occurrence_sum_overflow_is_detected(): void
    {
        $near = intdiv(PHP_INT_MAX, 2) + 10;
        $this->expectException(OverflowException::class);
        $this->calc->calculate(1, $near, [
            ['duration_minutes' => $near, 'sequence' => 1],
            ['duration_minutes' => $near, 'sequence' => 2],
        ]);
    }

    // ── lessonEquivalent() decimal-string helper ──────────────────────

    /**
     * The SAME 180-minute session is worth a different number of lesson-equivalents
     * depending on the course's own standard. This is the core product contract.
     *
     * @dataProvider lessonEquivalentProvider
     */
    public function test_lesson_equivalent(int $minutes, int $standard, string $expected): void
    {
        $this->assertSame($expected, $this->calc->lessonEquivalent($minutes, $standard));
    }

    /** @return array<string, array{0:int,1:int,2:string}> */
    public static function lessonEquivalentProvider(): array
    {
        return [
            '180min against a 120min standard = 1.50' => [180, 120, '1.50'],
            '180min against a 180min standard = 1.00' => [180, 180, '1.00'],
            '180min against a 90min standard = 2.00' => [180, 90, '2.00'],
            '180min against a 60min standard = 3.00' => [180, 60, '3.00'],
            '180min against a 45min standard = 4.00' => [180, 45, '4.00'],
            '180min against a 150min standard = 1.20' => [180, 150, '1.20'],
            'zero minutes' => [0, 120, '0.00'],
            'exactly one lesson' => [120, 120, '1.00'],
            'six and a half lessons' => [780, 120, '6.50'],
            'half a lesson' => [60, 120, '0.50'],
            'three quarters' => [90, 120, '0.75'],
            'rounds half-up to hundredths' => [65, 120, '0.54'],
            // Remainder rounding up to a whole unit must carry, not emit "0.100".
            'remainder carries into the whole part' => [999, 1000, '1.00'],
            'remainder carries above an existing whole' => [1999, 1000, '2.00'],
            // Divide-first keeps the scaling multiplication small even for huge inputs.
            'very large minute count does not overflow' => [PHP_INT_MAX - 800, 1000, '9223372036854775.01'],
        ];
    }

    public function test_lesson_equivalent_returns_a_string_never_a_float(): void
    {
        $this->assertIsString($this->calc->lessonEquivalent(780, 120));
    }

    public function test_lesson_equivalent_rejects_invalid_arguments(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->calc->lessonEquivalent(60, 0);
    }

    public function test_lesson_equivalent_rejects_negative_minutes(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->calc->lessonEquivalent(-1, 120);
    }

    public function test_lesson_equivalent_rejects_a_standard_large_enough_to_overflow_scaling(): void
    {
        $this->expectException(OverflowException::class);
        $this->calc->lessonEquivalent(1, PHP_INT_MAX);
    }
}
