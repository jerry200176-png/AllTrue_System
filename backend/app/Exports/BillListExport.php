<?php

namespace App\Exports;

use Maatwebsite\Excel\Concerns\FromArray;
use Maatwebsite\Excel\Concerns\WithCustomValueBinder;
use Maatwebsite\Excel\Concerns\WithStrictNullComparison;
use PhpOffice\PhpSpreadsheet\Cell\Cell;
use PhpOffice\PhpSpreadsheet\Cell\DataType;
use PhpOffice\PhpSpreadsheet\Cell\DefaultValueBinder;
use Maatwebsite\Excel\Concerns\ShouldAutoSize;
use Maatwebsite\Excel\Concerns\WithHeadings;
use Maatwebsite\Excel\Concerns\WithMultipleSheets;

/** 帳單清單 Excel (in-app #520): plain-Chinese headers, the same columns the director sees, no internal ids. */
class BillListExport extends DefaultValueBinder implements FromArray, WithHeadings, ShouldAutoSize, WithCustomValueBinder, WithStrictNullComparison, WithMultipleSheets
{
    /** Formula injection: text is always stored as a string cell (a name like =1+1 or @SUM stays literal); numbers stay numeric. */
    public function bindValue(Cell $cell, $value): bool
    {
        if (is_string($value)) {
            $cell->setValueExplicit($value, DataType::TYPE_STRING);

            return true;
        }

        if (is_int($value) || is_float($value)) {
            $cell->setValueExplicit($value, DataType::TYPE_NUMERIC);

            return true;
        }

        return parent::bindValue($cell, $value);
    }

    public function __construct(private array $rows, private ?array $students = null, private ?string $generatedAt = null)
    {
    }

    public function array(): array
    {
        return $this->rows;
    }

    public function headings(): array
    {
        return ['學生', '科目', '期間／剩幾堂', '狀態', '到期／逾期', '應收', '已收', '未收'];
    }

    public function sheets(): array
    {
        if ($this->students === null) {
            return [$this]; // Retain the existing summary-only programmatic export contract.
        }
        $used = [];
        $sheets = [];
        foreach ($this->students as $student) {
            // Same Excel title boundary as TeacherMonthlyAttendanceExport: <=31 characters, case-insensitive unique.
            $name = trim(preg_replace('/[\/\\\\?*:\[\]]/', '', (string) $student['name']), " '\t\n\r\0\x0B");
            $name = $name !== '' ? $name : '學生';
            $title = mb_substr($name, 0, 31);
            for ($i = 2; isset($used[mb_strtolower($title)]); $i++) {
                $suffix = '-' . $i;
                $title = mb_substr($name, 0, 31 - mb_strlen($suffix)) . $suffix;
            }
            $used[mb_strtolower($title)] = true;
            $sheets[] = new StudentBillWorksheet($student, $title, $this->generatedAt ?? now('Asia/Taipei')->toIso8601String());
        }
        return $sheets;
    }
}
