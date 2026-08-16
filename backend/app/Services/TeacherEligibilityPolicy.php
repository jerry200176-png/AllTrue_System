<?php

namespace App\Services;

use Carbon\Carbon;

/**
 * Pure policy engine for the 115.07 full-time teacher salary rules.
 * Database adapters normalize source records into the small input shapes used here.
 */
class TeacherEligibilityPolicy
{
    public const QUALIFIES = 'qualifies';
    public const NOT_QUALIFIES = 'not_qualifies';
    public const REVIEW = 'review';

    private array $settings;

    public function __construct(?array $settings = null)
    {
        if ($settings !== null) {
            $this->settings = $settings;
            return;
        }

        try {
            $this->settings = (array) config('teacher_salary', []);
        } catch (\Throwable) {
            $this->settings = [];
        }
    }

    public function evaluate(array $input): array
    {
        $components = [
            'weekly_16_segments' => $this->weekly16($input['weekly'] ?? null),
            'holiday_16_hours' => $this->holiday16($input['holiday_days'] ?? null),
            'weekday_afternoon' => $this->weekdayAfternoon($input['weekday_hours'] ?? []),
            'special_performance' => $this->specialPerformance($input['achievements'] ?? [], $input['period_start'] ?? null, $input['period_end'] ?? null),
            'deductions' => $this->deductions($input['deductions'] ?? [], $input['period_start'] ?? null, $input['period_end'] ?? null),
            'subject_count_bonus' => $this->subjectCountBonus(
                array_key_exists('subject_count', $input) && $input['subject_count'] !== null
                    ? (float) $input['subject_count']
                    : null
            ),
        ];

        $missing = [];
        foreach ($components as $component) {
            $missing = array_merge($missing, $component['missing_fields'] ?? []);
        }

        $positive = ['weekly_16_segments', 'holiday_16_hours', 'weekday_afternoon', 'special_performance'];
        $hasReview = collect($components)->contains(fn ($component) => $component['status'] === self::REVIEW);
        $hasBenefit = collect($positive)->contains(fn ($key) => ($components[$key]['rate'] ?? 0) > 0 || ($components[$key]['amount'] ?? 0) > 0);

        return [
            'overall_status' => $hasReview ? self::REVIEW : ($hasBenefit ? self::QUALIFIES : self::NOT_QUALIFIES),
            'components' => $components,
            'missing_fields' => array_values(array_unique($missing)),
        ];
    }

    public function weekly16(?array $weekly): array
    {
        if ($weekly === null) {
            return $this->review(['weekly_segments', 'work_hours', 'weekly_exception_context'], '缺少每週課段、工時或例外資料。');
        }

        $segments = $weekly['segments'] ?? null;
        $workHours = $weekly['work_hours'] ?? null;
        $exception = $weekly['exception'] ?? null;
        $exceptionIncomplete = $exception !== null && (
            (array_key_exists('official_event', $exception) && $exception['official_event'] === null)
            || (array_key_exists('leave_eligible', $exception) && $exception['leave_eligible'] === null)
        );
        if ($exceptionIncomplete) {
            return $this->review(['weekly_exception_context'], '請假或官方活動例外資料尚未完整。');
        }
        if ($exception !== null && !empty($exception['saturday_leave_blocked'])) {
            return $this->result(self::NOT_QUALIFIES, '週六請假且週日無補課或可抵扣假日假。');
        }
        if (($segments === null || $workHours === null) && $exception !== null && (!empty($exception['official_event']) || !empty($exception['leave_eligible']))) {
            return $this->result(self::QUALIFIES, !empty($exception['official_event']) ? '官方活動或統一公休例外。' : '請假抵扣或補課符合例外規則。', [
                'weekly_segments' => $segments === null ? null : round((float) $segments, 2),
                'work_hours' => $workHours === null ? null : round((float) $workHours, 2),
            ], $this->setting('weekly_segment_bonus', 1000), 0);
        }
        if ($segments === null || $workHours === null) {
            return $this->review(array_filter([
                $segments === null ? 'weekly_segments' : null,
                $workHours === null ? 'work_hours' : null,
            ]), '缺少每週課段或實際工時。');
        }

        $thresholdPass = (float) $segments >= (float) $this->setting('weekly_segment_threshold', 16)
            && (float) $workHours >= (float) $this->setting('weekly_work_hours_threshold', 40);
        if ($thresholdPass) {
            return $this->result(self::QUALIFIES, '達到每週16段課及40小時工時。', [
                'weekly_segments' => round((float) $segments, 2),
                'work_hours' => round((float) $workHours, 2),
            ], $this->setting('weekly_segment_bonus', 1000), 0);
        }
        if ($exception === null) {
            return $this->review(['weekly_exception_context'], '未達一般門檻，且缺少官方活動／公休／請假例外資料。');
        }
        if (!empty($exception['official_event']) || !empty($exception['leave_eligible'])) {
            return $this->result(self::QUALIFIES, !empty($exception['official_event']) ? '官方活動或統一公休例外。' : '請假抵扣或補課符合例外規則。', [
                'weekly_segments' => round((float) $segments, 2),
                'work_hours' => round((float) $workHours, 2),
            ], $this->setting('weekly_segment_bonus', 1000), 0);
        }

        return $this->result(self::NOT_QUALIFIES, '未達每週16段課及40小時工時，且不符合例外規則。', [
            'weekly_segments' => round((float) $segments, 2),
            'work_hours' => round((float) $workHours, 2),
        ], 0, 0);
    }

