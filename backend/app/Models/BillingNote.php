<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
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
 * @property string $author_name  snapshot at write time
 * @property \Carbon\CarbonInterface|null $created_at
 */
class BillingNote extends Model
{
    public const SUBJECT_TYPES = ['invoice', 'payment', 'payment_report'];

    public const MAX_BODY = 1000;

    /** #4041: shown next to notes of an invoice voided because its course was deleted. */
    public const VOIDED_INVOICE_LABEL = '（帳單已作廢）';

    public const UPDATED_AT = null;

    protected $table = 'billing_notes';

    protected $fillable = ['campus_id', 'subject_type', 'subject_id', 'body', 'author_user_id', 'author_name'];

    protected $casts = [
        'campus_id' => 'integer',
        'subject_id' => 'integer',
        'author_user_id' => 'integer',
        'created_at' => 'datetime',
    ];

    /**
     * #4041 — void invoices whose course no longer exists (StudentClassController::destroy() voids the
     * unpaid invoices and then deletes the course). Their notes stay staff-visible, read-only.
     *
     * @return \Illuminate\Database\Eloquent\Builder<\App\Models\Invoice>
     */
    public static function deletedCourseVoidInvoices(): Builder
    {
        $query = Invoice::query();
        $query->where('Invoice.Status', 'void')
            ->where('Invoice.StudentID', '>', 0)
            ->where('Invoice.StudentClassID', '>', 0)
            ->whereNotExists(fn ($q) => $q->selectRaw('1')->from('StudentClass')->whereColumn('StudentClass.ID', 'Invoice.StudentClassID'))
            // A line that still points at a live course means a consolidated bill: that is not a deleted contract.
            ->whereNotExists(fn ($q) => $q->selectRaw('1')->from('InvoiceItem')
                ->join('StudentClass', 'StudentClass.ID', '=', 'InvoiceItem.StudentClassID')
                ->whereColumn('InvoiceItem.InvoiceID', 'Invoice.id'));

        return $query;
    }

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
