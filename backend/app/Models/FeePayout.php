<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * @property int $id
 * @property int $campus_id
 * @property string $category
 * @property string $title
 * @property int $amount
 * @property \Carbon\Carbon|null $paid_on
 * @property \Carbon\Carbon|null $voided_at
 * @property string|null $void_reason
 * @method static static create(array $attributes = [])
 *
 * In-app #439: money a campus paid out for pass-through fees (代付, e.g. to a book supplier). Created -> voided only.
 */
class FeePayout extends Model
{
    protected $table = 'fee_payouts';

    protected $fillable = ['campus_id', 'category', 'title', 'amount', 'paid_on', 'created_by', 'voided_at', 'voided_by', 'void_reason'];

    protected $casts = [
        'amount' => 'integer',
        'paid_on' => 'date:Y-m-d',
        'voided_at' => 'datetime',
    ];
}
