<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class SessionEntitlementTransfer extends Model
{
    protected $table = 'session_entitlement_transfers';

    protected $fillable = [
        'class_session_id',
        'source_student_class_id',
        'target_student_class_id',
        'reason',
        'decision_reference',
        'decided_by_user_id',
        'decided_by_actor',
        'transferred_at',
        'rolled_back_at',
        'rolled_back_by_user_id',
        'rolled_back_by_actor',
        'snapshot_before',
        'snapshot_after',
    ];

    protected $casts = [
        'transferred_at' => 'datetime',
        'rolled_back_at' => 'datetime',
        'snapshot_before' => 'array',
        'snapshot_after' => 'array',
    ];
}
