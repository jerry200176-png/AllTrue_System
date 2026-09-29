<?php

namespace Tests\Unit;

use App\Services\ScheduleGuardService;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;

class ScheduleGuardSummaryTest extends TestCase
{
    /** @dataProvider sourceLabels */
    public function test_inapp_347_known_sources_use_readable_labels(string $source, string $label): void
    {
        $details = [$this->detail($source)];
        $summary = $this->invoke('buildOverlapSummary', $details);

        self::assertSame('測試學生（數學・9/1期）(16:00-18:00,'.$label.')', $summary);
        self::assertStringNotContainsString($source, $summary);
        self::assertSame($source, $details[0]['source']);
    }

    public function sourceLabels(): array
    {
        return [
            ['student_class', '固定課程'],
            ['class_session', '課堂紀錄'],
            ['schedule', '排課紀錄'],
        ];
    }

    public function test_unknown_source_is_retained_without_inventing_its_meaning(): void
    {
        self::assertStringContainsString(',future_source)', $this->invoke('buildOverlapSummary', [$this->detail('future_source')]));
        self::assertSame('', $this->invoke('buildOverlapSummary', []));
    }

    public function test_missing_name_and_source_keep_existing_fallbacks(): void
    {
        $row = $this->detail('');
        $row['student_name'] = '';
        $row['student_id'] = 7;
        $row['subject_name'] = '';
        $row['course_period'] = '';
        self::assertSame('#7(16:00-18:00,)', $this->invoke('buildOverlapSummary', [$row]));
        $row['student_id'] = 0;
        self::assertSame('未綁定學生(16:00-18:00,)', $this->invoke('buildOverlapSummary', [$row]));
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
        self::assertStringStartsWith('測試1（數學・9/1期）', $summary);
        self::assertStringContainsString('測試5（數學・9/1期）', $summary);
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
        self::assertStringContainsString('課堂紀錄', $result['message']);
        self::assertStringNotContainsString('class_session', $result['message']);
        self::assertSame($details, $result['overlap_details']);
        foreach (['type', 'current_students', 'allowed_students', 'overlap_summary'] as $key) {
            self::assertSame($conflict[$key], $result[$key]);
        }
        self::assertSame($result, $this->invoke('finalizeCapacityConflict', $result));
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
