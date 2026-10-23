<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * @property int $id
 * @property string $Name
 * @property string $ApiKeyHash
 * @property int $CampusID
 * @property bool $Active
 * @property string $Purpose
 * @property array<int,string>|null $Scopes
 * @property int|null $IssuedByUserID
 * @property array<int,mixed>|null $CampusIDs
 * @property \Carbon\CarbonInterface|null $ExpiresAt
 * @property \Carbon\CarbonInterface|null $created_at
 * @property \Carbon\CarbonInterface|null $updated_at
 */
class ApiClient extends Model
{
    protected $table = 'ApiClient';

    protected $fillable = [
        'Name',
        'ApiKeyHash',
        'CampusID',
        'Active',
        'Purpose',
        'Scopes',
    ];

    protected $casts = [
        'Active' => 'boolean',
        'Scopes' => 'array',
        'IssuedByUserID' => 'integer',
        'CampusIDs' => 'array',
        'ExpiresAt' => 'datetime',
    ];
}
