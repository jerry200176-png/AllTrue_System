<?php

namespace App\Services\Billing;

use App\Models\ClassSession;
use App\Models\Invoice;
use App\Models\InvoiceItem;
use App\Models\Payment;
use App\Models\PaymentReport;
use App\Models\SecurityAuditEvent;
use App\Models\SiblingContractTransfer;
use App\Models\Student;
use App\Models\StudentClass;
use App\Models\StudentGuardian;
use App\Services\Scheduling\ContractSessionSchedule;
use App\Services\Scheduling\DeductionBasis;
use App\Services\SessionDeductionService;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * In-app #412 / #4153 (Founder 2026-10-29, 「全照推薦」): move a contract's remaining sessions to a sibling at the
 * same campus, pro-rata of what was actually paid.
 *
 * Money: the amount is read from {@see ContractMoneyVerdict} (net applied on the contract's one live invoice), never
 * from today's price. The source keeps round(paid x kept / purchased); the sibling gets the rest, so the two contracts
 * always add up to exactly what was paid. The carry-over is the existing 轉課 payment pair (source Payment -X
 * 'transfer_out', sibling Payment +X 'transfer_in'); the original Payment / receipt is never edited. One
 * {@see SiblingContractTransfer} row links source -> target -> amount -> actor.
 *
 * Shrinking the source SessionCount after deduction history is a named exception to {@see \App\Services\Scheduling\BillingContractLockGuard}
 * (as 轉課 split-contract is): the sessions leave together with their price, so nothing used is re-interpreted.
 * v1 refuses anything that is not a plain paid contract, with a code and a reason.
 */
final class SiblingContractTransferService
{
    /** Methods that mean an earlier transfer already touched this contract's money (no chained transfers in v1). */
    private const TRANSFER_METHODS = ['transfer_in', 'transfer_out'];

    public function __construct(
        private ContractMoneyVerdict $verdict,
        private ContractSessionSchedule $schedule,
        private ContractRenewal $renewal,
    ) {
    }

    /**
     * Pro-rata split of what was paid. Integer round-half-up of paid x (purchased - moving) / purchased.
     *
     * @return array{kept:int, moved:int}
     */
    public static function split(int $paid, int $purchased, int $moving): array
    {
        $kept = intdiv(2 * $paid * ($purchased - $moving) + $purchased, 2 * $purchased);

        return ['kept' => $kept, 'moved' => $paid - $kept];
    }

    /**
     * @param array{target_student_id:int, sessions?:int|null, reason:string, idempotency_key:string, acknowledge_no_family_link?:bool} $in
     * @return array{status:int, body:array<string, mixed>}
     */
    public function run(StudentClass $course, array $in, int $actorId, bool $preview): array
    {
        DB::beginTransaction();
        try {
            $out = $this->apply((int) $course->getKey(), $in, $actorId, $preview);
            $preview ? DB::rollBack() : DB::commit();

            return $out;
        } catch (\Throwable $e) {
            DB::rollBack();
            throw $e;
        }
    }

    private function apply(int $courseId, array $in, int $actorId, bool $preview): array
    {
        // Lock order = purgeStudentRecords: students (by id) first, so a purge of the recipient waits for this transfer
        // (or this transfer sees it gone), then the source contract. Every writer locks the source contract, so a retry
        // or a double click waits there and then sees the committed row.
        $course = StudentClass::query()->where('ID', $courseId)->first() ?? abort(404);
        Student::query()->whereIn('id', [(int) $course->StudentID, (int) $in['target_student_id']])->orderBy('id')->lockForUpdate()->get(['id']);
        $source = StudentClass::query()->where('ID', $courseId)->lockForUpdate()->first() ?? abort(404);
        // The stored key is scoped to the source contract: the same client key on two contracts can never collide.
        $key = hash('sha256', $courseId . '|' . $in['idempotency_key']);

        $prior = SiblingContractTransfer::query()->where('idempotency_key', $key)->first();
        if ($prior) {
            if ((int) $prior->target_student_id !== (int) $in['target_student_id']) {
                $this->refuse('sibling_transfer_key_reused', '這個送出代碼已用在另一筆轉讓，請重新整理後再試。', 409);
            }

            return ['status' => 200, 'body' => ['replayed' => true, 'transfer' => $this->describe($prior)]];
        }
        if (SiblingContractTransfer::query()->where('source_student_class_id', $courseId)->whereNull('reversed_at')->exists()) {
            $this->refuse('sibling_transfer_already_done', '這份合約已經轉讓過剩餘堂數，不能再轉讓一次。', 409);
        }

        $target = $this->assertSiblingTarget($source, (int) $in['target_student_id']);
        $familyLink = $this->hasFamilyLink($source, $target);
        if (!$preview && !$familyLink && empty($in['acknowledge_no_family_link'])) {
            $this->refuse('sibling_transfer_no_family_link', '兩位學生沒有共同的家長／監護人紀錄。確認是手足後，請勾選確認再送出。');
        }

        [$invoice, $paid] = $this->assertSimplePaidContract($source);
        $purchased = (int) $source->getAttribute('SessionCount');
        // Same canonical usage as the counters; a makeup deducted by real minutes, or usage artifacts to reconcile,
        // cannot be turned into whole transferable sessions here.
        $usage = SessionDeductionService::batchExpectedUsedSessionDiagnostics([$courseId])[$courseId] ?? null;
        if (!$usage || $usage['has_partial'] || $usage['cancelled_usage_artifacts'] > 0) {
            $this->refuse('sibling_transfer_usage_unclear', '這份合約有依實際分鐘扣堂的補課，或有待核對的扣堂紀錄，請先核對扣堂後再轉讓。');
        }
        $used = (int) $usage['expected_used'];
        $remaining = $purchased - $used;
        $moving = (int) ($in['sessions'] ?? $remaining);
        if ($remaining < 1 || $moving < 1) {
            $this->refuse('sibling_transfer_no_remaining', '這份合約沒有剩餘堂數可以轉讓。');
        }
        if ($moving > $remaining) {
            $this->refuse('sibling_transfer_over_remaining', "要轉的堂數（{$moving}）超過剩餘堂數（{$remaining}）。", 422, ['remaining_sessions' => $remaining]);
        }
        if ($purchased - $moving < 1) {
            $this->refuse('sibling_transfer_source_would_be_empty', '這份合約還沒上過課，不能把堂數全部轉走；請改走取消合約／退費。');
        }
        if (ClassSession::query()->where('StudentClassID', $courseId)->where('Status', 'scheduled')
            ->whereDate('SessionDate', '<', Carbon::today()->toDateString())->exists()) {
            $this->refuse('sibling_transfer_unmarked_sessions', '這份合約有已過期但還沒點名的堂次，請先點名或取消後再轉讓。');
        }
        ['kept' => $kept, 'moved' => $amount] = self::split($paid, $purchased, $moving);
        if ($amount < 1) {
            $this->refuse('sibling_transfer_nothing_to_move', '依已繳金額換算，這次轉出的金額為 0。');
        }

        $plan = [
            'purchased_sessions' => $purchased, 'used_sessions' => $used, 'remaining_sessions' => $remaining,
            'moved_sessions' => $moving, 'paid_amount' => $paid, 'amount' => $amount, 'source_keeps_amount' => $kept,
            'family_link' => $familyLink,
        ];
        if ($preview) {
            return ['status' => 200, 'body' => ['preview' => true, 'plan' => $plan]];
        }

        $transfer = $this->write($source, $target, $invoice, $plan, $in, $actorId, $key);
        SecurityAuditEvent::append('student_class.sibling_transfer', 'success', [
            'campus_id' => (int) $target->CampusID, 'actor_type' => 'user', 'actor_id' => $actorId,
            'subject_type' => 'student_class', 'subject_id' => $courseId,
        ], [
            'source_course_id' => $courseId, 'target_course_id' => (int) $transfer->target_student_class_id,
            'target_student_id' => (int) $target->id, 'transfer_id' => (int) $transfer->id,
            'moved_sessions' => $moving, 'paid_amount' => $paid, 'amount' => $amount,
            'family_link' => $familyLink, 'reason_hash' => hash('sha256', (string) $in['reason']),
        ]);

        return ['status' => 201, 'body' => ['replayed' => false, 'plan' => $plan, 'transfer' => $this->describe($transfer)]];
    }

    private function assertSiblingTarget(StudentClass $source, int $targetStudentId): Student
    {
        $sourceStudent = Student::query()->where('id', $source->getAttribute('StudentID'))->first();
        $target = Student::query()->where('id', $targetStudentId)->first();
        if (!$target || !$sourceStudent || (int) $target->id === (int) $sourceStudent->id || (int) ($target->enable ?? 1) !== 1) {
            $this->refuse('sibling_transfer_bad_target', '找不到可以接收的學生，或對象就是原本的學生。');
        }
        if ((int) $target->CampusID !== (int) $sourceStudent->CampusID) {
            $this->refuse('sibling_transfer_cross_campus', '只能轉讓給同一分校的學生。');
        }

        return $target;
    }

    private function hasFamilyLink(StudentClass $source, Student $target): bool
    {
        $sourceId = (int) $source->getAttribute('StudentID');
        $viaGuardian = StudentGuardian::notRevoked()->where('student_id', $sourceId)
            ->whereIn('guardian_id', StudentGuardian::notRevoked()->where('student_id', $target->id)->select('guardian_id'))
            ->exists();
        $phone = trim((string) (Student::query()->whereKey($sourceId)->value('parent_phone') ?? ''));

        return $viaGuardian || ($phone !== '' && $phone === trim((string) ($target->parent_phone ?? '')));
    }

    /**
     * v1 scope: a plain, fully paid, session-priced, non-package contract.
     *
     * @return array{0:Invoice, 1:int} its one live (locked) invoice and the net amount paid on it
     */
    private function assertSimplePaidContract(StudentClass $c): array
    {
        $id = (int) $c->getKey();
        if ((string) ($c->ScheduleMode ?? 'count') !== 'count' || strtolower((string) ($c->rate_unit ?? 'session')) !== 'session') {
            $this->refuse('sibling_transfer_count_mode_only', '只有堂數制、按堂計費的合約可以轉讓。');
        }
        if ($c->isPartOfPackage()) {
            $this->refuse('sibling_transfer_package_forbidden', '共用課程包請使用方案調整流程。');
        }
        if (!in_array((string) ($c->ClassType ?? ''), ['one_on_one', 'one_on_two', 'one_on_three'], true)) {
            $this->refuse('sibling_transfer_class_type', '試聽或輔導課不能轉讓。');
        }
        if ((string) ($c->deduction_basis ?? DeductionBasis::FIXED_SESSION) !== DeductionBasis::FIXED_SESSION) {
            $this->refuse('sibling_transfer_actual_duration', '依實際時數扣堂的合約可能剩下零碎堂數，暫不支援轉讓。');
        }
        if ((int) ($c->Stop ?? 0) === 1 || (string) ($c->closed_reason ?? '') !== '') {
            $this->refuse('sibling_transfer_source_closed', '這份合約已停課或已結案，不能轉讓。');
        }
        if (PaymentReport::query()->where('StudentClassID', $id)->where('status', 'pending')->exists()) {
            $this->refuse('sibling_transfer_paid_locked', '這份合約有待處理的繳費回報，請先完成或作廢該筆回報。', 409);
        }
        $purchased = (int) ($c->SessionCount ?? 0);
        $simple = fn () => $this->refuse('sibling_transfer_paid_not_simple', '這份合約的收款情況較特殊（未繳清、有折扣、多張帳單、沖銷或轉課紀錄），請改用帳務更正流程。');
        $verdict = $this->verdict->forCourse($c);
        if (!$verdict || $verdict->status !== 'paid' || $verdict->source !== 'invoice' || $verdict->outstanding !== 0
            || $verdict->overpaid !== 0 || $verdict->applied < 1) {
            $simple();
        }
        $invoices = Invoice::query()->where('StudentClassID', $id)
            ->where(fn ($q) => $q->whereNull('Status')->orWhere('Status', '!=', 'void'))->lockForUpdate()->get();
        if ($invoices->count() !== 1) {
            $simple();
        }
        /** @var Invoice $invoice */
        $invoice = $invoices->first();
        $payments = Payment::query()->where('InvoiceID', $invoice->getKey())->get();
        $applied = $verdict->applied;
        if ($purchased < 1 || (int) ($c->Disconunt ?? 0) !== 0 || (int) ($c->Charge ?? 0) !== $applied
            || (int) $invoice->TotalAmount !== $applied || (int) $invoice->PaidAmount !== $applied
            || $payments->contains(fn ($p) => (int) $p->Amount <= 0 || (string) $p->Method === 'void' || in_array((string) $p->Method, self::TRANSFER_METHODS, true))
            || (int) $payments->sum(fn ($p) => (int) $p->Amount) !== $applied
            || $this->invoiceItemsAreNotJustThisContract($invoice, $id)) {
            $simple();
        }

        return [$invoice, $applied];
    }

    /** One line item at most, and it must be this contract's: a split would otherwise misstate the other lines. */
    private function invoiceItemsAreNotJustThisContract(Invoice $invoice, int $courseId): bool
    {
        $items = InvoiceItem::query()->where('InvoiceID', $invoice->getKey())->get(['StudentClassID']);

        return $items->count() > 1 || $items->contains(fn ($item) => (int) $item->StudentClassID !== $courseId);
    }

    /** @param array<string, mixed> $plan */
    private function write(StudentClass $source, Student $target, Invoice $invoice, array $plan, array $in, int $actorId, string $key): SiblingContractTransfer
    {
        $srcId = (int) $source->getKey();
        $moving = (int) $plan['moved_sessions'];
        $amount = (int) $plan['amount'];
        $kept = (int) $plan['source_keeps_amount'];
        $today = Carbon::today()->toDateString();
        $paidAt = ContractSessionSchedule::normalizeDateString($source->getAttribute('PayDate')) ?: $today;
        $duration = max(30, (int) ($source->SessionDuration ?? 120));
        $remainingAfter = (int) $plan['purchased_sessions'] - $moving;

        $new = $this->renewal->createCourseRecord([
            'StudentID' => (int) $target->id, 'GradeID' => (int) ($target->ClassID ?: 1),
            'SubjectID' => (int) $source->SubjectID, 'TeacherID' => (int) $source->TeacherID,
            'by1' => (int) ($source->by1 ?? 1), 'Period' => (int) ($source->Period ?? 4),
            'StartDate' => $today, 'EndDate' => null, 'TotalHours' => (int) round(($moving * $duration) / 60),
            'Charge' => $amount, 'Pay' => $amount, 'PayDate' => $paidAt, 'Paid' => 1, 'Disconunt' => 0,
            'Rate' => (float) ($source->Rate ?? 0), 'rate_unit' => 'session', 'MDate' => now(), 'Stop' => 0,
            'ScheduleMode' => 'count', 'scheduling_policy' => 'manual_occurrence',
            'SessionCount' => $moving, 'SessionDuration' => $duration, 'RemainingSessions' => $moving, 'UsedSessions' => 0,
            'ClassType' => (string) $source->ClassType,
            'standard_lesson_minutes' => $source->standard_lesson_minutes, 'deduction_basis' => DeductionBasis::FIXED_SESSION,
        ]);
        $newId = (int) $new->getKey();

        $invoice->setAttribute('TotalAmount', $kept);
        $invoice->setAttribute('PaidAmount', $kept);
        $invoice->save();
        InvoiceItem::query()->where('InvoiceID', $invoice->getKey())->where('StudentClassID', $srcId)->update(['Amount' => $kept]);
        $out = Payment::query()->create([
            'InvoiceID' => $invoice->getKey(), 'Amount' => -$amount, 'PaidAt' => $paidAt, 'Method' => 'transfer_out',
            'Note' => "手足轉讓：轉出 {$amount} 元（{$moving} 堂）至合約#{$newId}",
        ]);
        $newInvoice = Invoice::query()->create([
            'StudentID' => (int) $target->id, 'StudentClassID' => $newId, 'IssueDate' => $today, 'DueDate' => $today,
            'TotalAmount' => $amount, 'PaidAmount' => $amount, 'ScheduleModeAtIssue' => 'count', 'Status' => 'paid',
            'Note' => "手足轉讓轉入：自合約#{$srcId}",
        ]);
        InvoiceItem::query()->create([
            'InvoiceID' => $newInvoice->getKey(), 'StudentClassID' => $newId, 'Description' => "手足轉讓轉入：{$moving} 堂", 'Amount' => $amount,
        ]);
        $in2 = Payment::query()->create([
            'InvoiceID' => $newInvoice->getKey(), 'Amount' => $amount, 'PaidAt' => $paidAt, 'Method' => 'transfer_in',
            'Note' => "手足轉讓轉入 {$amount} 元，來源合約#{$srcId} 帳單#{$invoice->getKey()} 付款#{$out->getKey()}",
        ]);

        if ((int) ($source->Pay ?? 0) === (int) $plan['paid_amount']) {
            $source->setAttribute('Pay', $kept);
        }
        $source->setAttribute('Charge', $kept);
        $source->setAttribute('SessionCount', $remainingAfter);
        $source->save();
        $this->schedule->cancelExcessScheduledSessions($srcId, $remainingAfter);
        SessionDeductionService::recomputeCounters($srcId);
        SessionDeductionService::recomputeCounters($newId);

        return SiblingContractTransfer::create([
            'idempotency_key' => $key, 'campus_id' => (int) $target->CampusID,
            'source_student_class_id' => $srcId, 'target_student_class_id' => $newId,
            'source_student_id' => (int) $source->StudentID, 'target_student_id' => (int) $target->id,
            'purchased_sessions' => (int) $plan['purchased_sessions'], 'moved_sessions' => $moving,
            'paid_amount' => (int) $plan['paid_amount'], 'amount' => $amount,
            'transfer_out_payment_id' => (int) $out->getKey(), 'transfer_in_payment_id' => (int) $in2->getKey(),
            'reason' => mb_substr((string) $in['reason'], 0, 100), 'created_by' => $actorId,
        ]);
    }

    /** @return array<string, int|string> */
    private function describe(SiblingContractTransfer $t): array
    {
        return [
            'id' => (int) $t->id, 'source_course_id' => (int) $t->source_student_class_id, 'target_course_id' => (int) $t->target_student_class_id,
            'target_student_id' => (int) $t->target_student_id, 'moved_sessions' => (int) $t->moved_sessions, 'amount' => (int) $t->amount,
            'transfer_out_payment_id' => (int) $t->transfer_out_payment_id, 'transfer_in_payment_id' => (int) $t->transfer_in_payment_id,
        ];
    }

    /** @param array<string, mixed> $extra */
    private function refuse(string $code, string $message, int $status = 422, array $extra = []): never
    {
        abort(response()->json(['message' => $message, 'code' => $code] + $extra, $status));
    }
}
