<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * @property int $id
 * @property int $campus_id
 * @property int $student_id
 * @property string $category
 * @property string $title
 * @property int $amount
 * @property \Carbon\Carbon|null $charged_on
 * @property \Carbon\Carbon|null $paid_on
 * @property string|null $paid_method
 * @property \Carbon\Carbon|null $voided_at
 * @property string|null $void_reason
 * @property-read Student|null $student
 * @method static static create(array $attributes = [])
 *
 * In-app #439: a pass-through fee (代收) owed by one student. Never tuition: it has no Invoice, Payment or course,
 * so course paid state, AR, revenue and the GL export never read it. Moves only forward: unpaid -> paid -> voided.
 */
class FeeCharge extends Model
{
    public const CATEGORIES = ['books' => '書籍費', 'materials' => '教材費', 'sundry' => '雜費', 'other' => '其他'];
    public const METHODS = ['cash' => '現金', 'transfer' => '匯款', 'line_pay' => 'LINE Pay', 'other' => '其他'];

    protected $table = 'fee_charges';

    protected $fillable = [
        'campus_id', 'student_id', 'category', 'title', 'amount', 'charged_on', 'created_by',
        'paid_on', 'paid_method', 'paid_by', 'voided_at', 'voided_by', 'void_reason',
    ];

    protected $casts = [
        'amount' => 'integer',
        'charged_on' => 'date:Y-m-d',
        'paid_on' => 'date:Y-m-d',
        'voided_at' => 'datetime',
    ];

    public function student()
    {
        return $this->belongsTo(Student::class, 'student_id');
    }

    public function status(): string
    {
        return $this->voided_at !== null ? 'voided' : ($this->paid_on !== null ? 'paid' : 'unpaid');
    }
}
