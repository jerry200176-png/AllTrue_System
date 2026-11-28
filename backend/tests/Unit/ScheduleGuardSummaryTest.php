<?php

namespace Tests\Unit;

use App\Services\ScheduleGuardService;
use Carbon\Carbon;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;

class ScheduleGuardSummaryTest extends TestCase
{
    /** @dataProvider sourceLabels */
    public function test_inapp_347_known_sources_use_readable_labels(string $source, string $label): void
    {
        $details = [$this->detail($source)];
        $summary = $this->invoke('buildOverlapSummary', $details);

        self::assertSame('測試學生（數學，9/1 開始）16:00–18:00 '.$label, $summary);
        self::assertStringNotContainsString($source, $summary);
        self::assertSame($source, $details[0]['source']);
    }

    public static function sourceLabels(): array
    {
        return [
            ['student_class', '固定課程'],
            ['class_session', '已排的課'],
            ['schedule', '排課紀錄'],
        ];
    }

    public function test_unknown_source_is_retained_without_inventing_its_meaning(): void
    {
        self::assertStringEndsWith('16:00–18:00 future_source', $this->invoke('buildOverlapSummary', [$this->detail('future_source')]));
        self::assertSame('', $this->invoke('buildOverlapSummary', []));
    }

    public function test_missing_name_and_source_keep_existing_fallbacks(): void
    {
        $row = $this->detail('');
        $row['student_name'] = '';
        $row['student_id'] = 7;
        $row['subject_name'] = '';
        $row['course_period'] = '';
        self::assertSame('#7 16:00–18:00', $this->invoke('buildOverlapSummary', [$row]));
        $row['student_id'] = 0;
        self::assertSame('未綁定學生 16:00–18:00', $this->invoke('buildOverlapSummary', [$row]));
    }

    public function test_summary_limit_preserves_order_and_omitted_count(): void
    {
        $rows = [];
        for ($i = 1; $i <= 7; $i++) {
            $row = $this->detail('schedule');
            $row['student_name'] = '測試'.$i;
            $rows[] = $row;
        }
        $summary = $this->invoke('buildOverlapSummary', $rows);
        self::assertSame(5, substr_count($summary, '排課紀錄'));
        self::assertStringStartsWith('測試1（數學，9/1 開始）', $summary);
        self::assertStringContainsString('測試5（數學，9/1 開始）', $summary);
        self::assertStringEndsWith('；...其餘 2 筆', $summary);
        self::assertStringNotContainsString('測試6', $summary);
    }

    public function test_public_message_changes_only_summary_while_raw_diagnostics_remain_intact(): void
    {
        $details = [$this->detail('class_session')];
        $conflict = [
            'type' => 'teacher_capacity', 'current_students' => 3, 'allowed_students' => 3,
            'message' => '老師此時段本分校已有 3 位學生，上限為 3 位學生。',
            'overlap_details' => $details,
            'overlap_summary' => $this->invoke('buildOverlapSummary', $details),
        ];
        $result = $this->invoke('finalizeCapacityConflict', $conflict);
        self::assertStringContainsString('已排的課', $result['message']);
        self::assertStringNotContainsString('class_session', $result['message']);
        self::assertSame($details, $result['overlap_details']);
        foreach (['type', 'current_students', 'allowed_students', 'overlap_summary'] as $key) {
            self::assertSame($conflict[$key], $result[$key]);
        }
        self::assertSame($result, $this->invoke('finalizeCapacityConflict', $result));
    }

    public function test_inapp_406_same_student_from_contract_and_session_reads_as_one_entry(): void
    {
        $other = $this->detail('student_class');
        $other['student_id'] = 8;
        $other['student_name'] = '另一位';
        $rows = [$this->detail('student_class'), $other, $this->detail('class_session')];

        self::assertSame(
            '測試學生（數學，9/1 開始）16:00–18:00 固定課程＋已排的課；另一位（數學，9/1 開始）16:00–18:00 固定課程',
            $this->invoke('buildOverlapSummary', $rows)
        );
    }

    public function test_same_student_at_drifted_times_is_one_entry_listing_both_times(): void
    {
        $drifted = $this->detail('class_session');
        $drifted['start_time'] = '16:30';
        $drifted['end_time'] = '18:30';

        self::assertSame(
            '測試學生（數學，9/1 開始）16:00–18:00 固定課程、（數學，9/1 開始）16:30–18:30 已排的課',
            $this->invoke('buildOverlapSummary', [$this->detail('student_class'), $drifted])
        );
    }

    public function test_same_student_with_two_contracts_lists_both(): void
    {
        $renewal = $this->detail('student_class');
        $renewal['course_period'] = '12/1期';

        self::assertSame(
            '測試學生（數學，9/1 開始）16:00–18:00 固定課程、（數學，12/1 開始）16:00–18:00 固定課程',
            $this->invoke('buildOverlapSummary', [$this->detail('student_class'), $renewal])
        );
    }

    public function test_duplicate_contracts_with_identical_labels_both_show(): void
    {
        $first = $this->detail('student_class');
        $first['course_id'] = 101;
        $duplicate = $first;
        $duplicate['course_id'] = 102;

        self::assertSame(2, substr_count($this->invoke('buildOverlapSummary', [$first, $duplicate]), '固定課程'));
    }

