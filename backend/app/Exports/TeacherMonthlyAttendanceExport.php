<?php

namespace App\Exports;

use Maatwebsite\Excel\Concerns\WithMultipleSheets;

/**
 * 老師月報匯出（v2）
 *
 * 第一張「摘要」→ 每位老師一張（左側刷卡紀錄 + 右側每日上下班／工時／註記）→ 最後「修正紀錄」。
 * 每日計算在 App\Services\TeacherAttendanceMonth，網頁月檢視共用。
 */
class TeacherMonthlyAttendanceExport implements WithMultipleSheets
{
    public function __construct(
        private array $teachers,
        private array $adjustments,
        private string $yearMonth,
        private string $campusName
    ) {
    }

    public function sheets(): array
    {
        $prefix = trim("{$this->campusName} {$this->yearMonth}");
        $sheets = [new TeacherMonthlySummarySheet($this->teachers, $prefix)];

        $used = ['摘要' => true, '修正紀錄' => true];
        foreach ($this->teachers as $teacher) {
            $title = $this->uniqueSheetName($teacher['teacher_name'], $used);
            $used[$title] = true;
            $sheets[] = new TeacherMonthlyPerTeacherSheet($teacher, $title, $prefix);
        }

        $sheets[] = new TeacherMonthlyAdjustmentSheet($this->adjustments, $prefix);

        return $sheets;
    }

    private function uniqueSheetName(string $name, array $used): string
    {
        $name  = preg_replace('/[\/\\\\?\*:\[\]]/', '', $name);
        $name  = $name !== '' ? $name : 'Sheet';
        $base  = mb_substr($name, 0, 29);
        $final = mb_substr($name, 0, 31);

        $i = 2;
        while (isset($used[$final])) {
            $final = $base . "-{$i}";
            $i++;
        }

        return $final;
    }
}
