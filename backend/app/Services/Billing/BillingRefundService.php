<?php

namespace App\Services\Billing;

use App\Models\ClassSession;
use App\Models\Invoice;
use App\Models\InvoiceItem;
use App\Models\Payment;
use App\Models\Schedule;
use App\Models\SecurityAuditEvent;
use App\Models\StudentClass;
use App\Services\InvoiceAmountReconciliationService;
use App\Services\SessionDeductionService;
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
    private const CANCEL_NOTE = '[退費取消]';

    public function __construct(private InvoiceAmountReconciliationService $amounts)
    {
    }

    /** @return array{paid:int,used_lessons:int,rate:int|float,refundable:int} server-side truth; the UI shows exactly this. */
    public function preview(StudentClass $course): array
    {
        $this->assertRefundable($course);
        $paid = 0;
        foreach ($this->invoices($course, false) as $invoice) {
            $row = $this->amounts->resolve($invoice, $course);
            if ($row['outstanding_amount'] > 0) {
                throw ValidationException::withMessages(['course' => '這門課還有未繳金額，請先在帳務處理完再退費。']);
            }
            if (InvoiceItem::query()->where('InvoiceID', $invoice->getKey())->where('StudentClassID', '!=', $course->getKey())->exists()) {
                throw ValidationException::withMessages(['course' => '這張帳單還包含別的課程，不能在這裡退費，請走帳務流程。']);
            }
            if ($row['overpaid_amount'] > 0) {
                throw ValidationException::withMessages(['course' => '這門課有多收的錢，請先在帳務處理多收，再退費。']);
            }
            $paid += (int) $row['net_applied'];
        }
        // Same lesson count the app deducts (attendance + ledger evidence); an absence without deduction is not used.
        $diag = SessionDeductionService::batchExpectedUsedSessionDiagnostics([(int) $course->getKey()])[(int) $course->getKey()] ?? [];
        if (!empty($diag['has_partial'])) {
            throw ValidationException::withMessages(['course' => '這門課有部分時數的扣堂，不能在這裡算退費，請走帳務流程。']);
        }
        $used = (int) ($diag['expected_used'] ?? 0);
        $rate = max(0.0, (float) ($course->Rate ?? 0));
        if ($used > 0 && $rate <= 0) {
            throw ValidationException::withMessages(['course' => '這門課沒有每堂單價，無法算出已上課的金額，請先到課程管理設定單價。']);
        } // keep fractions; round only the consumed value
        return ['paid' => $paid, 'used_lessons' => $used, 'rate' => $rate == floor($rate) ? (int) $rate : $rate,
            'refundable' => max(0, $paid - (int) round($used * $rate))];
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
                // Method 'refund' tells readers this money-out row is not a receipt void; the way paid back is kept in the note.
                $payment = Payment::create(['InvoiceID' => $invoice->id, 'Amount' => -$take, 'PaidAt' => $date, 'Method' => 'refund',
                    'Note' => mb_substr('退費：' . ($method === 'cash' ? '現金' : '匯款') . '，' . $reason, 0, 255)]);
                // Credit line: the lines still add up to the new TotalAmount, and the slip shows the refund.
                InvoiceItem::create(['InvoiceID' => $invoice->id, 'StudentClassID' => $locked->getKey(), 'Description' => '退費', 'Amount' => -$take]);
                $invoice->TotalAmount = max(0, (int) $invoice->TotalAmount - $take);
                $invoice->PaidAmount = max(0, (int) $invoice->PaidAmount - $take);
                $invoice->save();
                $ids[] = (int) $payment->id;
                $left -= $take;
            }
            $today = Carbon::today()->toDateString();
            // A lesson already signed in (not voided) is consumed: keep its status and deduction.
            ClassSession::query()->where('StudentClassID', $locked->getKey())->where('Status', 'scheduled')
                ->whereDate('SessionDate', '>=', $today)
                ->whereNotExists(fn ($q) => $q->selectRaw('1')->from('StudentSingIn')->whereColumn('StudentSingIn.ClassSessionID', 'ClassSession.id')->whereNull('StudentSingIn.VoidedAt'))
                ->lockForUpdate()->get()->each(function (ClassSession $s): void {
                    $s->Status = 'cancelled';
                    $s->Note = trim(trim((string) ($s->Note ?? '')) . ' ' . self::CANCEL_NOTE);
                    $s->save();
                });
            Schedule::query()->where('student_course_id', $locked->getKey())->where('status', 'scheduled')
                ->whereDate('schedule_date', '>=', $today)->update(['status' => 'cancelled', 'updated_at' => now()]);
            $old = ['session_count' => (int) ($locked->SessionCount ?? 0), 'charge' => (int) ($locked->Charge ?? 0)];
            // Entitlement follows what was used (like the #4846 amendment): nothing left, and the amount follows the count.
            $locked->SessionCount = $p['used_lessons'];
            $locked->UsedSessions = $p['used_lessons'];
            $locked->Charge = (int) round($p['used_lessons'] * (float) ($locked->Rate ?? 0));
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
                'old_session_count' => $old['session_count'], 'new_session_count' => $p['used_lessons'],
                'old_charge' => $old['charge'], 'new_charge' => (int) $locked->Charge,
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
        if ((string) $course->rate_unit === 'hour') {
            throw ValidationException::withMessages(['course' => '依時數計價的課程暫不支援退費，請走帳務流程處理。']);
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
