<?php

namespace App\Exports;

use Carbon\Carbon;
use Maatwebsite\Excel\Concerns\FromArray;
use Maatwebsite\Excel\Concerns\WithStrictNullComparison;
use Maatwebsite\Excel\Concerns\WithStyles;
use Maatwebsite\Excel\Concerns\WithTitle;
use PhpOffice\PhpSpreadsheet\Style\Alignment;
use PhpOffice\PhpSpreadsheet\Style\Fill;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;

/**
 * 每位老師一張 Sheet（沿用 刷卡清單.xlsx 左右兩塊版型）
 *
 *   A 老師名  B 刷卡時間  C 日期  D 時間(24h)  E 來源  | F 空白 |
 *   G 日期(星期)  H 跑校  I 上班  J 下班  K 工時(時)  L 註記
 * 右側最後一列是合計。
 */
class TeacherMonthlyPerTeacherSheet implements FromArray, WithTitle, WithStyles, WithStrictNullComparison
{
    private const SOURCE_LABELS = ['rfid' => '刷卡', 'manual' => '手動'];

    private int $calendarRows = 0;

    public function __construct(private array $teacher, private string $sheetTitle, private string $titlePrefix)
    {
    }

    public function title(): string
    {
        return $this->sheetTitle;
    }

    public function array(): array
    {
        $rows = [
            ["{$this->titlePrefix} {$this->teacher['teacher_name']} 刷卡清單"],
            ['老師名', '刷卡時間', '日期', '時間', '來源', null, '日期', '跑校', '上班', '下班', '工時(時)', '註記'],
        ];

        $left  = $this->swipeRows();
        $right = $this->calendarRows();
        $this->calendarRows = count($right);
        for ($i = 0, $n = max(count($left), count($right)); $i < $n; $i++) {
            $rows[] = array_merge($left[$i] ?? array_fill(0, 5, null), [null], $right[$i] ?? array_fill(0, 6, null));
        }

        return $rows;
    }

    public function styles(Worksheet $sheet): array
    {
        $sheet->mergeCells('A1:L1');
        $totalRow = $this->calendarRows + 2;

        return [
            1 => [
                'font'      => ['bold' => true, 'color' => ['argb' => 'FFFFFFFF'], 'size' => 12],
                'fill'      => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['argb' => 'FF2F5496']],
                'alignment' => ['horizontal' => Alignment::HORIZONTAL_CENTER, 'vertical' => Alignment::VERTICAL_CENTER],
            ],
            2 => [
                'font' => ['bold' => true],
                'fill' => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['argb' => 'FFD9E1F2']],
            ],
            "G{$totalRow}:L{$totalRow}" => ['font' => ['bold' => true]],
        ];
    }

    /** 左側：每次刷卡一列（上班、下班各一列）。系統自動補的下班不是真的刷卡，來源標「系統補登」。 */
    private function swipeRows(): array
    {
        $rows = [];
        foreach ($this->teacher['swipes'] as $rec) {
            $source = self::SOURCE_LABELS[$rec->source ?? ''] ?? '刷卡';
            $rows[] = $this->swipeRow($rec->sign_in_dt, $source);
            if ($rec->sign_out_dt) {
                $auto = ($rec->memo ?? null) === \App\Services\TeacherAttendanceMonth::AUTO_CLOSE_MEMO;
                $rows[] = $this->swipeRow($rec->sign_out_dt, $auto ? '系統補登' : $source);
            }
        }

        return $rows;
    }

    private function swipeRow(string $dt, string $source): array
    {
        $at = Carbon::parse($dt);

        return [$this->teacher['teacher_name'], $at->format('Y-m-d H:i:s'), $at->format('Y-m-d'), $at->format('H:i'), $source];
    }

    /** 右側：每天一列 + 合計 */
    private function calendarRows(): array
    {
        $rows = array_map(fn ($d) => [
            $d['label'],
            $d['run_school'] ? '是' : null,
            $d['sign_in'],
            $d['sign_out'],
            $d['minutes'] !== null ? round($d['minutes'] / 60, 2) : null,
            $d['note'] !== '' ? $d['note'] : null,
        ], $this->teacher['days']);

        $t = $this->teacher['totals'];
        $rows[] = [
            '合計',
            $t['run_days'] ? "{$t['run_days']} 天" : null,
            null,
            null,
            round($t['minutes'] / 60, 2),
            "出勤 {$t['days_present']} 天、只刷一次 {$t['anomaly_days']} 天、修正 {$t['corrected_days']} 天",
        ];

        return $rows;
    }
}
