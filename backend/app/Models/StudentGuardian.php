<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class StudentGuardian extends Model
{
    public const STATUS_PENDING = 'pending';
    public const STATUS_ACTIVE = 'active';
    public const STATUS_READ_ONLY = 'read_only';
    public const STATUS_SUSPENDED = 'suspended';
    public const STATUS_REVOKED = 'revoked';

    public const ROLE_FATHER = 'father';
    public const ROLE_MOTHER = 'mother';
    public const ROLE_GUARDIAN = 'guardian';
    public const ROLE_OTHER = 'other';

    public const SOURCE_STAFF = 'staff';
    public const SOURCE_LEGACY_PHONE = 'legacy_phone';
    public const SOURCE_LINE_BINDING = 'line_binding';
    public const SOURCE_IMPORT = 'import';

    protected $table = 'student_guardians';

    protected $fillable = [
        'student_id',
        'guardian_id',
        'campus_id',
        'role',
        'is_primary',
        'status',
        'notify_learning_feedback',
        'notify_tuition',
        'source',
        'student_line_binding_id',
        'revoked_at',
    ];

    protected $casts = [
        'is_primary' => 'boolean',
        'notify_learning_feedback' => 'boolean',
        'notify_tuition' => 'boolean',
        'revoked_at' => 'datetime',
    ];

    public function student(): BelongsTo
    {
        return $this->belongsTo(Student::class, 'student_id');
    }

    public function guardian(): BelongsTo
    {
        return $this->belongsTo(Guardian::class, 'guardian_id');
    }

    public function scopeNotRevoked($query)
    {
        return $query->where('status', '!=', self::STATUS_REVOKED);
    }

    public function scopeActiveAccess($query)
    {
        return $query->whereIn('status', [self::STATUS_ACTIVE, self::STATUS_READ_ONLY]);
    }

    public function isAccessGranted(): bool
    {
        return in_array((string) $this->status, [self::STATUS_ACTIVE, self::STATUS_READ_ONLY], true);
    }
}
