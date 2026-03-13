<?php

namespace App\Imports;

use App\Models\Student;
use Carbon\Carbon;
use Maatwebsite\Excel\Concerns\ToModel;
use Maatwebsite\Excel\Concerns\WithHeadingRow;

class StudentsImport implements ToModel, WithHeadingRow
{
    public function model(array $row)
    {
        return new Student([
            'name' => $row['name'] ?? '',
            'CampusID' => (int) ($row['campus_id'] ?? 0),
            'ClassID' => (int) ($row['class_id'] ?? 0),
            'SchoolName' => $row['school_name'] ?? null,
            'Phone' => $row['phone'] ?? null,
            'RFID' => $row['rfid'] ?? null,
            'LineID' => $row['line_id'] ?? null,
            'TelegramID' => $row['telegram_id'] ?? '',
            'TelegramID1' => $row['telegram_id1'] ?? null,
            'TelegramID2' => $row['telegram_id2'] ?? null,
            'enable' => isset($row['enable']) ? (int) $row['enable'] : 1,
            'MDT' => Carbon::now(),
            'Notify_Token' => $row['notify_token'] ?? '',
        ]);
    }
}
