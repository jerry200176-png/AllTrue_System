<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * @property bool   $paid
 * @property string $paid_at
 * @property int    $remaining_sessions
 * @property int    $total_sessions
 * @property int    $settlement_day
 * @property int    $campus_id
 * @property int    $student_id
 * @property string $name
 * @property float  $rate
 * @mixin \Illuminate\Database\Eloquent\Builder
 */
class CoursePackage extends Model
{
    protected $table = 'course_packages';

    const BILLING_MODE_SESSION = 'count';
    const BILLING_MODE_MONTHLY = 'date';

    protected $fillable = [
        'student_id',
        'campus_id',
        'name',
        'billing_mode',
        'settlement_day',
        'total_sessions',
        'remaining_sessions',
        'used_sessions',
        'rate',
        'rate_unit',
        'class_type',
        'paid',
        'paid_at',
        'stop',
        'closed_reason',
        'enabled',
    ];

    protected $casts = [
        'paid'    => 'boolean',
        'stop'    => 'boolean',
        'enabled' => 'boolean',
    ];

    public function student()
    {
        return $this->belongsTo(Student::class, 'student_id', 'id');
    }

    public function campus()
    {
        return $this->belongsTo(Campus::class, 'campus_id', 'id');
    }

    public function studentClasses()
    {
        return $this->hasMany(StudentClass::class, 'PackageID', 'id');
    }

    public function ledgerEntries()
    {
        return $this->hasMany(PackageSessionLedger::class, 'package_id', 'id');
    }

    /**
     * Net remaining from ledger (authoritative).
     */
    public function computeRemainingFromLedger(): int
    {
        $netDelta = (int) ($this->ledgerEntries()->sum('delta') ?? 0);
        return max(0, $this->total_sessions + $netDelta);
    }

    /**
     * Recompute and persist remaining/used from ledger.
     */
    public function recomputeCounters(): void
    {
        $remaining = $this->computeRemainingFromLedger();
        $this->remaining_sessions = $remaining;
        $this->used_sessions = max(0, $this->total_sessions - $remaining);
        $this->save();
    }
}
