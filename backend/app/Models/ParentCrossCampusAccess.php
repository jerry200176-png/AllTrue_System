<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * @property int $id
 * @property int $identity_group_id
 * @property string $mode
 * @method static \Illuminate\Database\Eloquent\Builder query()
 */
class ParentCrossCampusAccess extends Model
{
    protected $table = 'parent_cross_campus_access';

    protected $fillable = [
        'identity_group_id',
        'mode',
        'updated_by_user_id',
    ];
}
