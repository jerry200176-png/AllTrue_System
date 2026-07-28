<?php

namespace App\Services\Scheduling;

use Carbon\Carbon;

final class HistoryCadenceInferrer
{
        public function infer(array $recentSessions): array
    {
        if (count($recentSessions) < 2) {
            return [
                'confirmed' => false,
                'dow' => null,
                'start_hm' => null,
                'end_hm' => null,
                'confidence' => '0/' . count($recentSessions),
                'iso_weekday' => null,
            ];
        }

        $tally = [];
        foreach (array_slice($recentSessions, 0, 6) as $row) {
            $date = is_array($row) ? ($row['SessionDate'] ?? null) : ($row->SessionDate ?? null);
            $start = is_array($row) ? ($row['StartTime'] ?? null) : ($row->StartTime ?? null);
            $end = is_array($row) ? ($row['EndTime'] ?? null) : ($row->EndTime ?? null);
            if ($date === null || $start === null) {
                continue;
            }
            $dow = Carbon::parse((string) $date)->dayOfWeek; // 0..6
            $startHm = substr((string) $start, 0, 5);
            $endHm = substr((string) ($end ?? ''), 0, 5);
            $key = "$dow|$startHm|$endHm";
            $tally[$key] = ($tally[$key] ?? 0) + 1;
        }

        if ($tally === []) {
            return [
                'confirmed' => false,
                'dow' => null,
                'start_hm' => null,
                'end_hm' => null,
                'confidence' => '0/0',
                'iso_weekday' => null,
            ];
        }

        arsort($tally);
        $topKey = array_key_first($tally);
        $topCount = $tally[$topKey];
        [$dow, $startHm, $endHm] = explode('|', $topKey);
        $dow = (int) $dow;
        // Carbon 0=Sun..6=Sat → ISO 1=Mon..7=Sun
        $iso = $dow === 0 ? 7 : $dow;

        return [
            'confirmed' => $topCount >= 2,
            'dow' => $dow,
            'start_hm' => $startHm !== '' ? $startHm : null,
            'end_hm' => $endHm !== '' ? $endHm : null,
            'confidence' => $topCount . '/' . min(6, count($recentSessions)),
            'iso_weekday' => $iso,
        ];
    }

        public function conflictsWithContract(array $contractSlots, array $history): bool
    {
        if (!$history['confirmed'] || $history['iso_weekday'] === null || $history['start_hm'] === null) {
            return false;
        }
        if ($contractSlots === []) {
            return false;
        }

        foreach ($contractSlots as $slot) {
            if ((int) $slot['iso_weekday'] === (int) $history['iso_weekday']
                && (string) $slot['start_hm'] === (string) $history['start_hm']) {
                return false;
            }
        }

        // Contract has slots, none match the confirmed history weekday+start.
        return true;
    }
}
