<?php

namespace App\Exports;

use Illuminate\Support\Collection;
use Maatwebsite\Excel\Concerns\WithMultipleSheets;

/**
 * 老師月報匯出（v1.2）
 *
 * 每位有刷卡記錄的老師各產生一張 Sheet，格式對應
 * 新莊中平分校_2025-12_刷卡清單.xlsx（左側流水帳 + 右側月曆摘要）。
 */
class TeacherMonthlyAttendanceExport implements WithMultipleSheets
{
    private Collection $records;
    private string $yearMonth;
    private string $campusName;

    public function __construct(Collection $records, string $yearMonth, string $campusName)
    {
        $this->records    = $records;
        $this->yearMonth  = $yearMonth;
        $this->campusName = $campusName;
    }

    public function sheets(): array
    {
        // Group by teacher, sort by teacher name
        $grouped = $this->records->groupBy('teacher_id')->sortBy(
            fn ($rows) => $rows->first()->teacher_name ?? ''
        );

        $sheets = [];
        $usedNames = [];

        foreach ($grouped as $teacherId => $teacherRecords) {
            $raw = $teacherRecords->first()->teacher_name ?? '';
            $teacherName = $raw !== '' ? $raw : "老師{$teacherId}";

            // Deduplicate sheet names (Excel requires unique sheet titles)
            $sheetName = $this->uniqueSheetName($teacherName, $usedNames);
            $usedNames[$sheetName] = true;

            $sheets[] = new TeacherMonthlyPerTeacherSheet(
                $teacherName,
                $teacherRecords,
                $this->yearMonth,
                $this->campusName
            );
        }

        if (empty($sheets)) {
            // PhpSpreadsheet requires at least one sheet; return a placeholder
            $sheets[] = new TeacherMonthlyPerTeacherSheet(
                '無資料',
                collect(),
                $this->yearMonth,
                $this->campusName
            );
        }

        return $sheets;
    }

    private function uniqueSheetName(string $name, array $used): string
    {
        $name  = preg_replace('/[\/\\\\?\*:\[\]]/', '', $name);
        $base  = mb_substr($name, 0, 29);
        $final = mb_substr($name, 0, 31);

        $i = 2;
        while (isset($used[$final])) {
            $suffix = "-{$i}";
            $final  = $base . $suffix;
            $i++;
        }

        return $final;
    }
}
