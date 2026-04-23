<?php

namespace App\Exports;

use Illuminate\Support\Collection;
use Maatwebsite\Excel\Concerns\WithMultipleSheets;

class TeacherMonthlyAttendanceExport implements WithMultipleSheets
{
    private Collection $records;
    private Collection $adjustments;
    private string $yearMonth;

    public function __construct(Collection $records, Collection $adjustments, string $yearMonth)
    {
        $this->records     = $records;
        $this->adjustments = $adjustments;
        $this->yearMonth   = $yearMonth;
    }

    public function sheets(): array
    {
        return [
            new TeacherMonthlySummarySheet($this->records, $this->yearMonth),
            new TeacherMonthlyDetailSheet($this->records, $this->adjustments),
        ];
    }
}
