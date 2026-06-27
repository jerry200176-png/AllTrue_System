<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * An immutable snapshot of the campus routing table (CRDE Phase 4).
 * Published versions are never mutated; new config = new version.
 */
class CampusRoutingVersion extends Model
{
    protected $table = 'campus_routing_versions';

    protected $fillable = ['version', 'status', 'checksum', 'note', 'published_at'];

    protected $casts = ['published_at' => 'datetime'];

    public function rules()
    {
        return $this->hasMany(CampusRoutingRule::class, 'routing_version_id');
    }
}
