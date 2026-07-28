<?php

namespace App\Services\Scheduling;

use Carbon\Carbon;

/**
 * Expand contract ISO weekday+start slots into occurrence dates within [from, through] inclusive.
 * Pure function — no DB. Used by Phase 0 gap counts and Phase 1 Preview.
 */
final class CommitmentOccurrenceExpander
{
    /**
     * @param  list<array{iso_weekday:int,start_hm:string,end_hm?:?string}>  $slots
     * @return list<array{date:string,start_hm:string,end_hm:?string,iso_weekday:int}>
     */
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
