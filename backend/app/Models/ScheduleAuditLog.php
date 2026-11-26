<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ScheduleAuditLog extends Model
{
    public $timestamps = false;

    protected $table = 'schedule_audit_logs';

    protected $fillable = [
        'session_id',
        'action_type',
        'description',
        'operator_id',
        'branch_id',
        'old_data',
        'new_data',
        'created_at',
    ];

    protected $casts = [
        'old_data'   => 'array',
        'new_data'   => 'array',
        'created_at' => 'datetime',
    ];

    public static function boot(): void
    {
        parent::boot();
        static::creating(function ($model) {
            if (empty($model->created_at)) {
                $model->created_at = now();
            }
        });
    }

    public function recordsNamedCancellation(): bool
    {
        if (!(int) $this->operator_id || strtolower((string) data_get($this->new_data, 'Status')) !== 'cancelled') {
            return false;
        }
        if ($this->action_type === 'create') {
            return data_get($this->new_data, 'Note') === ClassSession::PAUSED_PROJECTION_CANCEL_NOTE;
        }
        return $this->action_type === 'update' && strtolower((string) data_get($this->old_data, 'Status')) !== 'cancelled';
    }

    public function recordsRestorableCancellation(): bool
    {
        return $this->recordsNamedCancellation()
            && in_array(strtolower((string) data_get($this->old_data, 'Status')),
                ['scheduled', 'attended', 'completed', 'late', 'absent'], true);
    }

    /** @return \Illuminate\Database\Eloquent\Relations\BelongsTo<ClassSession, $this> */
    public function session()
    {
        return $this->belongsTo(ClassSession::class, 'session_id');
    }

    /** @return \Illuminate\Database\Eloquent\Relations\BelongsTo<User, $this> */
    public function operator()
    {
        return $this->belongsTo(User::class, 'operator_id');
    }
}
