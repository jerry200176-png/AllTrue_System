<?php

namespace App\Services;

use App\Models\ClassSession;
use App\Models\Invoice;
use App\Models\Payment;
use App\Models\StudentClass;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Usage-based early settlement for unpaid / partial courses.
 *
 * Billable = attended + late (+ completed alias). Cancel remaining scheduled,
 * stop course, void unpaid $0-paid invoices, open settlement receivable.
 */
class EarlySettlementService
{
    public const BILLABLE_STATUSES = ['attended', 'late', 'completed'];

    public const CLOSED_REASON = 'usage_settled';

    /**
     * @return array<string, mixed>
     */
    public function preview(StudentClass $course): array
    {
        $this->assertEligible($course);

        $billable = $this->countBillableSessions((int) $course->ID);
        $rate = (int) round((float) ($course->Rate ?? 0));
        $computed = max(0, $billable * $rate);
        $paidSoFar = $this->paidSoFar((int) $course->ID);
        $outstanding = max(0, $computed - $paidSoFar);

        return [
            'student_class_id' => (int) $course->ID,
            'schedule_mode' => (string) ($course->ScheduleMode ?? 'count'),
            'original_session_count' => (int) ($course->SessionCount ?? 0),
            'billable_sessions' => $billable,
            'rate' => $rate,
            'computed_amount' => $computed,
            'paid_so_far' => $paidSoFar,
            'outstanding' => $outstanding,
            'remaining_scheduled' => $this->countRemainingScheduled((int) $course->ID),
            'eligible' => true,
        ];
    }

