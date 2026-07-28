<?php

namespace App\Services\Scheduling;

use Carbon\Carbon;

final class CommitmentOccurrenceExpander
{
        public function expand(
        array $slots,
        Carbon $from,
        Carbon $through,
        ?Carbon $contractStart = null,
        ?Carbon $contractEnd = null,
    ): array {
        if ($slots === []) {
            return [];
        }

        $from = $from->copy()->startOfDay();
        $through = $through->copy()->startOfDay();
        if ($through->lt($from)) {
            return [];
        }

        $byDow = [];
        foreach ($slots as $slot) {
            $byDow[(int) $slot['iso_weekday']] = $slot;
        }

        $out = [];
        $cursor = $from->copy();
        $guard = 0;
        while ($cursor->lte($through) && $guard < 400) {
            $guard++;
            $iso = (int) $cursor->dayOfWeekIso;
            if (isset($byDow[$iso])) {
                $dateStr = $cursor->toDateString();
                if ($contractStart && $cursor->lt($contractStart->copy()->startOfDay())) {
                    $cursor->addDay();
                    continue;
                }
                if ($contractEnd && $cursor->gt($contractEnd->copy()->startOfDay())) {
                    $cursor->addDay();
                    continue;
                }
                $slot = $byDow[$iso];
                $out[] = [
                    'date' => $dateStr,
                    'start_hm' => $slot['start_hm'],
                    'end_hm' => $slot['end_hm'] ?? null,
                    'iso_weekday' => $iso,
                ];
            }
            $cursor->addDay();
        }

        return $out;
    }
}