    public function holiday16(?array $holidayDays): array
    {
        if ($holidayDays === null) {
            return $this->review(['holiday_calendar'], '缺少假日曆資料。');
        }
        if ($holidayDays === []) {
            return $this->result(self::QUALIFIES, '查詢期間沒有需計算的假日。', ['holiday_count' => 0], 0, 0);
        }

        $missing = [];
        foreach ($holidayDays as $index => $day) {
            foreach (['date', 'regular_scheduled_hours'] as $field) {
                if (!array_key_exists($field, $day) || $day[$field] === null) {
                    $missing[] = "holiday_days.{$index}.{$field}";
                }
            }
        }
        if ($missing !== []) {
            return $this->review($missing, '缺少假日出勤或假日假抵扣時數。');
        }

        $required = (float) $this->setting('holiday_required_hours', 16);
        $qualified = collect($holidayDays)->every(fn ($day) => (float) $day['regular_scheduled_hours'] >= $required);
        $metrics = [
            'holiday_count' => count($holidayDays),
            'required_hours' => $required,
            'regular_scheduled_hours' => collect($holidayDays)->mapWithKeys(fn ($day) => [
                (string) $day['date'] => round((float) $day['regular_scheduled_hours'], 2),
            ])->all(),
            'worked_hours' => collect($holidayDays)->mapWithKeys(fn ($day) => [
                (string) $day['date'] => array_key_exists('worked_hours', $day) && $day['worked_hours'] !== null
                    ? round((float) $day['worked_hours'], 2)
                    : null,
            ])->all(),
            'holiday_leave_hours' => collect($holidayDays)->mapWithKeys(fn ($day) => [
                (string) $day['date'] => array_key_exists('holiday_leave_hours', $day) && $day['holiday_leave_hours'] !== null
                    ? round((float) $day['holiday_leave_hours'], 2)
                    : null,
            ])->all(),
            'holiday_leave_effect' => 'neutral_not_added_to_multiplier',
        ];
        return $this->result(
            $qualified ? self::QUALIFIES : self::NOT_QUALIFIES,
            $qualified
                ? '每個假日常態排課均達16小時；假日假不扣除假日倍率。'
                : '至少一個假日常態排課未達16小時；假日假不能創造假日倍率。',
            $metrics,
            0,
            $qualified ? (float) $this->setting('holiday_multiplier_pass', 10) : 0
        );
    }

