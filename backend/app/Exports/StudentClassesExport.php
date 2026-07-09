<?php

namespace App\Exports;

use App\Models\StudentClass;
use Maatwebsite\Excel\Concerns\FromCollection;
use Maatwebsite\Excel\Concerns\WithHeadings;

class StudentClassesExport implements FromCollection, WithHeadings
{
    public function collection()
    {
        return StudentClass::all([
            'ID',
            'StudentID',
            'GradeID',
            'SubjectID',
            'ClassType',
            'TeacherID',
            'by1',
            'Period',
            'StartDate',
            'EndDate',
            'week',
            'time',
            'week1',
            'time1',
            'week2',
            'time2',
            'week3',
            'time3',
            'week4',
            'time4',
            'week5',
            'time5',
            'week6',
            'time6',
            'TotalHours',
            'Charge',
            'Pay',
            'PayDate',
            'Paid',
            'Disconunt',
            'Rate',
            'LearnTimeID',
            'room_id',
            'ScheduleMode',
            'SessionCount',
            'RemainingSessions',
            'MDate',
            'Stop',
        ]);
    }

    public function headings(): array
    {
        return [
            'id',
            'student_id',
            'grade_id',
            'subject_id',
            'class_type',
            'teacher_id',
            'by1',
            'period',
            'start_date',
            'end_date',
            'week',
            'time',
            'week1',
            'time1',
            'week2',
            'time2',
            'week3',
            'time3',
            'week4',
            'time4',
            'week5',
            'time5',
            'week6',
            'time6',
            'total_hours',
            'charge',
            'pay',
            'pay_date',
            'paid',
            'discount',
            'rate',
            'learn_time_id',
            'room_id',
            'schedule_mode',
            'session_count',
            'remaining_sessions',
            'mdate',
            'stop',
        ];
    }
}
