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

        $used = ['摘要' => true, '修正紀錄' => true];  // key = 小寫名稱
        foreach ($this->teachers as $teacher) {
            $title = $this->uniqueSheetName($teacher['teacher_name'], $used);
            $used[mb_strtolower($title)] = true;
            $sheets[] = new TeacherMonthlyPerTeacherSheet($teacher, $title, $prefix);
        }

        $sheets[] = new TeacherMonthlyAdjustmentSheet($this->adjustments, $prefix);

        return $sheets;
    }

    /** Excel sheet 名稱：≤31 字、不分大小寫唯一、不可含 / \\ ? * : [ ] */
    private function uniqueSheetName(string $name, array $used): string
    {
        $name  = preg_replace('/[\/\\\\?\*:\[\]]/', '', $name);
        $name  = $name !== '' ? $name : 'Sheet';
        $final = mb_substr($name, 0, 31);

        for ($i = 2; isset($used[mb_strtolower($final)]); $i++) {
            $suffix = "-{$i}";
            $final  = mb_substr($name, 0, 31 - mb_strlen($suffix)) . $suffix;
        }

        return $final;
    }
}
