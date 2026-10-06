<?php

namespace App\Services;

use App\Models\ClassSession;
use App\Models\StudentClass;
use App\Services\Scheduling\ContractSessionSchedule;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;

/** Shared renewal dates and historical-session safety; does not move or price sessions. */
final class MonthlyRenewalPeriodService
{
    public function inspect(StudentClass $course, ?string $end): array
    {
        $oldEnd = $course->getAttribute('EndDate') ? Carbon::parse($course->getAttribute('EndDate'))->toDateString() : null;
        $start = $oldEnd ? Carbon::parse($oldEnd)->addDay() : Carbon::today();
        $month = $start->copy()->startOfMonth();
        $day = max(1, min(31, (int) ($course->getAttribute('settlement_day') ?? 15)));
        $outsideDates = ClassSession::query()->where('StudentClassID', $course->getKey())->whereIn('Status', ['attended', 'completed', 'late'])
            ->where(function ($query) use ($course, $oldEnd) {
                if ($oldEnd) $query->whereDate('SessionDate', '>', $oldEnd);
                if ($course->getAttribute('StartDate')) $query->orWhereDate('SessionDate', '<', Carbon::parse($course->getAttribute('StartDate'))->toDateString());
                if (!$oldEnd && !$course->getAttribute('StartDate')) $query->whereRaw('1 = 0');
            })->pluck('SessionDate')->map(fn ($d) => Carbon::parse($d)->format('Y-m'));
        // A month already billed by a catch-up (same student + subject, non-void invoice for that billing_period)
        // is not a blocker: the lessons stay on this contract but their money lives on the catch-up invoice.
        $billedMonths = $outsideDates->isEmpty() ? collect() : DB::table('Invoice as inv')
            ->join('StudentClass as sc', 'sc.ID', '=', 'inv.StudentClassID')
            ->where('sc.StudentID', $course->getAttribute('StudentID'))->where('sc.SubjectID', $course->getAttribute('SubjectID'))
            ->whereIn('inv.billing_period', $outsideDates->unique()->values()->all())
            ->where(fn ($q) => $q->whereNull('inv.Status')->orWhere('inv.Status', '<>', 'void'))
            ->pluck('inv.billing_period')->unique();
        $outside = $outsideDates->reject(fn ($m) => $billedMonths->contains($m))->count();
        return ['start_date' => $start->toDateString(), 'billing_period' => $end ? $start->format('Y-m') : null,
            'due_date' => $end ? $month->day(min($day, $month->daysInMonth))->toDateString() : null,
            'blockers' => $outside ? [['code' => 'monthly_completed_sessions_outside_contract',
                'message' => "有 {$outside} 堂已上課落在合約日期外。請先到月結核對確認堂次與帳務歸屬，再建立新一期。"]] : []];
    }

    public static function chargeFromRate(float $rate, string $rateUnit, int $sessionCount, int $totalHours): int
    {
        if ($rate <= 0) {
            return 0;
        }
        return (int) round($rateUnit === 'hour' ? $rate * max(0, $totalHours) : $rate * max(0, $sessionCount));
    }

    public function findDuplicate(StudentClass $course, string $start, string $end): ?StudentClass
    {
        return StudentClass::query()->where('ID', '<>', $course->getKey())
            ->where('StudentID', $course->getAttribute('StudentID'))
            ->where('SubjectID', $course->getAttribute('SubjectID'))
            ->where('ScheduleMode', 'date')
            ->whereDate('StartDate', $start)
            ->whereDate('EndDate', $end)
            ->where(fn ($q) => $q->whereNull('Stop')->orWhere('Stop', 0))
            ->orderBy('ID')
            ->first();
    }

    /** Sessions, hours and charge BEFORE discount for one renewal period; shared by renewMonthly and the drafts preview. */
    public function previewPeriod(StudentClass $course, string $start, string $end): array
    {
        $rate = (float) ($course->getAttribute('Rate') ?? 0);
        $rateUnit = strtolower(trim((string) ($course->getAttribute('rate_unit') ?? 'session')));
        if (!in_array($rateUnit, ['session', 'hour'], true)) {
            $rateUnit = 'session';
        }
        $dur = max(30, (int) ($course->getAttribute('SessionDuration') ?? 120));
        $slots = ContractSessionSchedule::resolveScheduleSlotsForRebuild($course);
        $built = !empty($slots)
            ? ContractSessionSchedule::buildSessionsFromWeeklySchedule((int) $course->getKey(), $start, $end, $slots, $dur)
            : [];
        $count = count($built);
        if ($count <= 0) {
            $count = max(0, (int) ($course->getAttribute('monthly_sessions') ?? 0));
        }
        $hours = !empty($built)
            ? (int) round(array_reduce($built, function ($carry, $session) {
                $s = substr((string) ($session['StartTime'] ?? ''), 0, 5);
                $e = substr((string) ($session['EndTime'] ?? ''), 0, 5);
                if ($s === '' || $e === '') {
                    return $carry;
                }
                return $carry + max(0, ((int) substr($e, 0, 2)) * 60 + (int) substr($e, 3, 2) - ((int) substr($s, 0, 2)) * 60 - (int) substr($s, 3, 2));
            }, 0) / 60)
            : (int) round(($count * $dur) / 60);
        $charge = self::chargeFromRate($rate, $rateUnit, $count, $hours);
        if ($charge <= 0) {
            $charge = max(0, (int) ($course->getAttribute('Charge') ?? 0));
        }
        return ['rate' => $rate, 'rate_unit' => $rateUnit, 'session_duration' => $dur, 'sessions' => $count, 'hours' => $hours, 'charge' => $charge];
    }
}
