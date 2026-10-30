<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** #3824: immutable evidence of the original author's assessment body. Insert-only. */
class LearningRecordAuthorSnapshot extends Model
{
    public const UPDATED_AT = null;

    protected $table = 'learning_record_author_snapshots';

    protected $guarded = [];

    protected $casts = ['payload' => 'array', 'created_at' => 'datetime'];

    protected static function booted(): void
    {
        static::updating(fn () => throw new \LogicException('author snapshots are immutable'));
        static::deleting(fn () => throw new \LogicException('author snapshots are immutable'));
    }
}
