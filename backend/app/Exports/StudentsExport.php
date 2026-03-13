<?php

namespace App\Exports;

use App\Models\Student;
use Maatwebsite\Excel\Concerns\FromCollection;
use Maatwebsite\Excel\Concerns\WithHeadings;

class StudentsExport implements FromCollection, WithHeadings
{
    public function collection()
    {
        return Student::all([
            'id',
            'name',
            'CampusID',
            'ClassID',
            'SchoolName',
            'Phone',
            'RFID',
            'LineID',
            'TelegramID',
            'enable',
            'MDT',
        ]);
    }

    public function headings(): array
    {
        return [
            'id',
            'name',
            'campus_id',
            'class_id',
            'school_name',
            'phone',
            'rfid',
            'line_id',
            'telegram_id',
            'enable',
            'mdt',
        ];
    }
}
