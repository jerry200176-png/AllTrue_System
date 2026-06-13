<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Rate card for part-time teachers (#767).
 *
 * Determines the pay rate per 30 minutes based on class size (1v1 / 1v2 / 1v3+)
 * and session type (subject / tutoring / trial).
 *
 * Lookup: use the row with the most recent effective_from ≤ session_date
 * where effective_until IS NULL or ≥ session_date.
 */
class PartTimeRateCard extends Model
{
    protected $table = 'part_time_rate_cards';

    protected $fillable = [
        'user_id',
        'branch_id',
        'class_size',
        'session_type',
        'rate_per_30min',
        'effective_from',
        'effective_until',
    ];

    protected $casts = [
        'rate_per_30min' => 'decimal:2',
        'effective_from' => 'date',
        'effective_until' => 'date',
    ];

    public function teacher()
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    /**
     * Find the applicable rate card for a teacher on a given date.
     *
     * @param int    $userId
     * @param int    $branchId
     * @param string $classSize  '1v1' | '1v2' | '1v3_plus'
     * @param string $sessionType 'subject' | 'tutoring' | 'trial'
     * @param string $date        Y-m-d
     */
    public static function rateFor(
        int    $userId,
        int    $branchId,
        string $classSize,
        string $sessionType,
        string $date
    ): ?self {
        return static::where('user_id', $userId)
            ->where('branch_id', $branchId)
            ->where('class_size', $classSize)
            ->where('session_type', $sessionType)
            ->where('effective_from', '<=', $date)
            ->where(function ($q) use ($date) {
                $q->whereNull('effective_until')
                  ->orWhere('effective_until', '>=', $date);
            })
            ->orderByDesc('effective_from')
            ->first();
    }
}
