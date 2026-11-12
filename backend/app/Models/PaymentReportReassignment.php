<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * @property int $id
 * @property int $from_report_id
 * @property int $to_report_id
 * @property int|null $from_invoice_id
 * @property int $to_invoice_id
 * @property int $from_student_id
 * @property int $to_student_id
 * @property int $campus_id
 * @property int $amount
 * @property string $reason
 * @property string $idempotency_key
 * @property int $actor_user_id
 * @property \Illuminate\Support\Carbon|null $created_at
 * @method static static create(array $attributes = [])
 *
 * #5113 (in-app #522): append-only link row of one "move a confirmed receipt to the correct bill"
 * (voided source receipt -> new receipt of record -> both bills -> both students -> campus -> amount -> actor).
 * Persistence only: the money is the ordinary Payment rows the two receipts point at, and every rule
 * (campus scope, limits, idempotency replay) belongs to the service that writes this row, not to this model.
 * Rows are never updated, so there is no updated_at.
 */
class PaymentReportReassignment extends Model
{
    public const UPDATED_AT = null;

    protected $table = 'payment_report_reassignments';

    protected $guarded = [];

    protected $casts = [
        'from_report_id' => 'integer',
        'to_report_id' => 'integer',
        'from_invoice_id' => 'integer',
        'to_invoice_id' => 'integer',
        'from_student_id' => 'integer',
        'to_student_id' => 'integer',
        'campus_id' => 'integer',
        'amount' => 'integer',
        'actor_user_id' => 'integer',
        'created_at' => 'datetime',
    ];

    /** @return \Illuminate\Database\Eloquent\Relations\BelongsTo<PaymentReport, $this> */
    public function fromReport()
    {
        return $this->belongsTo(PaymentReport::class, 'from_report_id', 'id');
    }

    /** @return \Illuminate\Database\Eloquent\Relations\BelongsTo<PaymentReport, $this> */
    public function toReport()
    {
        return $this->belongsTo(PaymentReport::class, 'to_report_id', 'id');
    }

    /** @return \Illuminate\Database\Eloquent\Relations\BelongsTo<Invoice, $this> */
    public function fromInvoice()
    {
        return $this->belongsTo(Invoice::class, 'from_invoice_id', 'id');
    }

    /** @return \Illuminate\Database\Eloquent\Relations\BelongsTo<Invoice, $this> */
    public function toInvoice()
    {
        return $this->belongsTo(Invoice::class, 'to_invoice_id', 'id');
    }

    /** @return \Illuminate\Database\Eloquent\Relations\BelongsTo<Student, $this> */
    public function fromStudent()
    {
        return $this->belongsTo(Student::class, 'from_student_id', 'id');
    }

    /** @return \Illuminate\Database\Eloquent\Relations\BelongsTo<Student, $this> */
    public function toStudent()
    {
        return $this->belongsTo(Student::class, 'to_student_id', 'id');
    }

    /** @return \Illuminate\Database\Eloquent\Relations\BelongsTo<Campus, $this> */
    public function campus()
    {
        return $this->belongsTo(Campus::class, 'campus_id', 'id');
    }

    /** @return \Illuminate\Database\Eloquent\Relations\BelongsTo<User, $this> */
    public function actor()
    {
        return $this->belongsTo(User::class, 'actor_user_id', 'id');
    }
}
