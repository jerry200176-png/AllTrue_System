<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

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
