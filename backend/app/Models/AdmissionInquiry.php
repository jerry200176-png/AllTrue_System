<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * @property int $id
 * @property int $campus_id
 * @property string $status
 * @property string $parent_name
 * @property string $parent_phone
 * @property string $student_name
 * @property string $grade
 * @property string|null $school_name
 * @property string $subject
 * @property array<int, string>|null $preferred_slots
 * @property string|null $public_notes
 * @property string|null $staff_notes
 * @property string|null $trial_result
 * @property int|null $student_id
 * @property int|null $trial_student_class_id
 * @property int|null $enrolled_student_class_id
 * @property int|null $assigned_to
 * @property \Illuminate\Support\Carbon|null $consent_at
 * @property \Illuminate\Support\Carbon|null $contacted_at
 * @property \Illuminate\Support\Carbon|null $trial_scheduled_at
 * @property \Illuminate\Support\Carbon|null $trial_completed_at
 * @property \Illuminate\Support\Carbon|null $enrolled_at
 * @property \Illuminate\Support\Carbon $created_at
 * @method static \Illuminate\Database\Eloquent\Builder<static> query()
 * @method static static create(array $attributes = [])
 * @method static static findOrFail($id, $columns = ['*'])
 */
class AdmissionInquiry extends Model
{
    public const STATUS_NEW = 'new';
    public const STATUS_CONTACTED = 'contacted';
    public const STATUS_TRIAL_SCHEDULED = 'trial_scheduled';
    public const STATUS_TRIAL_COMPLETED = 'trial_completed';
    public const STATUS_ENROLLED = 'enrolled';
    public const STATUS_LOST = 'lost';

    protected $table = 'admission_inquiries';

    protected $fillable = [
        'campus_id', 'status', 'parent_name', 'parent_phone', 'parent_phone_hash',
        'student_name', 'student_name_hash', 'grade', 'school_name', 'subject',
        'preferred_slots', 'public_notes', 'staff_notes', 'consent_at', 'assigned_to',
        'contacted_at', 'trial_scheduled_at', 'trial_completed_at', 'enrolled_at',
        'trial_result', 'student_id', 'trial_student_class_id', 'enrolled_student_class_id',
    ];

    protected $casts = [
        'parent_name' => 'encrypted',
        'parent_phone' => 'encrypted',
        'student_name' => 'encrypted',
        'school_name' => 'encrypted',
        'preferred_slots' => 'array',
        'public_notes' => 'encrypted',
        'staff_notes' => 'encrypted',
        'consent_at' => 'datetime',
        'contacted_at' => 'datetime',
        'trial_scheduled_at' => 'datetime',
        'trial_completed_at' => 'datetime',
        'enrolled_at' => 'datetime',
    ];

    public static function mask(string|null $value, int $visible = 1): string
    {
        $value = trim((string) $value);
        if ($value === '') {
            return '';
        }
        return mb_substr($value, 0, $visible) . '***';
    }

    public static function maskPhone(string|null $value): string
    {
        $value = trim((string) $value);
        if (mb_strlen($value) <= 4) {
            return '****';
        }
        return str_repeat('*', max(0, mb_strlen($value) - 4)) . mb_substr($value, -4);
    }
}
