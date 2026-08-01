<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property int $id
 * @property int $identity_group_id
 * @property int $student_id
 * @property int $campus_id
 * @property string $status
 * @property-read StudentIdentityGroup|null $group
 * @property-read Student|null $student
 * @method static \Illuminate\Database\Eloquent\Builder query()
 */
class StudentIdentityMember extends Model
{
    protected $table = 'student_identity_members';

    protected $fillable = [
        'identity_group_id',
        'student_id',
        'campus_id',
        'status',
        'created_by_user_id',
        'revoked_at',
        'revoked_by_user_id',
        'revoked_reason',
    ];

    protected $casts = [
        'revoked_at' => 'datetime',
    ];

    public function group(): BelongsTo
    {
        return $this->belongsTo(StudentIdentityGroup::class, 'identity_group_id');
    }

    public function student(): BelongsTo
    {
        return $this->belongsTo(Student::class, 'student_id');
    }
}
