<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * A single immutable domain → campus rule within a routing version (CRDE Phase 4).
 */
class CampusRoutingRule extends Model
{
    protected $table = 'campus_routing_rules';

    protected $fillable = [
        'routing_version_id', 'domain', 'campus_id', 'match_type', 'priority', 'enabled', 'liff_id',
    ];

    protected $casts = [
        'campus_id' => 'integer',
        'priority'  => 'integer',
        'enabled'   => 'boolean',
    ];

    public function version()
    {
        return $this->belongsTo(CampusRoutingVersion::class, 'routing_version_id');
    }
}
