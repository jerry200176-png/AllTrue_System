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
}
