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

/** 帳單清單 Excel (in-app #520): plain-Chinese headers, the same columns the director sees, no internal ids. */
class BillListExport extends DefaultValueBinder implements FromArray, WithHeadings, ShouldAutoSize, WithCustomValueBinder, WithStrictNullComparison
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

    public function __construct(private array $rows)
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
}
