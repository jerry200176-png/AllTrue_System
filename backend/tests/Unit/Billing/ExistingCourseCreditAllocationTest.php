<?php

namespace Tests\Unit\Billing;

use App\Services\Billing\ExistingCourseCreditAllocation;
use Eris\{Generators, TestTrait};
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/** #5404 / in-app563: fund existing quantities, including already taught lessons. */
class ExistingCourseCreditAllocationTest extends TestCase
{
    use TestTrait;

    private function targets(int $a = 2, int $b = 3, int $price = 400): array
    {
        return [
            ['id' => 71, 'sessions' => $a, 'used' => $a, 'unit_price' => (string) $price, 'lesson_minutes' => 90, 'charge' => $a * $price],
            ['id' => 72, 'sessions' => $b, 'used' => intdiv($b, 2), 'unit_price' => (string) $price, 'lesson_minutes' => 90, 'charge' => $b * $price],
        ];
    }

    public function test_funding_already_taught_existing_courses_preserves_their_quantities_and_used_history(): void
    {
        $targets = $this->targets();
        $plan = ExistingCourseCreditAllocation::plan(12, 3, 9, '400', 90, $targets, range(101, 105), range(101, 109));
        self::assertSame([7, 3, 4, 2800], [$plan['source_sessions'], $plan['source_used'], $plan['source_remaining'], $plan['source_amount']]);
        self::assertSame([[71, 2, 2, 800], [72, 3, 1, 1200]], array_map(fn ($x) => [$x['id'], $x['sessions'], $x['used'], $x['amount']], $plan['allocations']));
        self::assertSame(range(101, 105), $plan['released_session_ids']);
        self::assertSame(4800, $plan['source_amount'] + array_sum(array_column($plan['allocations'], 'amount')));
        self::assertSame($this->targets(), $targets);
    }

    public function test_exact_fractional_unit_price_is_not_rounded(): void
    {
        $target = [['id' => 71, 'sessions' => 2, 'used' => 1, 'unit_price' => '12.50', 'lesson_minutes' => 90, 'charge' => 25]];
        $plan = ExistingCourseCreditAllocation::plan(4, 1, 3, '12.5', 90, $target, [101, 102], [101, 102, 103]);
        self::assertSame([25, 25], [$plan['source_amount'], $plan['allocations'][0]['amount']]);
    }

    public function test_missing_explicit_reservation_release_cannot_create_spendable_capacity(): void
    {
        $this->expectException(InvalidArgumentException::class);
        ExistingCourseCreditAllocation::plan(12, 3, 9, '400', 90, $this->targets(), [], range(101, 109));
    }

    public function test_a_release_outside_the_locked_eligible_set_is_rejected(): void
    {
        $this->expectException(InvalidArgumentException::class);
        ExistingCourseCreditAllocation::plan(12, 3, 9, '400', 90, $this->targets(), range(201, 205), range(101, 109));
    }

    public function test_a_duplicate_destination_cannot_be_funded_twice(): void
    {
        $targets = $this->targets(); $targets[1]['id'] = 71;
        $this->expectException(InvalidArgumentException::class);
        ExistingCourseCreditAllocation::plan(12, 3, 9, '400', 90, $targets, range(101, 105), range(101, 109));
    }

    public static function incompatibleTargetTerms(): array
    {
        return [
            'different price' => [['unit_price' => '401']],
            'different duration' => [['lesson_minutes' => 120]],
            'contract amount differs' => [['charge' => 801]],
            'used exceeds purchased' => [['used' => 3]],
            'source cannot fund quantity' => [['sessions' => 10, 'charge' => 4000]],
        ];
    }

    #[DataProvider('incompatibleTargetTerms')]
    public function test_incompatible_terms_fail_before_an_allocation_is_returned(array $overrides): void
    {
        $targets = $this->targets(); $targets[0] = array_replace($targets[0], $overrides);
        $this->expectException(InvalidArgumentException::class);
        ExistingCourseCreditAllocation::plan(12, 3, 9, '400', 90, $targets, range(101, 105), range(101, 109));
    }

    public function test_extra_releases_are_not_allowed_when_the_source_already_has_free_capacity(): void
    {
        $this->expectException(InvalidArgumentException::class);
        ExistingCourseCreditAllocation::plan(12, 3, 2, '400', 90, $this->targets(), [101], [101, 102]);
    }

    public function test_generated_allocations_conserve_paid_units_value_and_taught_history_without_overbooking(): void
    {
        $this->limitTo(100)->forAll(Generators::choose(1, 20), Generators::choose(1, 20),
            Generators::choose(0, 20), Generators::choose(0, 20), Generators::choose(1, 10000), Generators::choose(0, 100))
            ->then(function (int $a, int $b, int $used, int $extra, int $price, int $percent): void {
                $purchased = $used + $a + $b + $extra;
                $reserved = intdiv(($a + $b + $extra) * $percent, 100);
                $eligible = $reserved > 0 ? range(1001, 1000 + $reserved) : [];
                $release = array_slice($eligible, 0, max(0, $reserved - $extra));
                $targets = $this->targets($a, $b, $price);
                $plan = ExistingCourseCreditAllocation::plan($purchased, $used, $reserved, (string) $price, 90, $targets, $release, $eligible);
                self::assertSame($purchased, $plan['source_sessions'] + array_sum(array_column($plan['allocations'], 'sessions')));
                self::assertSame($purchased * $price, $plan['source_amount'] + array_sum(array_column($plan['allocations'], 'amount')));
                self::assertSame($used, $plan['source_used']);
                self::assertSame([$a, intdiv($b, 2)], array_column($plan['allocations'], 'used'));
                $byId = array_column($plan['allocations'], null, 'id');
                foreach ($targets as $target) {
                    self::assertArrayHasKey($target['id'], $byId);
                    self::assertSame([$target['sessions'], $target['used'], $target['charge']],
                        [$byId[$target['id']]['sessions'], $byId[$target['id']]['used'], $byId[$target['id']]['amount']]);
                }
                self::assertGreaterThanOrEqual($reserved - count($release), $plan['source_remaining']);
                self::assertSame($this->targets($a, $b, $price), $targets);
                self::assertSame($plan, ExistingCourseCreditAllocation::plan($purchased, $used, $reserved, (string) $price, 90,
                    array_reverse($targets), array_reverse($release), array_reverse($eligible)));
            });
    }
}
