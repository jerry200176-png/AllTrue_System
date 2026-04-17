<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * @property int $id
 * @property int $StudentClassID
 * @property int|null $SubjectID  Per-session subject override; null = inherit from StudentClass.
 * @property string $SessionDate
 * @property string $StartTime
 * @property string $EndTime
 * @property int|null $session_charge  Per-session charge override; null = use standard charge.
 */

class ClassSession extends Model
{
    protected $table = 'ClassSession';

    protected $fillable = [
        'StudentClassID',
        'SubjectID',
        'SessionDate',
        'StartTime',
        'EndTime',
        'Status',
        'Note',
        'IsContractException',
        'session_charge',
    ];

    protected $casts = [
        'IsContractException' => 'boolean',
        'session_charge' => 'integer',
    ];

    public function studentClass()
    {
        return $this->belongsTo(StudentClass::class, 'StudentClassID', 'ID');
    }

    public function subject()
    {
        return $this->belongsTo(\App\Models\Subject::class, 'SubjectID', 'id');
    }

    public function signIns()
    {
        return $this->hasMany(StudentSignIn::class, 'ClassSessionID', 'id');
    }

    /**
     * All session rows for payment-slip display (attended, scheduled, leave, etc.).
     * Cancelled sessions are excluded.
     *
     * @param  array<int>  $studentClassIds
     * @return list<array{student_class_id:int,date:string,start_time:?string,end_time:?string,status:string,subject:?string}>
     */
    public static function sessionsForPaymentSlip(array $studentClassIds): array
    {
        $ids = array_values(array_unique(array_filter(array_map('intval', $studentClassIds))));
        if ($ids === []) {
            return [];
        }

        $multiCourse = count($ids) > 1;

        return static::query()
            ->whereIn('StudentClassID', $ids)
            ->whereNotIn('Status', ['cancelled'])
            ->with(['studentClass'])
            ->orderBy('SessionDate')
            ->orderBy('StartTime')
            ->get()
            ->map(function (ClassSession $s) use ($multiCourse) {
                $date = $s->SessionDate ? substr((string) $s->SessionDate, 0, 10) : '';
                $st = $s->StartTime ? substr((string) $s->StartTime, 0, 5) : null;
                $et = $s->EndTime ? substr((string) $s->EndTime, 0, 5) : null;
                $row = [
                    'student_class_id' => (int) $s->StudentClassID,
                    'date'             => $date,
                    'start_time'       => $st,
                    'end_time'         => $et,
                    'status'           => (string) ($s->Status ?? ''),
                ];
                if ($multiCourse && $s->studentClass) {
                    $row['subject'] = $s->studentClass->displaySubjectName();
                }

                return $row;
            })
            ->values()
            ->all();
    }
}
