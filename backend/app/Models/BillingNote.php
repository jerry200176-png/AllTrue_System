<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * #3836 — one append-only note on a billing-center row. Insert only: editing or deleting a
 * saved note throws (fail closed), and there is no update or delete route.
 *
 * @property int $id
 * @property int $campus_id
 * @property string $subject_type  one of self::SUBJECT_TYPES
 * @property int $subject_id
 * @property string $body
 * @property int $author_user_id
 * @property \Carbon\CarbonInterface|null $created_at
 */
class BillingNote extends Model
{
    public const SUBJECT_TYPES = ['student_class', 'invoice', 'payment', 'payment_report'];

    public const MAX_BODY = 1000;

    public const UPDATED_AT = null;

    protected $table = 'billing_notes';

    protected $fillable = ['campus_id', 'subject_type', 'subject_id', 'body', 'author_user_id'];

    protected $casts = [
        'campus_id' => 'integer',
        'subject_id' => 'integer',
        'author_user_id' => 'integer',
        'created_at' => 'datetime',
    ];

    protected static function booted(): void
    {
        static::updating(function (): void {
            throw new \LogicException('billing_notes is append-only: a saved note cannot be edited.');
        });
        static::deleting(function (): void {
            throw new \LogicException('billing_notes is append-only: a saved note cannot be deleted.');
        });
    }
}
