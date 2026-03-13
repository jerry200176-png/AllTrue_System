<?php

namespace App\Exports;

use App\Models\Invoice;
use Maatwebsite\Excel\Concerns\FromCollection;
use Maatwebsite\Excel\Concerns\WithHeadings;

class InvoicesExport implements FromCollection, WithHeadings
{
    public function collection()
    {
        return Invoice::all([
            'id',
            'StudentID',
            'StudentClassID',
            'IssueDate',
            'DueDate',
            'TotalAmount',
            'PaidAmount',
            'Status',
            'Note',
            'created_at',
        ]);
    }

    public function headings(): array
    {
        return [
            'id',
            'student_id',
            'student_class_id',
            'issue_date',
            'due_date',
            'total_amount',
            'paid_amount',
            'status',
            'note',
            'created_at',
        ];
    }
}
