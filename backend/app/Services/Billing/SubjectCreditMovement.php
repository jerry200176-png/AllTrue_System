<?php

namespace App\Services\Billing;

use App\Models\{Invoice, InvoiceItem, Payment, StudentClass, SubjectCreditTransfer};
use App\Services\SessionDeductionService;
use Brick\Math\BigRational;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;

/** #535: explicit same-unit credit movement; no teaching, cash receipt or existing destination-debt mutation. */
final class SubjectCreditMovement
{
    public function __construct(private SubjectCreditPreview $preview, private ContractRenewal $renewal, private PaidContractCreditMovement $money)
    {
    }

    public function apply(StudentClass $course, array $input, int $actorId, ?array $campusIds): array
    {
        if ($actorId < 1) {
            abort(403);
        }
        return DB::transaction(function () use ($course, $input, $actorId, $campusIds) {
            $scope = $this->preview->lockScope($course, $input, $campusIds);
            $this->assertSchema();
            ['student' => $student, 'source' => $source, 'target' => $template] = $scope;
            $key = hash('sha256', $source->ID . '|' . $input['idempotency_key']);
            $fingerprint = SubjectCreditState::digest([
                'target_course_id' => (int) $input['target_course_id'], 'sessions' => (int) $input['sessions'], 'reason' => trim($input['reason']),
            ]);
            $prior = SubjectCreditTransfer::where('operation_key', $key)->lockForUpdate()->first();
            if ($prior) {
                if (!hash_equals((string) $prior->request_fingerprint, $fingerprint)) {
                    $this->refuse('subject_credit_key_reused', '這個送出代碼已用於不同的轉用內容，請重新整理。');
                }
                return ['status' => 200, 'body' => ['replayed' => true, 'transfer' => $this->describe($prior)]];
            }
            $locked = $this->preview->inspectLocked($scope, $input);
            $response = $locked['response'];
            $plan = $response['plan'];
            if (!hash_equals($response['state_hash'], $input['state_hash'])) {
                $this->refuse('subject_credit_preview_changed', '合約或排課已變動，請重新預覽後再確認。');
            }
            if ($plan['counters_require_review']) {
                $this->refuse('subject_credit_counters_require_review', '來源合約的堂數紀錄尚待核對，不能用轉用操作順便修正。');
            }
            $invoice = $locked['invoice'];
            $before = SubjectCreditState::capture($source, $invoice);
            $n = (int) $plan['moved_sessions'];
            $amount = (int) $plan['amount'];
            $minutes = (int) $plan['lesson_minutes'];
            $paidAt = Carbon::parse($source->PayDate ?: $invoice->IssueDate)->toDateString();
            $target = $this->renewal->createCourseRecord([
                'StudentID' => (int) $student->id, 'GradeID' => (int) $template->GradeID,
                'SubjectID' => (int) $template->SubjectID, 'TeacherID' => (int) $template->TeacherID,
                'by1' => (int) $template->by1, 'Period' => (int) $template->Period,
                'StartDate' => Carbon::today()->toDateString(), 'EndDate' => null, 'TotalHours' => (int) round($n * $minutes / 60),
                'Charge' => $amount, 'Pay' => $amount, 'Paid' => 1, 'PayDate' => $paidAt, 'Disconunt' => 0,
                'Rate' => (float) $template->Rate, 'rate_unit' => 'session', 'MDate' => now(), 'Stop' => 0,
                'ScheduleMode' => 'count', 'scheduling_policy' => 'manual_occurrence', 'deduction_basis' => 'fixed_session',
                'standard_lesson_minutes' => $minutes, 'ClassType' => $template->ClassType,
                'SessionCount' => $n, 'SessionDuration' => $minutes, 'UsedSessions' => 0, 'RemainingSessions' => $n,
            ])->refresh();
            $this->assertTargetPersisted($source, $template, $target, $n, $amount, $minutes);
            $movement = $this->money->move($invoice, $source, $target, $n, $amount, $paidAt, '跨科轉用');
            $remainingCount = (int) $source->SessionCount - $n;
            $kept = (int) $plan['source_keeps_amount'];
            if ((int) $source->Pay === (int) $before['invoice']['PaidAmount']) {
                $source->Pay = $kept;
            }
            $source->Charge = $kept;
            $source->SessionCount = $remainingCount;
            if ($remainingCount === (int) $plan['used_sessions']) {
                $source->Stop = 1;
                $source->closed_reason = 'completed';
            }
            $source->save();
            SessionDeductionService::recomputeCounters((int) $source->ID);
            SessionDeductionService::recomputeCounters((int) $target->ID);
            $record = SubjectCreditTransfer::create([
                'operation_key' => $key, 'request_fingerprint' => $fingerprint, 'campus_id' => (int) $student->CampusID,
                'student_id' => (int) $student->id, 'source_student_class_id' => (int) $source->ID,
                'template_student_class_id' => (int) $template->ID, 'target_student_class_id' => (int) $target->ID,
                'source_invoice_id' => (int) $invoice->id, 'target_invoice_id' => (int) $movement['invoice']->id,
                'transfer_out_payment_id' => (int) $movement['out']->id, 'transfer_in_payment_id' => (int) $movement['in']->id,
                'sessions' => $n, 'amount' => $amount, 'unit_price' => $plan['unit_price'], 'lesson_minutes' => $minutes,
                'reason' => trim($input['reason']), 'created_by' => $actorId, 'source_before' => $before,
                'source_after' => SubjectCreditState::capture($source, $invoice),
                'target_after' => SubjectCreditState::capture($target, $movement['invoice']),
            ]);
            if ((int) $source->fresh()->SessionCount + (int) $target->SessionCount !== (int) $before['course']['SessionCount']
                || (int) $invoice->fresh()->PaidAmount + (int) $movement['invoice']->PaidAmount !== (int) $before['invoice']['PaidAmount']
                || (int) $movement['in']->Amount + (int) $movement['out']->Amount !== 0) {
                throw new \LogicException('Subject credit conservation failed.');
            }
            return ['status' => 201, 'body' => ['replayed' => false, 'transfer' => $this->describe($record)]];
        }, 3); // Only transactional DB effects; Laravel may retry a deadlock without repeating external effects.
    }

