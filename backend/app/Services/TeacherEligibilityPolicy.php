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
            'admin_allowance' => $this->adminAllowance($input['admin_allowances'] ?? [], $input['period_start'] ?? null, $input['period_end'] ?? null),
            'subject_count_bonus' => $this->subjectCountBonus(
                array_key_exists('subject_count', $input) && $input['subject_count'] !== null
                    ? (float) $input['subject_count']
                    : (isset($input['subject_units']['payroll_total']) ? (float) $input['subject_units']['payroll_total'] : null),
                isset($input['subject_units']['one_to_three']) ? (float) $input['subject_units']['one_to_three'] : null,
                is_array($input['subject_units'] ?? null) ? $input['subject_units'] : []
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
        foreach ($holidayDays as $index => $day) {
            $regular = (float) $day['regular_scheduled_hours'];
            $leaveUnknown = !array_key_exists('holiday_leave_hours', $day) || $day['holiday_leave_hours'] === null;
            if ($regular < $required && $leaveUnknown) {
                $missing[] = "holiday_days.{$index}.holiday_leave_hours";
            }
        }
        if ($missing !== []) {
            return $this->review($missing, '缺少假日出勤或假日假抵扣時數。');
        }

        $qualified = collect($holidayDays)->every(function ($day) use ($required) {
            $covered = (float) $day['regular_scheduled_hours'] + (float) ($day['holiday_leave_hours'] ?? 0);
            return $covered >= $required;
        });
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
            'holiday_leave_effect' => 'offsets_toward_required_hours',
        ];
        return $this->result(
            $qualified ? self::QUALIFIES : self::NOT_QUALIFIES,
            $qualified
                ? '每個假日常態排課加假日假抵扣均達16小時。'
                : '至少一個假日常態排課加假日假仍未達16小時。',
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

    public function adminAllowance(array $allowances, ?string $periodStart, ?string $periodEnd): array
    {
        $start = $periodStart ? Carbon::parse($periodStart)->startOfDay() : null;
        $end = $periodEnd ? Carbon::parse($periodEnd)->endOfDay() : null;
        $pending = [];
        $rate = 0.0;
        foreach ($allowances as $row) {
            if (($row['status'] ?? 'pending') === 'withdrawn') {
                continue;
            }
            if (($row['status'] ?? 'pending') !== 'approved') {
                $pending[] = $row['role_key'] ?? 'admin_allowance';
                continue;
            }
            if ($start && !empty($row['starts_on']) && Carbon::parse($row['starts_on'])->gt($end)) {
                continue;
            }
            if ($end && !empty($row['ends_on']) && Carbon::parse($row['ends_on'])->lt($start)) {
                continue;
            }
            $rate += (float) ($row['rate'] ?? 0);
        }
        if ($pending !== []) {
            return $this->review(['admin_allowance_approval'], '有行政加給尚未完成主任確認及總部審核。', ['pending_count' => count($pending)]);
        }
        $cap = (float) $this->setting('admin_allowance_rate_cap', 10);
        $rate = min($cap, max(0.0, $rate));

        return $this->result(
            self::QUALIFIES,
            $rate > 0 ? '已核准行政加給，可依制度疊加。' : '查詢期間沒有已核准行政加給。',
            ['rate_cap' => $cap],
            0,
            round($rate, 2)
        );
    }

    public function subjectCountBonus(?float $subjectCount, ?float $oneToThreeCount = null, array $units = []): array
    {
        if ($subjectCount === null) {
            return $this->review(
                ['approved_learning_records'],
                '缺少已核准評量的科目數資料，無法套用附件表格。'
            );
        }

        $payrollCount = round($subjectCount, 4);
        $oneToThree = round($oneToThreeCount ?? $subjectCount, 4);
        if ($payrollCount < 1 && $oneToThree < 1) {
            return $this->result(self::QUALIFIES, '查詢期間沒有科目數獎金。', [
                'subject_count' => 0,
                'one_to_three_count' => 0,
                'regular_subject_count' => $units['regular'] ?? 0,
                'tutoring_trial_subject_count' => $units['tutoring_trial'] ?? 0,
                'subject_count_bonus' => 0,
                'one_to_three_bonus' => 0,
            ], 0, 0);
        }

        $table = $this->setting('subject_count_table', []);
        $subjectBonus = $this->interpolateTableValue($table, max(0, $payrollCount), 0);
        $oneToThreeBonus = $this->interpolateTableValue($table, max(0, $oneToThree), 1);
        $illustrativeMultiplier = $this->interpolateTableValue($table, max($payrollCount, $oneToThree), 2);
        $illustrativeAmount = $this->interpolateTableValue($table, max($payrollCount, $oneToThree), 3);
        if ($subjectBonus === null || $oneToThreeBonus === null || $illustrativeMultiplier === null) {
            return $this->review(
                ['subject_count_table'],
                '科目數超出附件表格1～50範圍。',
                ['subject_count' => $payrollCount, 'one_to_three_count' => $oneToThree]
            );
        }

        return $this->result(
            self::QUALIFIES,
            '依115.07科目數及一對三獎金表計算。',
            [
                'subject_count' => $payrollCount,
                'one_to_three_count' => $oneToThree,
                'regular_subject_count' => $units['regular'] ?? null,
                'tutoring_trial_subject_count' => $units['tutoring_trial'] ?? null,
                'subject_count_bonus' => $this->number(round($subjectBonus, 2)),
                'one_to_three_bonus' => $this->number(round($oneToThreeBonus, 2)),
                'multiplier' => $this->number(round($illustrativeMultiplier, 2)),
            ],
            $illustrativeAmount === null ? 0 : $this->number(round($illustrativeAmount, 2)),
            $this->number(round($illustrativeMultiplier - 100, 2))
        );
    }

    private function interpolateTableValue(array $table, float $count, int $column): ?float
    {
        if ($count < 1) {
            return 0.0;
        }
        if ($count > 50) {
            return null;
        }
        $floor = (int) floor($count);
        $ceil = (int) ceil($count);
        $low = $table[$floor] ?? null;
        $high = $table[$ceil] ?? null;
        if ($low === null || $high === null) {
            return null;
        }
        if ($floor === $ceil) {
            return (float) $low[$column];
        }
        $fraction = $count - $floor;
        return (float) $low[$column] + ((float) $high[$column] - (float) $low[$column]) * $fraction;
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

    private function number(float $value): int|float
    {
        $rounded = round($value, 2);
        return abs($rounded - (int) round($rounded)) < 0.0001 ? (int) round($rounded) : $rounded;
    }
}
