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

    public function session()
    {
        return $this->belongsTo(ClassSession::class, 'session_id');
    }

    public function operator()
    {
        return $this->belongsTo(User::class, 'operator_id');
    }
}
