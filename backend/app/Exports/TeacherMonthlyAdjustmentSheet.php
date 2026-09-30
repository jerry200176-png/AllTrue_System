<?php

namespace App\Exports;

use Carbon\Carbon;
use Maatwebsite\Excel\Concerns\FromArray;
use Maatwebsite\Excel\Concerns\ShouldAutoSize;
use Maatwebsite\Excel\Concerns\WithStyles;
use Maatwebsite\Excel\Concerns\WithTitle;
use PhpOffice\PhpSpreadsheet\Style\Border;
use PhpOffice\PhpSpreadsheet\Style\Fill;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;

/** 最後一張：人工修正紀錄（原始→修正、誰、何時、原因），來源 teacher_signin_adjustments */
class TeacherMonthlyAdjustmentSheet implements FromArray, ShouldAutoSize, WithTitle, WithStyles
{
    public function __construct(private array $adjustments, private string $titlePrefix)
    {
    }

    public function title(): string
    {
        return '修正紀錄';
    }

    public function array(): array
    {
        $rows = [
            ["{$this->titlePrefix} 打卡修正紀錄"],
            ['日期', '老師', '上班 原始→修正', '下班 原始→修正', '修正人', '修正時間', '原因'],
        ];
        foreach ($this->adjustments as $a) {
            $rows[] = [
                substr((string) $a['original_signin_dt'], 0, 10),
                $a['teacher_name'],
                self::hm($a['original_signin_dt']) . ' → ' . self::hm($a['new_signin_dt']),
                self::hm($a['original_signout_dt']) . ' → ' . self::hm($a['new_signout_dt']),
                $a['adjusted_by'],
                Carbon::parse($a['created_at'])->format('Y-m-d H:i'),
                $a['adjust_reason'],
            ];
        }
        if (! $this->adjustments) {
            $rows[] = ['本月沒有修正'];
        }

        return $rows;
    }

    public function styles(Worksheet $sheet): array
    {
        $sheet->mergeCells('A1:G1');  // 標題不參與自動欄寬
        $sheet->freezePane('A3');
        $last = max(3, count($this->adjustments) + 2);
        $sheet->getStyle("A2:G{$last}")->applyFromArray([
            'borders' => ['allBorders' => ['borderStyle' => Border::BORDER_THIN, 'color' => ['argb' => 'FFBFBFBF']]],
        ]);

        return [
            1 => ['font' => ['bold' => true, 'size' => 12]],
            2 => [
                'font' => ['bold' => true],
                'fill' => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['argb' => 'FFD9E1F2']],
            ],
        ];
    }

    private static function hm(?string $dt): string
    {
        return $dt ? Carbon::parse($dt)->format('m-d H:i') : '（空）';
    }
}
