<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class SessionDeductionLedger extends Model
{
    protected $table = 'session_deduction_ledger';

    protected $fillable = [
        'student_class_id',
        'class_session_id',
        'event_type',
        'source',
        'minutes',
        'created_by',
        'note',
    ];

    public function studentClass()
    {
        return $this->belongsTo(StudentClass::class, 'student_class_id', 'ID');
    }

    public function classSession()
    {
        return $this->belongsTo(ClassSession::class, 'class_session_id', 'id');
    }

    public function creator()
    {
        return $this->belongsTo(User::class, 'created_by', 'id');
    }

    /**
     * Net deduction count for a StudentClass: sum of deducts minus reverses.
     */
    public static function netCount(int $studentClassId): int
    {
        $counts = self::where('student_class_id', $studentClassId)
            ->selectRaw("SUM(CASE WHEN event_type = 'deduct' THEN 1 ELSE 0 END) as deducts")
            ->selectRaw("SUM(CASE WHEN event_type = 'reverse' THEN 1 ELSE 0 END) as reverses")
            ->first();

        return max(0, (int) ($counts->deducts ?? 0) - (int) ($counts->reverses ?? 0));
    }
}
