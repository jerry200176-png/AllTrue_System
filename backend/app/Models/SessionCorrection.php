<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Auditable supersede / correction row for ClassSession.
 *
 * @property int $id
 * @property int $session_id
 * @property int $replaced_by_session_id
 * @property string $correction_reason
 * @property string $decision_reference
 * @property \Illuminate\Support\Carbon $decided_at
 * @property int|null $decided_by_user_id
 * @property string|null $decided_by_actor
 * @property string $previous_status
 * @property string $new_status
 * @property int|null $preserved_learning_record_id
 * @property int|null $keeper_learning_record_id
 * @property array|null $snapshot_before
 * @property \Illuminate\Support\Carbon|null $rolled_back_at
 */
class SessionCorrection extends Model
{
    protected $table = 'session_corrections';

    protected $fillable = [
        'session_id',
        'replaced_by_session_id',
        'correction_reason',
        'decision_reference',
        'decided_at',
        'decided_by_user_id',
        'decided_by_actor',
        'previous_status',
        'new_status',
        'preserved_learning_record_id',
        'keeper_learning_record_id',
        'snapshot_before',
        'rolled_back_at',
    ];

    protected $casts = [
        'decided_at' => 'datetime',
        'rolled_back_at' => 'datetime',
        'snapshot_before' => 'array',
    ];

    public function session()
    {
        return $this->belongsTo(ClassSession::class, 'session_id', 'id');
    }

    public function replacedBySession()
    {
        return $this->belongsTo(ClassSession::class, 'replaced_by_session_id', 'id');
    }
}