    private function assertTargetPersisted(StudentClass $source, StudentClass $template, StudentClass $target, int $n, int $amount, int $minutes): void
    {
        foreach (['StudentID' => (int) $source->StudentID, 'SubjectID' => (int) $template->SubjectID,
            'TeacherID' => (int) $template->TeacherID, 'SessionCount' => $n, 'Charge' => $amount, 'Pay' => $amount,
            'Paid' => 1, 'SessionDuration' => $minutes, 'UsedSessions' => 0, 'RemainingSessions' => $n] as $key => $value) {
            if ((int) $target->getRawOriginal($key) !== $value) {
                throw new \LogicException('Subject credit target did not persist required fields.');
            }
        }
        if ($target->scheduling_policy !== 'manual_occurrence' || $target->rate_unit !== 'session'
            || $target->getRawOriginal('deduction_basis') !== 'fixed_session' || $target->ScheduleMode !== 'count'
            || !BigRational::of((string) $source->Rate)->isEqualTo((string) $target->Rate)) {
            throw new \LogicException('Subject credit target lost its pricing or scheduling contract.');
        }
    }

    public function reverse(StudentClass $course, int $id, string $reason, int $actorId, ?array $campusIds): array
    {
        if ($actorId < 1) {
            abort(403);
        }
        // Routing metadata only, outside the transaction; caller already checks source-campus access.
        $this->assertSchema();
        $route = SubjectCreditTransfer::whereKey($id)->where('source_student_class_id', $course->ID)->firstOrFail();
        return DB::transaction(function () use ($course, $route, $id, $reason, $actorId, $campusIds) {
            $scope = $this->preview->lockScope($course, ['target_course_id' => (int) $route->template_student_class_id], $campusIds, [(int) $route->target_student_class_id]);
            $this->assertSchema();
            ['student' => $student, 'source' => $source] = $scope;
            $target = StudentClass::where('ID', $route->target_student_class_id)->where('StudentID', $student->id)->lockForUpdate()->firstOrFail();
            $record = SubjectCreditTransfer::whereKey($id)->where('source_student_class_id', $source->ID)->lockForUpdate()->firstOrFail();
            if ((int) $record->student_id !== (int) $student->id || (int) $record->campus_id !== (int) $student->CampusID) {
                abort(403);
            }
            if (($record->getAttributes()['allocation_group_id'] ?? null) !== null) {
                $this->refuse('subject_credit_allocation_group', '這筆轉用屬於整批既有課程配置，請從整批紀錄還原，不能單獨還原一科。');
            }
            if ($record->reversed_at !== null) {
                if (trim($reason) !== (string) $record->reverse_reason) {
                    $this->refuse('subject_credit_reverse_reused', '這筆轉用已還原，不能更改原本的還原紀錄。');
                }
                return ['replayed' => true, 'transfer' => $this->describe($record)];
            }
            $courseIds = [(int) $source->ID, (int) $target->ID];
            $invoices = Invoice::where(fn ($q) => $q->whereIn('StudentClassID', $courseIds)
                ->orWhereHas('items', fn ($items) => $items->whereIn('StudentClassID', $courseIds)))
                ->orderBy('id')->lockForUpdate()->get()->keyBy('id');
            $from = $invoices->get((int) $record->source_invoice_id);
            $to = $invoices->get((int) $record->target_invoice_id);
            if ($invoices->count() !== 2 || !$from || !$to || (int) $from->StudentClassID !== (int) $source->ID || (int) $to->StudentClassID !== (int) $target->ID) {
                $this->refuse('subject_credit_reverse_changed', '轉用的帳單連結已變動，請聯絡總部核對。');
            }
            Payment::whereIn('InvoiceID', $invoices->keys())->orderBy('id')->lockForUpdate()->get();
            if (SubjectCreditState::lockBookings((int) $target->ID)->isNotEmpty()) {
                $this->refuse('subject_credit_reverse_used', '轉入的合約已有排課，不能在這裡還原。');
            }
            foreach (['ClassSession' => 'StudentClassID', 'StudentSingIn' => 'StudentClassID',
                'LearningRecord' => 'StudentClassID', 'session_deduction_ledger' => 'student_class_id'] as $table => $column) {
                if (DB::table($table)->where($column, $target->ID)->lockForUpdate()->get(['id'])->isNotEmpty()) {
                    $this->refuse('subject_credit_reverse_used', '轉入的合約已有排課或教學紀錄，不能在這裡還原。');
                }
            }
            if (!hash_equals(SubjectCreditState::digest($record->source_after), SubjectCreditState::digest(SubjectCreditState::capture($source, $from)))
                || !hash_equals(SubjectCreditState::digest($record->target_after), SubjectCreditState::digest(SubjectCreditState::capture($target, $to)))) {
                $this->refuse('subject_credit_reverse_changed', '轉用後的合約或帳務已變動，請聯絡總部核對。');
            }
            $amount = (int) $record->amount;
            $date = Carbon::today()->toDateString();
            $out = Payment::create(['InvoiceID' => $to->id, 'Amount' => -$amount, 'Method' => 'transfer_reversal_out', 'PaidAt' => $date,
                'Note' => "跨科轉用還原：操作#{$id}，回到合約#{$source->ID}"]);
            $back = Payment::create(['InvoiceID' => $from->id, 'Amount' => $amount, 'Method' => 'transfer_reversal_in', 'PaidAt' => $date,
                'Note' => "跨科轉用還原：操作#{$id}，自合約#{$target->ID}"]);
            $before = $record->source_before;
            $restore = array_intersect_key($before['course'], array_flip(['SessionCount', 'Charge', 'Pay', 'Paid', 'Stop', 'closed_reason', 'EndDate']));
            $source->forceFill($restore)->save();
            $from->forceFill(array_intersect_key($before['invoice'], array_flip(['TotalAmount', 'PaidAmount', 'Status'])))->save();
            foreach ($before['items'] as $item) {
                InvoiceItem::where('id', $item['id'])->where('InvoiceID', $from->id)->update(['Amount' => $item['Amount']]);
            }
            InvoiceItem::create(['InvoiceID' => $to->id, 'StudentClassID' => $target->ID, 'Amount' => -$amount, 'Description' => "跨科轉用還原：操作#{$id}"]);
            $to->forceFill(['TotalAmount' => 0, 'PaidAmount' => 0, 'Status' => 'void'])->save();
            $target->forceFill(['SessionCount' => 0, 'Charge' => 0, 'Pay' => 0, 'Paid' => 0, 'Stop' => 1, 'closed_reason' => 'cancelled'])->save();
            SessionDeductionService::recomputeCounters((int) $source->ID);
            SessionDeductionService::recomputeCounters((int) $target->ID);
            $record->forceFill(['reversed_at' => now(), 'reversed_by' => $actorId, 'reverse_reason' => trim($reason),
                'reverse_out_payment_id' => (int) $out->id, 'reverse_in_payment_id' => (int) $back->id])->save();
            if ((int) $out->Amount + (int) $back->Amount !== 0 || (int) $source->fresh()->SessionCount !== (int) $before['course']['SessionCount']) {
                throw new \LogicException('Subject credit reversal conservation failed.');
            }
            return ['replayed' => false, 'transfer' => $this->describe($record)];
        }, 3);
    }

    private function assertSchema(): void
    {
        if (!SubjectCreditTransfer::storageReady()) {
            abort(response()->json(['message' => '轉用功能尚未完成資料結構更新，請稍後再試。', 'code' => 'subject_credit_schema_unready'], 503));
        }
    }

    private function describe(SubjectCreditTransfer $r): array
    {
        return ['id' => (int) $r->id, 'source_course_id' => (int) $r->source_student_class_id,
            'destination_template_course_id' => (int) $r->template_student_class_id, 'target_course_id' => (int) $r->target_student_class_id,
            'moved_sessions' => (int) $r->sessions, 'amount' => (int) $r->amount, 'reversed' => $r->reversed_at !== null];
    }

    private function refuse(string $code, string $message): never
    {
        abort(response()->json(['message' => $message, 'code' => $code], 409));
    }
}
