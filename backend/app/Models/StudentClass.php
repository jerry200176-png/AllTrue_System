<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

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
        'Rate', 'LearnTimeID', 'RoomID', 'room_id', 'settlement_day', 'monthly_sessions', 'MDate', 'Stop',
        'ScheduleMode', 'SessionCount', 'RemainingSessions',
        'ClassType', 'UsedSessions', 'SessionDuration',
        'duration1', 'duration2', 'duration3', 'duration4', 'duration5', 'duration6',
        'rate_unit',
    ];

    public function student()
    {
        return $this->belongsTo(Student::class, 'StudentID', 'id');
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
}
