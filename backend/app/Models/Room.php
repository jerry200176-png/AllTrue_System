<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * @property int $id
 * @property int $campus_id
 * @property string $name
 * @property int $capacity
 * @property string|null $memo
 * @property bool $is_active
 */
class Room extends Model
{
    protected $table = 'rooms';

    protected $fillable = ['campus_id', 'name', 'capacity', 'memo', 'is_active'];

    protected $casts = [
        'campus_id' => 'integer',
        'capacity'  => 'integer',
        'is_active' => 'boolean',
    ];

    public function campus()
    {
        return $this->belongsTo(Campus::class, 'campus_id', 'id');
    }
}
