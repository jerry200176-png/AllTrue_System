<?php

namespace App\Exports;

use Illuminate\Support\Collection;
use Maatwebsite\Excel\Concerns\FromArray;
use Maatwebsite\Excel\Concerns\WithTitle;
use Maatwebsite\Excel\Concerns\ShouldAutoSize;
use Maatwebsite\Excel\Concerns\WithStyles;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;

class TeacherMonthlyDetailSheet implements FromArray, WithTitle, ShouldAutoSize, WithStyles
{
    private Collection $records;
    private Collection $adjustments;

    public function __construct(Collection $records, Collection $adjustments)
    {
        $this->records     = $records;
        $this->adjustments = $adjustments->keyBy('teacher_signin_id');
    }

    public function title(): string
    {
        return '明細記錄';
    }

    public function array(): array
    {
        $header = [
            '老師ID', '老師姓名', '分校ID', '日期',
            '簽到時間', '簽退時間', '狀態', '遲到分鐘', '補卡原因',
        ];

        $statusLabel = [
            'present'  => '出勤',
            'late'     => '遲到',
            'leave'    => '請假',
            'absent'   => '缺席',
            'adjusted' => '補卡',
        ];

        $rows = [$header];

        foreach ($this->records as $rec) {
            $adj      = $this->adjustments->get($rec->id);
            $rows[] = [
                $rec->teacher_id,
                $rec->teacher_name,
                $rec->campus_id,
                substr((string) $rec->sign_in_dt, 0, 10),
                substr((string) $rec->sign_in_dt, 11, 5),
                $rec->sign_out_dt ? substr((string) $rec->sign_out_dt, 11, 5) : '',
                $statusLabel[$rec->status] ?? ($rec->status ?? ''),
                $rec->late_minutes ?? '-',
                $adj ? $adj->adjust_reason : '',
            ];
        }

        return $rows;
    }

    public function styles(Worksheet $sheet): array
    {
        return [
            1 => ['font' => ['bold' => true]],
        ];
    }
}
