<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;

class StudentClass extends Model
{
    protected $table = 'StudentClass';
    protected $primaryKey = 'ID';
    public $timestamps = false;

    protected $fillable = [
        'StudentID', 'GradeID', 'SubjectID', 'TeacherID', 'by1',
        'Period', 'StartDate', 'EndDate',
        'week', 'time', 'week1', 'time1', 'week2', 'time2',
        'week3', 'time3', 'week4', 'time4', 'week5', 'time5', 'week6', 'time6',
        'TotalHours', 'Memo', 'Charge', 'Pay', 'PayDate', 'Paid', 'Disconunt',
        'Rate', 'LearnTimeID', 'RoomID', 'room_id', 'settlement_day', 'monthly_sessions', 'MDate', 'Stop', 'closed_reason',
        'ScheduleMode', 'SessionCount', 'RemainingSessions',
        'ClassType', 'UsedSessions', 'SessionDuration',
        'duration1', 'duration2', 'duration3', 'duration4', 'duration5', 'duration6',
        'rate_unit',
        'PackageID', 'PackageTotalSessions', 'PackageName',
    ];

    public function student()
    {
        return $this->belongsTo(Student::class, 'StudentID', 'id');
    }

    public function subjectRecord()
    {
        return $this->belongsTo(Subject::class, 'SubjectID', 'id');
    }

    public function teacher()
    {
        return $this->belongsTo(User::class, 'TeacherID', 'id');
    }

    public function classSessions()
    {
        return $this->hasMany(ClassSession::class, 'StudentClassID', 'ID');
    }

    public function learningRecords()
    {
        return $this->hasMany(LearningRecord::class, 'StudentClassID', 'ID');
    }

    public function room()
    {
        return $this->belongsTo(Room::class, 'room_id', 'id');
    }

    public function coursePackage()
    {
        return $this->belongsTo(CoursePackage::class, 'PackageID', 'id');
    }

    public function isPartOfPackage(): bool
    {
        return !empty($this->PackageID) && (int) $this->PackageID > 0;
    }

    /** Human-readable subject for UI / slips (Subject 欄位或 SubjectID 對照). */
    public function displaySubjectName(): string
    {
        $subject = $this->getAttribute('Subject');
        if ($subject !== null && $subject !== '') {
            return (string) $subject;
        }
        $id = (int) ($this->SubjectID ?? 0);
        if ($id <= 0) {
            return '課程';
        }

        return (string) (DB::table('Subject')->where('id', $id)->value('Subject_Name')
            ?? DB::table('BaseData')->where('Name', '課程')->where('id', $id)->value('Val')
            ?? '課程');
    }
}
