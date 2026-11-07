<?php

namespace App\Exports;

use Maatwebsite\Excel\Concerns\FromArray;
use Maatwebsite\Excel\Concerns\ShouldAutoSize;
use Maatwebsite\Excel\Concerns\WithHeadings;

/** 帳單清單 Excel (in-app #520): plain-Chinese headers, the same columns the director sees, no internal ids. */
class BillListExport implements FromArray, WithHeadings, ShouldAutoSize
{
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
