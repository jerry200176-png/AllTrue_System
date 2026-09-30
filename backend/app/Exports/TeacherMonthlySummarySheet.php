<?php

namespace App\Exports;

use Maatwebsite\Excel\Concerns\FromArray;
use Maatwebsite\Excel\Concerns\WithStrictNullComparison;
use Maatwebsite\Excel\Concerns\WithStyles;
use Maatwebsite\Excel\Concerns\WithTitle;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;

/** 第一張：每位老師一列的月合計 */
class TeacherMonthlySummarySheet implements FromArray, WithTitle, WithStyles, WithStrictNullComparison
{
    public function __construct(private array $teachers, private string $titlePrefix)
    {
    }

    public function title(): string
    {
        return '摘要';
    }

    public function array(): array
    {
        $rows = [
            ["{$this->titlePrefix} 老師出勤摘要（匯出時間 " . now()->format('Y-m-d H:i') . '）'],
            ['老師', '出勤天數', '總工時(時)', '只刷一次天數', '修正天數', '跑校天數'],
        ];
        foreach ($this->teachers as $t) {
            $x = $t['totals'];
            $rows[] = [$t['teacher_name'], $x['days_present'], round($x['minutes'] / 60, 2), $x['anomaly_days'], $x['corrected_days'], $x['run_days']];
        }
        if (! $this->teachers) {
            $rows[] = ['本月無刷卡資料'];
        }

        return $rows;
    }

    public function styles(Worksheet $sheet): array
    {
        return [1 => ['font' => ['bold' => true, 'size' => 12]], 2 => ['font' => ['bold' => true]]];
    }
}
