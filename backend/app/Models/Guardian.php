<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * @property int|null $id
 * @property string|null $display_name
 * @property string|null $phone
 * @property string|null $phone_normalized
 * @property string|null $line_user_id
 */
class Guardian extends Model
{
    protected $table = 'guardians';

    protected $fillable = [
        'display_name',
        'phone',
        'phone_normalized',
        'line_user_id',
    ];

    public function studentGuardians(): HasMany
    {
        return $this->hasMany(StudentGuardian::class, 'guardian_id');
    }

    public static function normalizePhone(?string $phone): string
    {
        return preg_replace('/[^0-9]/', '', (string) $phone) ?? '';
    }
}
