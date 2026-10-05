<?php

namespace App\Services\Scheduling;

use App\Models\ClassSession;
use App\Models\LearningRecord;
use App\Models\ScheduleAuditLog;
use App\Models\StudentClass;
use App\Models\StudentSignIn;
use App\Services\ClassSessionContractReflowService;
use App\Services\ClassSessionMaterializationService;
use App\Services\ManualSessionBookingService;
use App\Services\SessionDeductionService;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Contract schedule -> ClassSession rows (extracted from StudentClassController, ADR-003 / #966).
 *
 * Behaviour-preserving move, sliced across several PRs. Pure helpers are static;
 * DB-writing reconcilers are instance methods.
 */
class ContractSessionSchedule
{
    public static function normalizeDateString($value): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }
        try {
            return Carbon::parse($value)->toDateString();
        } catch (\Throwable $e) {
            return null;
        }
    }

    /**
     * Normalize a weekday value to ISO-8601 (1=Mon … 7=Sun).
     * Accepts both ISO 1-7 and legacy JS 0-6 (0=Sunday → 7).
     */
    public static function isoWeekday($weekday): int
    {
        $weekday = (int) $weekday;

        return $weekday === 0 ? 7 : $weekday;
    }

    public static function normalizeSessionTime($value, string $fallback = '16:00:00'): string
    {
        $raw = trim((string) ($value ?? ''));
        if ($raw === '') {
            $raw = $fallback;
        }
        try {
            if (preg_match('/^\d{1,2}:\d{2}$/', $raw)) {
                return Carbon::createFromFormat('H:i', $raw)->format('H:i:s');
            }
            if (preg_match('/^\d{1,2}:\d{2}:\d{2}$/', $raw)) {
                return Carbon::createFromFormat('H:i:s', $raw)->format('H:i:s');
            }
            return Carbon::parse($raw)->format('H:i:s');
        } catch (\Throwable $e) {
            try {
                return Carbon::parse($fallback)->format('H:i:s');
            } catch (\Throwable $ignore) {
                return '16:00:00';
            }
        }
    }

    public static function sessionEndedByEndTime(string $sessionDate, string $endTime, ?Carbon $now = null): bool
    {
        $now = $now ?: Carbon::now();
        $sessionEndAt = Carbon::parse($sessionDate . ' ' . $endTime);
        return $sessionEndAt->lte($now);
    }

    /**
     * Dates with a cancelled session. A cancelled row beside a live row in the same slot (date + start) is a
     * duplicate/placeholder (e.g. reschedule collision), not a cancelled lesson.
     *
     * @return array<string, bool>
     */
    public static function cancelledDateSet(iterable $sessionRows): array
    {
        $cancelledSlots = [];
        $liveSlots = [];
        foreach ($sessionRows as $row) {
            if (!$row->SessionDate) {
                continue;
            }
            $slot = Carbon::parse($row->SessionDate)->toDateString() . '|' . substr((string) ($row->StartTime ?? ''), 0, 5);
            if (strtolower((string) ($row->Status ?? '')) === 'cancelled') {
                $cancelledSlots[$slot] = true;
            } else {
                $liveSlots[$slot] = true;
            }
        }
        $set = [];
        foreach (array_keys(array_diff_key($cancelledSlots, $liveSlots)) as $slot) {
            $set[strstr($slot, '|', true)] = true;
        }

        return $set;
    }

    /**
     * Contract-wide cancelled dates per class (any date, one query), for count-mode recurrence walks.
     *
     * @param  list<int>  $classIds
     * @return array<int, array<string, bool>>
     */
    public static function cancelledDatesByClass(array $classIds): array
    {
        if ($classIds === []) {
            return [];
        }
        $rows = ClassSession::query()
            ->whereIn('StudentClassID', $classIds)
            ->whereExists(fn ($q) => $q->selectRaw('1')->from('ClassSession as c2')
                ->whereColumn('c2.StudentClassID', 'ClassSession.StudentClassID')
                ->whereColumn('c2.SessionDate', 'ClassSession.SessionDate')
                ->whereRaw('LOWER(c2.Status) = ?', ['cancelled']))
            ->get(['StudentClassID', 'SessionDate', 'StartTime', 'Status']);

        $out = [];
        foreach ($rows->groupBy('StudentClassID') as $classId => $classRows) {
            $out[(int) $classId] = self::cancelledDateSet($classRows);
        }

        return $out;
    }

    /**
     * 堂數制：從第一堂日開始，依排課星期與請假/調課/加課，算出恰好 N 堂的有效日期（請假會讓結束日往後推）。
     */
    public static function computeEffectiveSessionDates(string $startDate, int $n, array $daysOfWeek, array $leaveSet, array $scheduledSet, array $cancelledSet = []): array
    {
        $list = [];
        $d = Carbon::parse($startDate . ' 12:00:00');
        $end = $d->copy()->addYears(2);
        while ($d <= $end && count($list) < $n) {
            $ymd = $d->toDateString();
            $dow = $d->dayOfWeekIso;
            $isRegular = in_array($dow, $daysOfWeek, true);
            $isCancelled = isset($cancelledSet[$ymd]);
            $isLeave = isset($leaveSet[$ymd]) || $isCancelled;
            $isScheduledExtra = isset($scheduledSet[$ymd]);

            if ($isRegular && !$isLeave) {
                $list[] = $ymd;
            } elseif ($isScheduledExtra && !$isRegular && !$isCancelled) {
                // A same-day leave marker beside a schedule-only make-up keeps the make-up (R13/R114);
                // only a cancelled ClassSession on that date removes it.
                $list[] = $ymd;
            }
            $d->addDay();
        }
        return array_slice($list, 0, $n);
    }

    public static function buildSessionsFromWeeklySchedule(
        int $studentClassId,
        string $startDate,
        string $endDate,
        array $slots,
        int $durationMinutes,
        bool $includeStartDate = false
    ): array {
        $sessions = [];
        $start = Carbon::parse($startDate)->startOfDay();
        $end = Carbon::parse($endDate)->endOfDay();

        for ($date = $start->copy(); $date->lte($end); $date->addDay()) {
            $matchingSlots = array_values(array_filter($slots, function ($slot) use ($date) {
                // Slots arrive in two conventions: ISO 1-7 (DB week columns, day_time_slots)
                // and legacy JS 0-6 (ScheduleSlots param). Both agree on Mon-Sat (1-6);
                // Sunday is 7 (ISO) or 0 (JS). Comparing raw dayOfWeek (0-6) silently
                // dropped every ISO-Sunday slot (GitHub #1096: 0-amount monthly invoices).
                return (int) $date->dayOfWeekIso === self::isoWeekday($slot['weekday']);
            }));

            // Monthly courses have an explicit opening date that is the first lesson,
            // even when recurrence starts on a different fixed weekday. Keep the
            // default false so date-based imports/rebuilds retain their old contract.
            if ($includeStartDate && $date->isSameDay($start) && empty($matchingSlots) && !empty($slots)) {
                $matchingSlots = [$slots[0]];
            }

            foreach ($matchingSlots as $slot) {
                    $startTime = Carbon::parse($date->toDateString() . ' ' . $slot['time']);
                    $slotDur = !empty($slot['duration_minutes']) ? (int) $slot['duration_minutes'] : $durationMinutes;
                    $endTime = $startTime->copy()->addMinutes($slotDur);

                    $sessions[] = [
                        'StudentClassID' => $studentClassId,
                        'SessionDate' => $startTime->toDateString(),
                        'StartTime' => $startTime->format('H:i:s'),
                        'EndTime' => $endTime->format('H:i:s'),
                        'Status' => 'scheduled',
                        'Note' => '',
                        'created_at' => now(),
                        'updated_at' => now(),
                    ];
            }
        }

        return $sessions;
    }

    /**
     * 堂數制：排定共 sessionCount 堂。
     * 第 1 堂固定為「首堂日」startDate（不論星期幾），使用該日匹配的所有時段；
     * 第 2～N 堂從 startDate 的隔天起，依序取「星期符合 slots」的日期。
     * 支援同日多時段（如週六 13:00 + 17:00 各算一堂）。
     */
    public static function buildSessionsForCount(
        int $studentClassId,
        string $startDate,
        int $sessionCount,
        array $slots,
        int $durationMinutes
    ): array {
        $sessions = [];
        if ($sessionCount < 1 || empty($slots)) {
            return $sessions;
        }

        $slotsByWeekday = [];
        foreach ($slots as $s) {
            $wd = (int) ($s['weekday'] ?? 0);
            if ($wd < 1 || $wd > 7) {
                continue;
            }
            $slotsByWeekday[$wd][] = $s;
        }
        foreach ($slotsByWeekday as &$group) {
            usort($group, fn ($a, $b) => strcmp((string) ($a['time'] ?? ''), (string) ($b['time'] ?? '')));
        }
        unset($group);

        $slotWeekdays = array_keys($slotsByWeekday);
        $firstSlot = $slots[0];
        $firstTime = $firstSlot['time'] ?? '16:00';

        $appendSessionsForDate = function (Carbon $dateObj) use (
            $studentClassId, $durationMinutes, $slotsByWeekday,
            $firstTime, $sessionCount, &$sessions
        ) {
            $isoDow = (int) $dateObj->dayOfWeekIso;
            $daySlots = $slotsByWeekday[$isoDow] ?? [['time' => $firstTime]];
            foreach ($daySlots as $slot) {
                if (count($sessions) >= $sessionCount) {
                    return;
                }
                $time = $slot['time'] ?? $firstTime;
                $dur = (!empty($slot['duration_minutes']) && (int) $slot['duration_minutes'] >= 30)
                    ? (int) $slot['duration_minutes']
                    : $durationMinutes;
                $start = Carbon::parse($dateObj->toDateString() . ' ' . $time);
                $end = $start->copy()->addMinutes($dur);
                $sessions[] = [
                    'StudentClassID' => $studentClassId,
                    'SessionDate' => $start->toDateString(),
                    'StartTime' => $start->format('H:i:s'),
                    'EndTime' => $end->format('H:i:s'),
                    'Status' => 'scheduled',
                    'Note' => '',
                    'created_at' => now(),
                    'updated_at' => now(),
                ];
            }
        };

        $firstDate = Carbon::parse($startDate)->startOfDay();
        $appendSessionsForDate($firstDate);

        $date = Carbon::parse($startDate)->addDay()->startOfDay();
        $maxDate = Carbon::parse($startDate)->addYears(2);
        while (count($sessions) < $sessionCount && $date->lte($maxDate)) {
            $isoDow = (int) $date->dayOfWeekIso;
            if (isset($slotsByWeekday[$isoDow])) {
                $appendSessionsForDate($date);
            }
            $date->addDay();
        }

        return $sessions;
    }
}
