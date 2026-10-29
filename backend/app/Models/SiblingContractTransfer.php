<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * @property int $id
 * @property string $idempotency_key
 * @property int $campus_id
 * @property int $source_student_class_id
 * @property int $target_student_class_id
 * @property int $source_student_id
 * @property int $target_student_id
 * @property int $purchased_sessions
 * @property int $moved_sessions
 * @property int $paid_amount
 * @property int $amount
 * @property int $transfer_out_payment_id
 * @property int $transfer_in_payment_id
 * @property string $reason
 * @property int $created_by
 * @property \Illuminate\Support\Carbon|null $reversed_at
 * @property \Illuminate\Support\Carbon|null $created_at
 * @method static static create(array $attributes = [])
 *
 * In-app #412 / #4153: append-only link row of one sibling transfer (source contract -> new target contract -> amount
 * -> actor). The money itself is the transfer_out / transfer_in Payment pair it points at.
 */
class SiblingContractTransfer extends Model
{
    protected $table = 'contract_sibling_transfers';

    protected $guarded = [];

    protected $casts = ['reversed_at' => 'datetime'];
}
