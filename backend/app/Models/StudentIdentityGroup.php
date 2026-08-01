<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

/**
 * @property int $id
 * @property string|null $display_name
 * @property string $status
 * @property-read \Illuminate\Database\Eloquent\Collection<int, StudentIdentityMember> $members
 * @property-read ParentCrossCampusAccess|null $access
 * @method static \Illuminate\Database\Eloquent\Builder query()
 */
class StudentIdentityGroup extends Model
{
    protected $table = 'student_identity_groups';

    protected $fillable = [
        'display_name',
        'status',
        'created_by_user_id',
    ];

    public function members(): HasMany
    {
        return $this->hasMany(StudentIdentityMember::class, 'identity_group_id');
    }

    public function access(): HasOne
    {
        return $this->hasOne(ParentCrossCampusAccess::class, 'identity_group_id');
    }
}
