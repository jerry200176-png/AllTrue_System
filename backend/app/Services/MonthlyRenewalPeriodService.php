<?php

namespace App\Services;

use App\Models\ClassSession;
use App\Models\StudentClass;
use Carbon\Carbon;

/** Shared renewal dates and historical-session safety; does not move or price sessions. */
final class MonthlyRenewalPeriodService
{
    public function inspect(StudentClass $course, ?string $end): array
    {
        $oldEnd = $course->getAttribute('EndDate') ? Carbon::parse($course->getAttribute('EndDate'))->toDateString() : null;
        $start = $oldEnd ? Carbon::parse($oldEnd)->addDay() : Carbon::today();
        $month = $start->copy()->startOfMonth();
        $day = max(1, min(31, (int) ($course->getAttribute('settlement_day') ?? 15)));
        $outside = ClassSession::query()->where('StudentClassID', $course->getKey())->whereIn('Status', ['attended', 'completed', 'late'])
            ->where(function ($query) use ($course, $oldEnd) {
                if ($oldEnd) $query->whereDate('SessionDate', '>', $oldEnd);
                if ($course->getAttribute('StartDate')) $query->orWhereDate('SessionDate', '<', Carbon::parse($course->getAttribute('StartDate'))->toDateString());
                if (!$oldEnd && !$course->getAttribute('StartDate')) $query->whereRaw('1 = 0');
            })->count();
        return ['start_date' => $start->toDateString(), 'billing_period' => $end ? $start->format('Y-m') : null,
            'due_date' => $end ? $month->day(min($day, $month->daysInMonth))->toDateString() : null,
            'blockers' => $outside ? [['code' => 'monthly_completed_sessions_outside_contract',
                'message' => "有 {$outside} 堂已上課落在合約日期外。請先到月結核對確認堂次與帳務歸屬，再建立新一期。"]] : []];
    }
}
