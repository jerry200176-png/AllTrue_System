<?php

namespace App\Exports;

use Carbon\Carbon;
use Illuminate\Support\Collection;
use Maatwebsite\Excel\Concerns\FromArray;
use Maatwebsite\Excel\Concerns\WithStyles;
use Maatwebsite\Excel\Concerns\WithTitle;
use Maatwebsite\Excel\Concerns\ShouldAutoSize;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;
use PhpOffice\PhpSpreadsheet\Style\Fill;

class TeacherMonthlySummarySheet implements FromArray, WithTitle, WithStyles, ShouldAutoSize
{
    private Collection $records;
    private string $yearMonth;
    private int $daysInMonth;
    private int $today;

    private const STATUS_MAP = [
        'present'  => '●',
        'late'     => '△',
        'leave'    => '○',
        'absent'   => '✕',
        'adjusted' => '◇',
    ];

    public function __construct(Collection $records, string $yearMonth)
    {
        $this->records     = $records;
        $this->yearMonth   = $yearMonth;
        $carbon            = Carbon::createFromFormat('Y-m', $yearMonth);
        $this->daysInMonth = (int) $carbon->daysInMonth;
        // Only output columns up to today if current month
        $currentMonth = now()->format('Y-m');
        if ($yearMonth === $currentMonth) {
            $this->today = (int) now()->format('j');
        } else {
            $this->today = $this->daysInMonth;
        }
    }

    public function title(): string
    {
        return '月報摘要';
    }

    public function array(): array
    {
        // Group records by teacher, then by day
        // records: collection of objects with teacher_id, teacher_name, campus_name, sign_in_dt, status
        $byTeacher = $this->records->groupBy('teacher_id');

        // Header row: 老師姓名 | 分校 | 1日 | 2日 | … | N日 | 出勤天 | 遲到天 | 請假天 | 缺席天
        $header = ['老師姓名', '分校'];
        for ($d = 1; $d <= $this->today; $d++) {
            $header[] = "{$d}日";
        }
        $header[] = '出勤天';
        $header[] = '遲到天';
        $header[] = '請假天';
        $header[] = '缺席天';

        $rows = [$header];

        foreach ($byTeacher as $teacherId => $teacherRecords) {
            $first      = $teacherRecords->first();
            $name       = $first->teacher_name ?? '';
            $campus     = $first->campus_name  ?? '';

            // Build day → status map (take latest record per day if multiple)
            $dayMap = [];
            foreach ($teacherRecords as $rec) {
                $day = (int) Carbon::parse($rec->sign_in_dt)->format('j');
                if ($day >= 1 && $day <= $this->today) {
                    $dayMap[$day] = $rec->status ?? 'present';
                }
            }

            $row = [$name, $campus];
            $presentCount = 0;
            $lateCount    = 0;
            $leaveCount   = 0;
            $absentCount  = 0;

            for ($d = 1; $d <= $this->today; $d++) {
                $status = $dayMap[$d] ?? null;
                $row[]  = $status ? (self::STATUS_MAP[$status] ?? $status) : '';

                match ($status) {
                    'present', 'adjusted' => $presentCount++,
                    'late'                => $lateCount++,
                    'leave'               => $leaveCount++,
                    'absent'              => $absentCount++,
                    default               => null,
                };
            }

            $row[] = $presentCount;
            $row[] = $lateCount;
            $row[] = $leaveCount;
            $row[] = $absentCount;
            $rows[] = $row;
        }

        return $rows;
    }

    public function styles(Worksheet $sheet): array
    {
        $lastCol = 2 + $this->today + 4; // name + campus + days + 4 stats
        $lastColLetter = \PhpOffice\PhpSpreadsheet\Cell\Coordinate::stringFromColumnIndex($lastCol);

        // Bold header row
        $styles = [
            1 => ['font' => ['bold' => true]],
        ];

        // Apply conditional cell formatting for late (yellow) and absent (orange)
        $statusMap = self::STATUS_MAP;
        $rowCount  = $sheet->getHighestRow();
        for ($row = 2; $row <= $rowCount; $row++) {
            for ($col = 3; $col <= 2 + $this->today; $col++) {
                $colLetter = \PhpOffice\PhpSpreadsheet\Cell\Coordinate::stringFromColumnIndex($col);
                $cell      = $sheet->getCell("{$colLetter}{$row}");
                $val       = $cell->getValue();
                if ($val === '△') {
                    $cell->getStyle()->getFill()
                        ->setFillType(Fill::FILL_SOLID)
                        ->getStartColor()->setARGB('FFFFE066');
                } elseif ($val === '✕') {
                    $cell->getStyle()->getFill()
                        ->setFillType(Fill::FILL_SOLID)
                        ->getStartColor()->setARGB('FFFFB347');
                }
            }
        }

        return $styles;
    }
}
