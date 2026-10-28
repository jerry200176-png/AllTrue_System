<?php

namespace App\Models;

use Carbon\Carbon;
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

    /**
     * The tuition slip's separate 代收款 block: this student's charges in this campus that are unpaid, plus ones paid
     * in $month ('YYYY-MM'; null or malformed = this month). Voided ones never show. Read-only and kept beside the
     * tuition fields: nothing here is summed into a tuition total.
     *
     * @return array{pass_through: list<array{title: string, category: string, amount: int, status: string, paid_on: string|null}>, pass_through_unpaid_total: int}
     */
    public static function slipBlock(int $studentId, int $campusId, ?string $month): array
    {
        $first = preg_match('/^\d{4}-(0[1-9]|1[0-2])$/', (string) $month)
            ? Carbon::createFromFormat('Y-m-d', $month . '-01')->startOfMonth()
            : Carbon::now()->startOfMonth();
        $rows = static::query()
            ->where('student_id', $studentId)->where('campus_id', $campusId)->whereNull('voided_at')
            ->where(fn ($q) => $q->whereNull('paid_on')
                ->orWhereBetween('paid_on', [$first->toDateString(), $first->copy()->endOfMonth()->toDateString()]))
            ->orderBy('charged_on')->orderBy('id')
            ->get();

        return [
            'pass_through' => $rows->map(fn (self $c) => [
                'title' => (string) $c->title,
                'category' => self::CATEGORIES[$c->category] ?? (string) $c->category,
                'amount' => (int) $c->amount,
                'status' => $c->status(),
                'paid_on' => $c->paid_on?->toDateString(),
            ])->all(),
            'pass_through_unpaid_total' => (int) $rows->filter(fn (self $c) => $c->status() === 'unpaid')->sum('amount'),
        ];
    }
}