    /**
     * @param  array{amount?:int|null,adjustment_reason?:string|null,actor_user_id?:int|null}  $options
     * @return array<string, mixed>
     */
    public function settle(StudentClass $course, array $options = []): array
    {
        $this->assertEligible($course);

        $preview = $this->preview($course);
        $computed = (int) $preview['computed_amount'];
        $amount = array_key_exists('amount', $options) && $options['amount'] !== null
            ? (int) $options['amount']
            : $computed;
        if ($amount < 0) {
            throw ValidationException::withMessages(['amount' => '結清金額不可為負數']);
        }

        $reason = trim((string) ($options['adjustment_reason'] ?? ''));
        if ($amount !== $computed && $reason === '') {
            throw ValidationException::withMessages([
                'adjustment_reason' => '調整結清金額時必須填寫原因',
            ]);
        }

        $actorId = (int) ($options['actor_user_id'] ?? 0);
        $today = Carbon::today('Asia/Taipei')->toDateString();

        return DB::transaction(function () use ($course, $preview, $computed, $amount, $reason, $actorId, $today) {
            /** @var StudentClass $locked */
            $locked = StudentClass::where('ID', $course->ID)->lockForUpdate()->firstOrFail();
            $this->assertEligible($locked);

            $paidSoFar = $this->paidSoFar((int) $locked->ID);
            $voidedIds = $this->voidUnpaidZeroInvoices((int) $locked->ID, $actorId);
            $this->capPartialInvoices((int) $locked->ID, $actorId);

            $outstanding = max(0, $amount - $paidSoFar);
            $settlementInvoice = null;
            if ($outstanding > 0) {
                $settlementInvoice = Invoice::create([
                    'StudentID' => (int) $locked->StudentID,
                    'StudentClassID' => (int) $locked->ID,
                    'IssueDate' => $today,
                    'DueDate' => $today,
                    'TotalAmount' => $outstanding,
                    'PaidAmount' => 0,
                    'Status' => 'unpaid',
                    'Note' => sprintf(
                        '[usage_settlement] billable=%d rate=%d computed=%d final=%d paid_so_far=%d',
                        (int) $preview['billable_sessions'],
                        (int) $preview['rate'],
                        $computed,
                        $amount,
                        $paidSoFar
                    ),
                    'billing_period' => Carbon::today('Asia/Taipei')->format('Y-m'),
                ]);
            }

            $cancelled = 0;
            $scheduled = ClassSession::where('StudentClassID', (int) $locked->ID)
                ->where('Status', 'scheduled')
                ->lockForUpdate()
                ->get();
            foreach ($scheduled as $session) {
                $session->Status = 'cancelled';
                $note = trim((string) ($session->Note ?? ''));
                $tag = '[提前結清取消]';
                if (!str_contains($note, $tag)) {
                    $session->Note = trim($note . ' ' . $tag);
                }
                $session->save();
                $cancelled++;
            }

            $snapshot = [
                'at' => now('Asia/Taipei')->toIso8601String(),
                'actor_user_id' => $actorId ?: null,
                'original_session_count' => (int) ($locked->SessionCount ?? 0),
                'original_charge' => (int) ($locked->Charge ?? 0),
                'billable_sessions' => (int) $preview['billable_sessions'],
                'rate' => (int) $preview['rate'],
                'computed_amount' => $computed,
                'final_amount' => $amount,
                'paid_so_far' => $paidSoFar,
                'outstanding' => $outstanding,
                'adjustment_reason' => $reason !== '' ? $reason : null,
                'voided_invoice_ids' => $voidedIds,
                'settlement_invoice_id' => $settlementInvoice?->id,
                'cancelled_scheduled' => $cancelled,
            ];

            $locked->Charge = $amount;
            $locked->UsedSessions = (int) $preview['billable_sessions'];
            $locked->RemainingSessions = 0;
            $locked->Stop = 1;
            $locked->closed_reason = self::CLOSED_REASON;
            $locked->EndDate = $today;
            $locked->settlement_locked_at = now();
            $locked->settlement_snapshot = json_encode($snapshot, JSON_UNESCAPED_UNICODE);
            if ($outstanding <= 0) {
                $locked->Paid = 1;
                $locked->PayDate = $today;
            } else {
                $locked->Paid = 0;
            }
            $locked->save();

            return [
                'message' => $outstanding > 0
                    ? sprintf(
                        '已提前結清：計費 %d 堂，結清應收 %d 元（尚欠 %d）。剩餘排課已取消，點名已鎖定。',
                        $preview['billable_sessions'],
                        $amount,
                        $outstanding
                    )
                    : sprintf(
                        '已提前結清：計費 %d 堂，結清金額 %d 元（已繳足以沖銷）。剩餘排課已取消，點名已鎖定。',
                        $preview['billable_sessions'],
                        $amount
                    ),
                'preview' => $preview,
                'final_amount' => $amount,
                'outstanding' => $outstanding,
                'cancelled_scheduled' => $cancelled,
                'voided_invoice_ids' => $voidedIds,
                'settlement_invoice_id' => $settlementInvoice?->id,
                'closed_reason' => self::CLOSED_REASON,
                'settlement_locked_at' => optional($locked->settlement_locked_at)->toIso8601String(),
                'snapshot' => $snapshot,
            ];
        });
    }

    public function isLocked(StudentClass $course): bool
    {
        if (!empty($course->settlement_locked_at)) {
            return true;
        }

        return (string) ($course->closed_reason ?? '') === self::CLOSED_REASON;
    }

    private function assertEligible(StudentClass $course): void
    {
        if ($this->isLocked($course)) {
            throw ValidationException::withMessages([
                'student_class_id' => '此課程已提前結清並鎖定，不可再次結清。',
            ]);
        }

        $paidSoFar = $this->paidSoFar((int) $course->ID);
        $charge = (int) ($course->Charge ?? 0);
        $hasOpen = $this->hasOpenReceivable((int) $course->ID);
        $paidFlag = (int) ($course->Paid ?? 0) === 1;

        // Fully paid with no open receivable → use 結案／暫停, not usage settlement.
        if ($paidFlag && !$hasOpen && ($charge <= 0 || $paidSoFar >= $charge)) {
            throw ValidationException::withMessages([
                'student_class_id' => '已繳清課程不可使用提前結清；請用「結案（不續報）」或暫停。',
            ]);
        }
    }