    public function weekdayAfternoon(array $weekdayHours): array
    {
        $missing = [];
        $extraSegments = 0.0;
        foreach ($weekdayHours as $date => $hours) {
            if ($hours === null) {
                $missing[] = "weekday_hours.{$date}";
                continue;
            }
            $extraSegments += max(0, ((float) $hours - 4) / 2);
        }
        if ($missing !== []) {
            return $this->review($missing, '缺少平日課程時數。');
        }

        $rate = min(
            (float) $this->setting('weekday_rate_cap', 5),
            $extraSegments * (float) $this->setting('weekday_extra_rate_per_segment', 1)
        );
        return $this->result(
            self::QUALIFIES,
            $rate > 0 ? '平日每日4小時低消後有可計算課段。' : '平日未超過每日4小時低消。',
            [
                'extra_segments' => round($extraSegments, 2),
                'rate_cap' => 5,
                'daily_coverage_hours' => array_map(fn ($hours) => round((float) $hours, 2), $weekdayHours),
            ],
            0,
            round($rate, 2)
        );
    }

    public function specialPerformance(array $achievements, ?string $periodStart, ?string $periodEnd): array
    {
        $start = $periodStart ? Carbon::parse($periodStart)->startOfDay() : null;
        $end = $periodEnd ? Carbon::parse($periodEnd)->endOfDay() : null;
        $pending = [];
        $verified = [];

        foreach ($achievements as $achievement) {
            $status = (string) ($achievement['status'] ?? 'pending');
            if ($status === 'withdrawn') {
                continue;
            }
            if ($status !== 'verified') {
                $pending[] = $achievement['outcome_key'] ?? 'achievement';
                continue;
            }
            if (empty($achievement['evidence'])) {
                $pending[] = $achievement['outcome_key'] ?? 'achievement';
                continue;
            }
            $outcomeKey = (string) ($achievement['outcome_key'] ?? 'achievement');
            if (empty($achievement['starts_on']) || empty($achievement['ends_on'])) {
                $pending[] = $outcomeKey;
                continue;
            }
            try {
                $achievementStart = Carbon::parse($achievement['starts_on'])->startOfDay();
                $achievementEnd = Carbon::parse($achievement['ends_on'])->endOfDay();
            } catch (\Throwable) {
                $pending[] = $outcomeKey;
                continue;
            }
            if ($achievementEnd->lt($achievementStart)) {
                $pending[] = $outcomeKey;
                continue;
            }
            $activeMonths = $achievementStart->copy()->startOfMonth()->diffInMonths($achievementEnd->copy()->startOfMonth()) + 1;
            $maxMonths = $outcomeKey === 'employee_of_year'
                ? 12
                : (int) $this->setting('student_achievement_max_months', 3);
            if ($activeMonths > $maxMonths) {
                $pending[] = $outcomeKey;
                continue;
            }
            if ($outcomeKey === 'employee_of_year' && $achievementStart->year < (int) $this->setting('employee_of_year_start_year', 2027)) {
                continue;
            }
            if ($start && !empty($achievement['starts_on']) && Carbon::parse($achievement['starts_on'])->gt($end)) {
                continue;
            }
            if ($end && !empty($achievement['ends_on']) && Carbon::parse($achievement['ends_on'])->lt($start)) {
                continue;
            }
            if ($outcomeKey === 'employee_of_year') {
                $awardYear = isset($achievement['award_year']) ? (int) $achievement['award_year'] : null;
                if ($awardYear === null) {
                    $pending[] = $outcomeKey;
                    continue;
                }
                if ($awardYear < (int) $this->setting('employee_of_year_start_year', 2027)) {
                    continue;
                }
                if ($achievementStart->year !== $awardYear + 1) {
                    $pending[] = $outcomeKey;
                    continue;
                }
            }
            $verified[] = $achievement;
        }

        if ($pending !== []) {
            return $this->review(['achievement_evidence_or_approval'], '有升學或績優資料尚未完成證明／審核。', ['pending_count' => count($pending)]);
        }

        $rate = 0.0;
        foreach ($verified as $achievement) {
            $rate += (string) ($achievement['outcome_key'] ?? '') === 'employee_of_year'
                ? (float) $this->setting('employee_of_year_rate', 5)
                : (float) $this->setting('student_achievement_rate', 5);
        }
        return $this->result(
            self::QUALIFIES,
            $rate > 0 ? '已核准特殊表現，可依制度疊加。' : '目前沒有已核准的特殊表現。',
            ['verified_count' => count($verified), 'active_month_limit' => 3],
            0,
            round($rate, 2)
        );
    }

