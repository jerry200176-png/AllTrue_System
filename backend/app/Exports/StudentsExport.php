<?php

namespace App\Exports;

use App\Models\Student;
use Illuminate\Database\Eloquent\Builder;
use Maatwebsite\Excel\Concerns\FromQuery;
use Maatwebsite\Excel\Concerns\WithChunkReading;
use Maatwebsite\Excel\Concerns\WithHeadings;

/**
 * #987: was `Student::all()` — loaded the entire Student table (PII) into memory.
 *
 * Now streams via FromQuery + chunked reading (bounded memory) and scopes rows to
 * the caller's campuses (`$campusIds`, from `auth_campus_ids`). An empty list means
 * "no campus restriction" (e.g. super_admin), preserving prior behaviour only for
 * unscoped callers; directors are limited to their own campuses (PII, #889).
 */
class StudentsExport implements FromQuery, WithHeadings, WithChunkReading
{
    /** @param int[] $campusIds */
    public function __construct(private array $campusIds = [])
    {
    }

    public function query(): Builder
    {
        return Student::query()
            ->select([
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
            ])
            ->when(!empty($this->campusIds), fn (Builder $q) => $q->whereIn('CampusID', $this->campusIds))
            ->orderBy('id');
    }

    public function chunkSize(): int
    {
        return 500;
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