    private function hasOpenReceivable(int $studentClassId): bool
    {
        return Invoice::query()
            ->where('StudentClassID', $studentClassId)
            ->whereIn('Status', ['unpaid', 'partial'])
            ->whereColumn('PaidAmount', '<', 'TotalAmount')
            ->exists();
    }

    private function countBillableSessions(int $studentClassId): int
    {
        return (int) ClassSession::query()
            ->where('StudentClassID', $studentClassId)
            ->whereIn('Status', self::BILLABLE_STATUSES)
            ->count();
    }

    private function countRemainingScheduled(int $studentClassId): int
    {
        return (int) ClassSession::query()
            ->where('StudentClassID', $studentClassId)
            ->where('Status', 'scheduled')
            ->count();
    }

    private function paidSoFar(int $studentClassId): int
    {
        $invoiceIds = Invoice::query()
            ->where('StudentClassID', $studentClassId)
            ->where(function ($q) {
                $q->whereNull('Status')->orWhere('Status', '!=', 'void');
            })
            ->pluck('id');

        if ($invoiceIds->isEmpty()) {
            return 0;
        }

        $credits = (int) Payment::query()
            ->whereIn('InvoiceID', $invoiceIds)
            ->where('Method', '!=', 'void')
            ->where('Amount', '>', 0)
            ->sum('Amount');

        $debits = (int) abs((float) Payment::query()
            ->whereIn('InvoiceID', $invoiceIds)
            ->where(function ($q) {
                $q->where('Method', 'void')->orWhere('Amount', '<', 0);
            })
            ->sum('Amount'));

        return max(0, $credits - $debits);
    }

    /**
     * @return list<int>
     */
    private function voidUnpaidZeroInvoices(int $studentClassId, int $actorId): array
    {
        $ids = [];
        $invoices = Invoice::query()
            ->where('StudentClassID', $studentClassId)
            ->where(function ($q) {
                $q->whereNull('Status')->orWhereNotIn('Status', ['void', 'paid']);
            })
            ->lockForUpdate()
            ->get();

        foreach ($invoices as $invoice) {
            $paidAmount = (int) ($invoice->PaidAmount ?? 0);
            $status = strtolower((string) ($invoice->Status ?? ''));
            if ($paidAmount > 0 || in_array($status, ['paid', 'partial'], true)) {
                continue;
            }
            if ($status === 'void') {
                continue;
            }

            $audit = sprintf(
                '[void: usage_settlement supersede; user_id=%s; at=%s]',
                $actorId ?: 'system',
                now()->format('Y-m-d H:i:s')
            );
            $invoice->Status = 'void';
            $invoice->Note = trim((string) ($invoice->Note ?? '') . ' ' . $audit);
            $invoice->save();
            $ids[] = (int) $invoice->id;
        }

        return $ids;
    }

    /**
     * Partial invoices keep applied cash; shrink TotalAmount to PaidAmount so they close.
     * Remaining AR is represented by the new settlement invoice.
     */
    private function capPartialInvoices(int $studentClassId, int $actorId): void
    {
        $invoices = Invoice::query()
            ->where('StudentClassID', $studentClassId)
            ->where('Status', 'partial')
            ->lockForUpdate()
            ->get();

        foreach ($invoices as $invoice) {
            $paidAmount = (int) ($invoice->PaidAmount ?? 0);
            if ($paidAmount <= 0) {
                continue;
            }
            $invoice->TotalAmount = $paidAmount;
            $invoice->Status = 'paid';
            $invoice->Note = trim((string) ($invoice->Note ?? '') . sprintf(
                ' [usage_settlement: capped total→paid=%d; user_id=%s; at=%s]',
                $paidAmount,
                $actorId ?: 'system',
                now()->format('Y-m-d H:i:s')
            ));
            $invoice->save();
        }
    }
}