    public function deductions(array $deductions, ?string $periodStart, ?string $periodEnd): array
    {
        $start = $periodStart ? Carbon::parse($periodStart)->startOfDay() : null;
        $end = $periodEnd ? Carbon::parse($periodEnd)->endOfDay() : null;
        $pending = [];
        $active = [];
        foreach ($deductions as $deduction) {
            if (($deduction['status'] ?? 'pending') === 'withdrawn') {
                continue;
            }
            if (($deduction['status'] ?? 'pending') !== 'approved') {
                $pending[] = $deduction['deduction_key'] ?? 'deduction';
                continue;
            }
            if ($start && !empty($deduction['starts_on']) && Carbon::parse($deduction['starts_on'])->gt($end)) continue;
            if ($end && !empty($deduction['ends_on']) && Carbon::parse($deduction['ends_on'])->lt($start)) continue;
            $active[] = $deduction;
        }
        if ($pending !== []) {
            return $this->review(['deduction_approval'], '有扣除案件尚未完成主任確認及總部審核。', ['pending_count' => count($pending)]);
        }
        $rate = count($active) * (float) $this->setting('deduction_rate', -10);
        return $this->result(
            $active === [] ? self::QUALIFIES : self::NOT_QUALIFIES,
            $active === [] ? '查詢期間沒有已核准扣除案件。' : '查詢期間有已核准扣除案件。',
            ['active_count' => count($active)],
            0,
            round($rate, 2)
        );
    }

    public function subjectCountBonus(?float $subjectCount): array
    {
        if ($subjectCount === null) {
            return $this->review(
                ['approved_learning_records'],
                '缺少已核准評量的科目數資料，無法套用附件表格。'
            );
        }

        $subjectCount = round($subjectCount, 2);
        if ($subjectCount < 1) {
            return $this->result(self::QUALIFIES, '查詢期間沒有科目數獎金。', ['subject_count' => 0], 0, 0);
        }

        if (abs($subjectCount - round($subjectCount)) > 0.0001) {
            return $this->review(
                ['subject_count_table'],
                '科目數含加權小數，附件表格只有1～50整數版本，無法自行推算。',
                ['subject_count' => $subjectCount]
            );
        }

        $subjectCount = (int) round($subjectCount);
        $table = $this->setting('subject_count_table', []);
        if ($subjectCount > 50) {
            return $this->review(['subject_count_table'], '科目數超出附件表格1～50範圍。');
        }
        $row = $table[$subjectCount] ?? null;
        if ($row === null) {
            return $this->review(['subject_count_table'], '科目數超出附件表格1～50範圍。');
        }
        return $this->result(
            self::QUALIFIES,
            '依115.07科目數及一對三獎金表計算。',
            ['subject_count' => $subjectCount, 'subject_count_bonus' => $row[0], 'one_to_three_bonus' => $row[1], 'multiplier' => $row[2]],
            $row[3],
            $row[2] - 100
        );
    }

    private function result(string $status, string $reason, array $metrics = [], $amount = 0, $rate = 0): array
    {
        return [
            'status' => $status,
            'reason' => $reason,
            'metrics' => $metrics,
            'amount' => $amount,
            'rate' => $rate,
            'missing_fields' => [],
        ];
    }

    private function review(array $missing, string $reason, array $metrics = []): array
    {
        return [
            'status' => self::REVIEW,
            'reason' => $reason,
            'metrics' => $metrics,
            'amount' => 0,
            'rate' => 0,
            'missing_fields' => array_values(array_unique($missing)),
        ];
    }

    private function setting(string $key, $default)
    {
        return array_key_exists($key, $this->settings) ? $this->settings[$key] : $default;
    }
}
