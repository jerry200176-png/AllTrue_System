<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * An immutable snapshot of the campus routing table (CRDE Phase 4).
 * Published versions are never mutated; new config = new version.
 *
 * @property int $id
 * @property int $version
 * @property string $status
 * @property string $checksum
 * @property string|null $note
 * @property \Illuminate\Support\Carbon|null $published_at
 *
 * @method static \Illuminate\Database\Eloquent\Builder|CampusRoutingVersion where($column, $operator = null, $value = null, $boolean = 'and')
 * @method static \Illuminate\Database\Eloquent\Builder|CampusRoutingVersion orderByDesc($column)
 * @method static mixed max($column)
 * @method static static create(array $attributes = [])
 * @method static static findOrFail($id, $columns = ['*'])
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
