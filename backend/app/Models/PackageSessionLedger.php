<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class PackageSessionLedger extends Model
{
    protected $table = 'package_session_ledger';

    protected $fillable = [
        'package_id',
        'student_class_id',
        'class_session_id',
        'delta',
        'reason',
        'recorded_by',
        'note',
        'request_id',
    ];

    public function coursePackage()
    {
        return $this->belongsTo(CoursePackage::class, 'package_id', 'id');
    }

    public function studentClass()
    {
        return $this->belongsTo(StudentClass::class, 'student_class_id', 'ID');
    }

    public function classSession()
    {
        return $this->belongsTo(ClassSession::class, 'class_session_id', 'id');
    }

    public function recorder()
    {
        return $this->belongsTo(User::class, 'recorded_by', 'id');
    }

    /**
     * Net remaining for a package from ledger entries.
     */
    public static function netDelta(int $packageId): int
    {
        return (int) (self::where('package_id', $packageId)->sum('delta') ?? 0);
    }
}