    public function test_rows_without_a_student_stay_distinct_per_source(): void
    {
        $rows = [];
        foreach ([31, 32] as $sourceId) {
            $row = $this->detail('schedule');
            $row['student_id'] = 0;
            $row['student_name'] = '';
            $row['subject_name'] = '';
            $row['course_period'] = '';
            $row['source_id'] = $sourceId;
            $rows[] = $row;
        }

        self::assertSame(2, substr_count($this->invoke('buildOverlapSummary', $rows), '未綁定學生'));
    }

    public function test_inapp_613_summary_and_message_name_the_clashing_dates(): void
    {
        $weekly = ['day_of_week' => 6, 'course_period' => '8/1期', 'start_time' => '13:00', 'end_time' => '15:00'] + $this->detail('student_class');
        $lesson = ['student_id' => 8, 'student_name' => '另一位', 'subject_name' => '理化', 'course_period' => '7/4期', 'schedule_date' => '2026-12-06',
            'start_time' => '13:00', 'end_time' => '15:00'] + $this->detail('class_session');
        $dated = ['student_id' => 9, 'student_name' => '第三位', 'course_end_date' => '2026-12-31'] + $weekly;
        self::assertSame(
            '測試學生（數學，8/1 開始）每週六 13:00–15:00 固定課程；另一位（理化，7/4 開始）12/6 13:00–15:00 已排的課；第三位（數學，8/1 開始）每週六（到 12/31）13:00–15:00 固定課程',
            $this->invoke('buildOverlapSummary', [$weekly, $lesson, $dated])
        );

        $dates = new ReflectionMethod(ScheduleGuardService::class, 'withConflictDates');
        $conflict = ['message' => '老師此時段本分校已有 3 位學生，上限為 3 位學生。'];
        $sunday = ['day_of_week' => 7, 'start_time' => '13:00', 'end_time' => '15:00'];
        $two = $dates->invoke(new ScheduleGuardService(), $conflict, ['2026-12-13', '2026-12-06'], $sunday);
        self::assertSame('12/6、12/13 這兩天週日 13:00–15:00 老師此時段本分校已有 3 位學生，上限為 3 位學生。', $two['message']);
        self::assertSame(['2026-12-06', '2026-12-13'], $two['conflict_dates']);
        self::assertStringStartsWith('12/6 週日 13:00–15:00 老師', $dates->invoke(new ScheduleGuardService(), $conflict, ['2026-12-06'], $sunday)['message']);

        Carbon::setTestNow('2026-11-28 10:00:00');
        try {
            $saturday = ['day_of_week' => 6] + $sunday;
            self::assertStringStartsWith('從 12/5 起每週六 13:00–15:00 老師', $dates->invoke(new ScheduleGuardService(), $conflict, [], $saturday, '2026-12-01')['message']);
            self::assertStringStartsWith('從 11/28 起每週六', $dates->invoke(new ScheduleGuardService(), $conflict, [], $saturday, null)['message']);
            // Codex P2: the weekly prefix starts at the first start at which the begun courses fill the slot.
            $first = new ReflectionMethod(ScheduleGuardService::class, 'firstFullWeek');
            $svc = new ScheduleGuardService();
            $three = fn (array $t) => count($t) >= 3;
            $rows = [['course_start_date' => '2026-08-01'], ['course_start_date' => '2026-12-12'], ['course_start_date' => '2026-12-05'], ['course_start_date' => null]];
            self::assertSame('2026-12-05', $first->invoke($svc, '2026-11-28', $rows, $three));
            self::assertSame('2026-11-28', $first->invoke($svc, '2026-11-28', $rows, fn (array $t) => count($t) >= 2));
            self::assertNull($first->invoke($svc, '2026-11-28', $rows, fn (array $t) => count($t) >= 5));
            $earlier = new ReflectionMethod(ScheduleGuardService::class, 'earlierDate');
            self::assertSame('2026-12-01', $earlier->invoke($svc, '2026-12-05', ['', '2026-12-01']));
            self::assertStringStartsWith('從 12/5 起每週六', $dates->invoke($svc, $conflict, [], $saturday, $first->invoke($svc, null, $rows, $three))['message']);
        } finally {
            Carbon::setTestNow();
        }
        $own = ['message' => '這門課自己在 2026-12-06 還有一堂 13:00-15:00 沒有跟著改'];
        self::assertSame($own, $dates->invoke(new ScheduleGuardService(), $own, ['2026-12-06'], $sunday));
    }

    private function detail(string $source): array
    {
        return ['source' => $source, 'source_id' => 12, 'student_id' => 7,
            'student_name' => '測試學生', 'subject_name' => '數學', 'course_period' => '9/1期',
            'start_time' => '16:00', 'end_time' => '18:00'];
    }

    private function invoke(string $method, array $value): mixed
    {
        return (new ReflectionMethod(ScheduleGuardService::class, $method))->invoke(new ScheduleGuardService(), $value);
    }
}
