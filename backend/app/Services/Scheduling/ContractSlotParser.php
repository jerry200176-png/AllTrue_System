<?php

namespace App\Services\Scheduling;

final class ContractSlotParser
{
        private const PAIRS = [
        ['week', 'time'],
        ['week1', 'time1'],
        ['week2', 'time2'],
        ['week3', 'time3'],
        ['week4', 'time4'],
        ['week5', 'time5'],
        ['week6', 'time6'],
    ];

        public function parse(object|array $course): array
    {
        $get = static function (object|array $c, string $key): mixed {
            if (is_array($c)) {
                return $c[$key] ?? null;
            }

            return $c->{$key} ?? null;
        };

        $byWeekday = [];
        $partial = false;
        $slots = [];

        foreach (self::PAIRS as [$weekField, $timeField]) {
            $rawWeek = $get($course, $weekField);
            $rawTime = trim((string) ($get($course, $timeField) ?? ''));
            $hasWeek = $rawWeek !== null && $rawWeek !== '' && (int) $rawWeek !== 0;
            $hasTime = $rawTime !== '';

            if (!$hasWeek && !$hasTime) {
                continue;
            }
            if ($hasWeek xor $hasTime) {
                $partial = true;
                continue;
            }

            $iso = self::isoWeekday((int) $rawWeek);
            if ($iso < 1 || $iso > 7) {
                $partial = true;
                continue;
            }

            $startHm = self::normalizeHm($rawTime);
            if ($startHm === null) {
                $partial = true;
                continue;
            }

            $endHm = null;
            $duration = (int) ($get($course, 'SessionDuration') ?? 0);
            if ($duration >= 30) {
                $endHm = self::addMinutes($startHm, $duration);
            }

            $slot = [
                'iso_weekday' => $iso,
                'start_hm' => $startHm,
                'end_hm' => $endHm,
                'source' => $weekField,
            ];
            $slots[] = $slot;

            $key = $iso . '|' . $startHm;
            if (!isset($byWeekday[$iso])) {
                $byWeekday[$iso] = $startHm;
            } elseif ($byWeekday[$iso] !== $startHm) {
                // Same weekday, different start times across week fields → conflict.
                return [
                    'slots' => $slots,
                    'unique' => false,
                    'conflicting' => true,
                    'partial' => $partial,
                ];
            }
        }

        // Deduplicate identical weekday+start.
        $uniqueMap = [];
        foreach ($slots as $slot) {
            $uniqueMap[$slot['iso_weekday'] . '|' . $slot['start_hm']] = $slot;
        }
        $uniqueSlots = array_values($uniqueMap);

        return [
            'slots' => $uniqueSlots,
            'unique' => count($uniqueSlots) >= 1 && !$partial,
            'conflicting' => false,
            'partial' => $partial,
        ];
    }

    public static function isoWeekday(int $weekday): int
    {
        return $weekday === 0 ? 7 : $weekday;
    }

    public static function normalizeHm(string $time): ?string
    {
        $time = trim($time);
        if ($time === '') {
            return null;
        }
        if (preg_match('/^(\d{1,2}):(\d{2})(?::\d{2})?$/', $time, $m)) {
            $h = (int) $m[1];
            $min = (int) $m[2];
            if ($h < 0 || $h > 23 || $min < 0 || $min > 59) {
                return null;
            }

            return sprintf('%02d:%02d', $h, $min);
        }

        return null;
    }

    public static function addMinutes(string $startHm, int $minutes): string
    {
        [$h, $m] = array_map('intval', explode(':', $startHm));
        $total = $h * 60 + $m + max(0, $minutes);
        $total = $total % (24 * 60);

        return sprintf('%02d:%02d', intdiv($total, 60), $total % 60);
    }
}
