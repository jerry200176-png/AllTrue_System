<?php

namespace App\Services\Billing;

use App\Models\ClassSession;
use App\Models\Invoice;
use App\Models\Payment;
use App\Models\Schedule;
use App\Models\SecurityAuditEvent;
use App\Models\StudentClass;
use App\Services\InvoiceAmountReconciliationService;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * #4843 / in-app #473: refund a lesson-count (堂數制) contract and end it as 已退費.
 *
 * Books balance the credit-note way: each refunded dollar is a negative Payment row (money out) AND a matching cut of the
 * invoice's TotalAmount/PaidAmount (what is owed), so InvoiceAmountReconciliationService still reads total == net applied
 * and the resolver says settled. No schema change.
 */
final class BillingRefundService
{
    public const CLOSED_REASON = 'refunded';
    private const HELD = ['attended', 'completed', 'late', 'absent'];
    private const CANCEL_NOTE = '[退費取消]';

    public function __construct(private InvoiceAmountReconciliationService $amounts)
    {
    }

    /** @return array{paid:int,used_lessons:int,rate:int,refundable:int} server-side truth; the UI shows exactly this. */
    public function preview(StudentClass $course): array
    {
        $this->assertRefundable($course);
        $paid = 0;
        foreach ($this->invoices($course, false) as $invoice) {
            $paid += (int) $this->amounts->resolve($invoice, $course)['net_applied'];
        }
        $used = ClassSession::query()->where('StudentClassID', $course->getKey())->whereIn('Status', self::HELD)->count();
        $rate = max(0, (int) ($course->Rate ?? 0));

        return ['paid' => $paid, 'used_lessons' => $used, 'rate' => $rate, 'refundable' => max(0, $paid - $used * $rate)];
    }

    public function refund(StudentClass $course, int $amount, string $method, string $date, string $reason, ?int $actorId): array
    {
        return DB::transaction(function () use ($course, $amount, $method, $date, $reason, $actorId): array {
            $locked = StudentClass::query()->where('ID', $course->getKey())->lockForUpdate()->firstOrFail();
            $p = $this->preview($locked); // re-read under the lock: never trust the client's numbers
            if ($amount <= 0 || $amount > $p['refundable']) {
                throw ValidationException::withMessages(['amount' => '退費金額不能超過可退金額 ' . $p['refundable'] . ' 元。']);
            }
            $left = $amount;
            $ids = [];
            foreach ($this->invoices($locked, true)->sortByDesc('id') as $invoice) {
                $take = min($left, (int) $this->amounts->resolve($invoice, $locked)['net_applied']);
                if ($take <= 0) {
                    continue;
                }
                $payment = Payment::create(['InvoiceID' => $invoice->id, 'Amount' => -$take, 'PaidAt' => $date, 'Method' => $method,
                    'Note' => mb_substr('退費：' . $reason, 0, 255)]);
                $invoice->TotalAmount = max(0, (int) $invoice->TotalAmount - $take);
                $invoice->PaidAmount = max(0, (int) $invoice->PaidAmount - $take);
                $invoice->save();
                $ids[] = (int) $payment->id;
                $left -= $take;
            }
            $today = Carbon::today()->toDateString();
            ClassSession::query()->where('StudentClassID', $locked->getKey())->where('Status', 'scheduled')
                ->whereDate('SessionDate', '>=', $today)->lockForUpdate()->get()->each(function (ClassSession $s): void {
                    $s->Status = 'cancelled';
                    $s->Note = trim(trim((string) ($s->Note ?? '')) . ' ' . self::CANCEL_NOTE);
                    $s->save();
                });
            Schedule::query()->where('student_course_id', $locked->getKey())->where('status', 'scheduled')
                ->whereDate('schedule_date', '>=', $today)->update(['status' => 'cancelled', 'updated_at' => now()]);
            $locked->Stop = 1;
            $locked->EndDate = $today;
            $locked->RemainingSessions = 0;
            $locked->closed_reason = self::CLOSED_REASON;
            $locked->save();
            SecurityAuditEvent::append('student_class.refund', 'success', [
                'campus_id' => $locked->student?->CampusID, 'actor_type' => 'user', 'actor_id' => $actorId,
                'subject_type' => 'student_class', 'subject_id' => (int) $locked->getKey(),
            ], [
                'amount' => $amount, 'refundable' => $p['refundable'], 'paid' => $p['paid'], 'row_count' => count($ids),
                'method' => $method, 'reason_code' => self::CLOSED_REASON, 'reason_hash' => hash('sha256', $reason), 'outcome' => 'success',
            ], required: true); // no audit row, no refund: the transaction rolls back

            return ['message' => '已退費，這門課已結束。', 'amount' => $amount, 'payment_ids' => $ids];
        });
    }

    private function assertRefundable(StudentClass $course): void
    {
        if ((string) ($course->ScheduleMode ?? 'count') !== 'count') {
            throw ValidationException::withMessages(['course' => '月結課程暫不支援退費，請走帳務流程處理。']);
        }
        if ($course->isPartOfPackage()) {
            throw ValidationException::withMessages(['course' => '共用課程包請走方案調整流程，不能單獨退費。']);
        }
        if ((string) $course->getAttribute('closed_reason') === self::CLOSED_REASON) {
            abort(409, '這門課已經退過費。');
        }
        if ((int) ($course->Stop ?? 0) === 1) {
            throw ValidationException::withMessages(['course' => '這門課已結束或暫停，不能退費。']);
        }
    }

    private function invoices(StudentClass $course, bool $lock)
    {
        $q = Invoice::query()->where('StudentClassID', $course->getKey())->scopes('notVoided');

        return ($lock ? $q->lockForUpdate() : $q)->with('payments')->get();
    }
}
