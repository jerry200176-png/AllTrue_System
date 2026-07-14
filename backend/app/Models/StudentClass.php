<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;

/**
 * @mixin \Illuminate\Database\Eloquent\Builder
 */
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
        'Rate', 'LearnTimeID', 'room_id', 'settlement_day', 'monthly_sessions', 'MDate', 'Stop', 'closed_reason',
        'ScheduleMode', 'SessionCount', 'RemainingSessions',
        'ClassType', 'UsedSessions', 'SessionDuration',
        'PurchasedMinutes', 'RemainingMinutes',
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

    public function invoices()
    {
        return $this->hasMany(Invoice::class, 'StudentClassID', 'ID');
    }

    public function paymentReports()
    {
        return $this->hasMany(PaymentReport::class, 'StudentClassID', 'ID');
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

    /**
     * 取得「某堂課當日」的標準時長（分鐘）。
     * 先查 duration1~duration6 對應 ISO weekday；否則 fallback 到 SessionDuration。
     * 供單堂時間調整時的費率換算使用（per-day > contract default）。
     *
     * @param  int  $isoWeekday 1=Mon ... 7=Sun
     */
    public function resolveSessionDurationForWeekday(int $isoWeekday): int
    {
        $map = [
            ['week1', 'duration1'], ['week2', 'duration2'], ['week3', 'duration3'],
            ['week4', 'duration4'], ['week5', 'duration5'], ['week6', 'duration6'],
        ];
        foreach ($map as [$wf, $df]) {
            if ((int) ($this->{$wf} ?? 0) === $isoWeekday) {
                $dur = (int) ($this->{$df} ?? 0);
                if ($dur >= 30) {
                    return $dur;
                }
            }
        }
        $fallback = (int) ($this->SessionDuration ?? 0);
        return $fallback > 0 ? $fallback : 0;
    }

    /**
     * #613 A1：契約「每堂標準分鐘」。分鐘制扣堂以此把分鐘換算為堂數顯示值。
     * 來源優先序：SessionDuration（契約預設）→ 60（無資料時的安全 fallback）。
     * 變動時長課（duration1..6 不一）仍以契約 SessionDuration 為準，差異由人工複核。
     */
    public const DEFAULT_SESSION_MINUTES = 60;

    public function perSessionMinutes(): int
    {
        $dur = (int) ($this->SessionDuration ?? 0);
        return $dur >= 1 ? $dur : self::DEFAULT_SESSION_MINUTES;
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
