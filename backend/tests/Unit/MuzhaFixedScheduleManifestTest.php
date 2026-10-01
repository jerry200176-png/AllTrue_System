<?php

namespace Tests\Unit;

use App\Operations\Strategies\MuzhaFixedScheduleManifest;
use PHPUnit\Framework\TestCase;

final class MuzhaFixedScheduleManifestTest extends TestCase
{
    public function test_production_observed_103_exceptions_and_full_mutation_counts(): void
    {
        $cases = MuzhaFixedScheduleManifest::cases();
        self::assertSame([3428, 3429, 2332, 2335], array_keys($cases));
        self::assertSame(['2026-10-03', '17:00', 'scheduled', 1], $cases[2332]['rows'][19267]);
        self::assertSame(['2026-10-03', '17:00', 'scheduled', 1], $cases[2335]['rows'][19324]);
        self::assertSame('10:00', $cases[3428]['new']);
        self::assertSame('10:00', $cases[3429]['new']);
        self::assertSame('15:00', $cases[2332]['new']);
        self::assertSame('15:00', $cases[2335]['new']);
        $rows = array_merge(...array_map(static fn (array $case): array => array_values($case['rows']), $cases));
        self::assertCount(32, $rows);
        self::assertCount(8, array_filter($rows, static fn (array $row): bool => $row[2] === 'cancelled'));
        $timeUpdates = 0;
        $adopted = 0;
        foreach ($cases as $case) {
            foreach ($case['rows'] as [$date, $start, $status, $exception]) {
                if ($status !== 'scheduled') continue;
                if ($start !== $case['new']) $timeUpdates++;
                elseif ($exception) $adopted++;
            }
        }
        self::assertSame(20, $timeUpdates);
        self::assertSame(4, $adopted);
    }
}
