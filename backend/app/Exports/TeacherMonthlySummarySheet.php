<?php

namespace App\Exports;

use Maatwebsite\Excel\Concerns\FromArray;
use Maatwebsite\Excel\Concerns\ShouldAutoSize;
use Maatwebsite\Excel\Concerns\WithStrictNullComparison;
use Maatwebsite\Excel\Concerns\WithStyles;
use Maatwebsite\Excel\Concerns\WithTitle;
use PhpOffice\PhpSpreadsheet\Style\Alignment;
use PhpOffice\PhpSpreadsheet\Style\Border;
use PhpOffice\PhpSpreadsheet\Style\Fill;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;

/** 第一張：每位老師一列的月合計 */
class TeacherMonthlySummarySheet implements FromArray, ShouldAutoSize, WithTitle, WithStyles, WithStrictNullComparison
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
        $sheet->mergeCells('A1:F1');  // 標題不參與自動欄寬
        // 整份檔案統一字型（第一張 sheet 設定即套用全 workbook）
        $sheet->getParent()->getDefaultStyle()->getFont()->setName('Microsoft JhengHei')->setSize(11);
        $sheet->freezePane('A3');
        $last = max(3, count($this->teachers) + 2);
        $sheet->getStyle("A2:F{$last}")->applyFromArray([
            'borders' => ['allBorders' => ['borderStyle' => Border::BORDER_THIN, 'color' => ['argb' => 'FFBFBFBF']]],
        ]);
        $sheet->getStyle("B3:F{$last}")->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER);
        $sheet->getStyle("C3:C{$last}")->getNumberFormat()->setFormatCode('0.00');
        // 有只刷一次的老師：該格紅字粗體底色，主任一眼看到要補登的人
        foreach (array_values($this->teachers) as $i => $t) {
            if ($t['totals']['anomaly_days'] > 0) {
                $cell = 'D' . ($i + 3);
                $sheet->getStyle($cell)->getFont()->setBold(true)->getColor()->setARGB('FFC00000');
                $sheet->getStyle($cell)->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setARGB('FFFFF2CC');
            }
        }

        return [
            1 => ['font' => ['bold' => true, 'size' => 12]],
            2 => [
                'font'      => ['bold' => true],
                'fill'      => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['argb' => 'FFD9E1F2']],
                'alignment' => ['horizontal' => Alignment::HORIZONTAL_CENTER],
            ],
        ];
    }
}
