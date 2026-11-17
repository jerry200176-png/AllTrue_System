<?php

namespace App\Services\Billing;

use App\Models\{Campus, ClassSession, ExistingCourseCreditAllocationGroup as Group, Invoice, InvoiceItem, Payment, PaymentReport, StudentClass, SubjectCreditTransfer};
use App\Models\Scopes\OperationalTenantScope;
use App\Services\{ClassSessionMaterializationService, ScheduleGuardService};
use App\Services\Scheduling\ClassSessionDeleteGuard;
use Brick\Math\BigRational;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;

/** Internal transaction only. No route is enabled until the strict group inverse and its controls are complete. */
final class ExistingCourseCreditMovement
{
    public function __construct(private ExistingCourseCreditPreview $preview, private PaidContractCreditMovement $money, private SubjectCreditPreview $sourcePolicy) {}

    public function apply(StudentClass $course, array $input, int $actorId, ?array $campusIds): array
    {
        if ($actorId < 1) abort(403);
        if (!is_string($input['idempotency_key'] ?? null) || trim($input['idempotency_key']) === '' || strlen($input['idempotency_key']) > 100
            || !is_string($input['reason'] ?? null) || trim($input['reason']) === '' || mb_strlen(trim($input['reason'])) > 100
            || !is_string($input['state_hash'] ?? null) || preg_match('/^[0-9a-f]{64}$/', $input['state_hash']) !== 1) {
            $this->refuse('existing_credit_input_invalid', '請重新預覽並填寫本次配置的原因。', 422);
        }
        return DB::transaction(function () use ($course, $input, $actorId, $campusIds) {
            $scope = $this->preview->lockScope($course, $input, $campusIds);
            if (!Group::storageReady()) $this->refuse('existing_credit_schema_unready', '配置功能尚未完成資料結構更新。', 503);
            ['student' => $student, 'source' => $source, 'targets' => $targets] = $scope;
            $key = hash('sha256', $source->ID . '|' . $input['idempotency_key']);
            $releasedIds = $input['released_session_ids'] ?? []; sort($releasedIds, SORT_NUMERIC);
            $fingerprint = SubjectCreditState::digest(['targets' => $targets->modelKeys(), 'released' => $releasedIds, 'reason' => trim($input['reason'])]);
            $prior = Group::where('operation_key', $key)->lockForUpdate()->first();
            if ($prior) {
                if ((int) $prior->getAttribute('created_by') !== $actorId
                    || (int) $prior->getAttribute('student_id') !== (int) $student->id
                    || (int) $prior->getAttribute('campus_id') !== (int) $student->CampusID
                    || (int) $prior->getAttribute('source_student_class_id') !== (int) $source->ID) {
                    $this->refuse('existing_credit_replay_scope', '這個送出代碼已被其他操作使用，請重新整理後再確認。');
                }
                if (!hash_equals((string) $prior->request_fingerprint, $fingerprint)) {
                    $this->refuse('existing_credit_key_reused', '這個送出代碼已用於不同的配置內容，請重新整理。');
                }
                return ['status' => 200, 'body' => ['replayed' => true, 'allocation_group' => $this->describe($prior)]];
            }
            $locked = $this->preview->inspectScopeLocked($scope, $input);
            if (!hash_equals($locked['response']['state_hash'], $input['state_hash'])) {
                $this->refuse('existing_credit_preview_changed', '合約、帳務或排課已變動，請重新預覽後再確認。');
            }
            $plan = $locked['response']['plan']; $invoice = $locked['invoice'];
            $before = $this->snapshot($source, $invoice);
            $before['receipt_calendars'] = [];
            foreach (PaymentReport::where('StudentClassID', $source->ID)->where('status', 'confirmed')->orderBy('id')->lockForUpdate()->get() as $report) {
                $receiptCourse = clone $source;
                $quantity = SubjectCreditTransfer::originalReceiptQuantity($report, $source);
                if ($quantity !== null) $receiptCourse->SessionCount = $quantity;
                $before['receipt_calendars'][$report->id] = app(CountCourseReceiptCalendar::class)->read($receiptCourse, $report);
            }
            $targetsBefore = [];
            foreach ($targets as $target) $targetsBefore[(int) $target->ID] = $target->only(SubjectCreditState::COURSE_FIELDS);
            $released = [];
            foreach ($locked['released'] as $session) {
                $old = $this->lessonSnapshot($session);
                $session->Status = 'cancelled'; $session->save();
                $released[] = ['before' => $old, 'after' => $this->lessonSnapshot($session->refresh())];
            }
            $n = array_sum(array_column($plan['allocations'], 'sessions'));
            $amount = array_sum(array_column($plan['allocations'], 'amount'));
            $group = Group::create(['operation_key' => $key, 'request_fingerprint' => $fingerprint,
                'campus_id' => (int) $student->CampusID, 'student_id' => (int) $student->id,
                'source_student_class_id' => (int) $source->ID, 'source_invoice_id' => (int) $invoice->id,
                'created_by' => $actorId, 'sessions' => $n, 'amount' => $amount, 'reason' => trim($input['reason']),
                'source_before' => $before, 'source_after' => [], 'targets_before' => $targetsBefore,
                'targets_after' => [], 'released_sessions' => $released]);
            $paidAt = Carbon::parse($source->PayDate ?: $invoice->IssueDate)->toDateString();
            $targetsAfter = [];
            foreach ($plan['allocations'] as $allocation) {
                $target = $targets->firstWhere('ID', $allocation['id']);
                if (!$target) throw new \LogicException('Locked credit allocation target is missing.');
                $childBefore = SubjectCreditState::capture($source, $invoice);
                $movement = $this->money->move($invoice, $source, $target, $allocation['sessions'], $allocation['amount'],
                    $paidAt, '既有課程學費轉用', Carbon::today()->toDateString());
                $kept = (int) $invoice->TotalAmount;
                if ((int) $source->Pay === (int) $childBefore['invoice']['PaidAmount']) $source->Pay = $kept;
                $source->Charge = $kept;
                $source->SessionCount = (int) $source->SessionCount - $allocation['sessions'];
                $source->RemainingSessions = (int) $source->SessionCount - (int) $source->UsedSessions;
                if ((int) $source->RemainingSessions === 0) { $source->Stop = 1; $source->closed_reason = 'completed'; }
                $source->save();
                // Targets retain their original quantity, used count, dates and completion state; only funding changes.
                $target->forceFill(['Pay' => $allocation['amount'], 'Paid' => 1, 'PayDate' => $paidAt])->save();
                $targetsAfter[(int) $target->ID] = $this->snapshot($target, $movement['invoice']);
                $post = $targetsAfter[(int) $target->ID];
                if ((int) $post['course']['SessionCount'] !== $allocation['sessions']
                    || (int) $post['course']['UsedSessions'] !== $allocation['used']
                    || (int) $post['course']['RemainingSessions'] !== $allocation['sessions'] - $allocation['used']
                    || (int) $post['course']['Charge'] !== $allocation['amount']
                    || (int) $post['course']['Pay'] !== $allocation['amount'] || (int) $post['course']['Paid'] !== 1
                    || (int) $post['invoice']['TotalAmount'] !== $allocation['amount']
                    || (int) $post['invoice']['PaidAmount'] !== $allocation['amount']) {
                    throw new \LogicException('Existing target quantity or funding changed unexpectedly.');
                }
                SubjectCreditTransfer::create(['allocation_group_id' => (int) $group->id,
                    'operation_key' => hash('sha256', $key . '|existing-target|' . $target->ID),
                    'request_fingerprint' => $fingerprint, 'campus_id' => (int) $student->CampusID, 'student_id' => (int) $student->id,
                    'source_student_class_id' => (int) $source->ID, 'template_student_class_id' => (int) $target->ID,
                    'target_student_class_id' => (int) $target->ID, 'source_invoice_id' => (int) $invoice->id,
                    'target_invoice_id' => (int) $movement['invoice']->id, 'transfer_out_payment_id' => (int) $movement['out']->id,
                    'transfer_in_payment_id' => (int) $movement['in']->id, 'sessions' => $allocation['sessions'], 'amount' => $allocation['amount'],
                    'unit_price' => (string) BigRational::of((string) $source->Rate)->simplified(), 'lesson_minutes' => (int) $source->SessionDuration,
                    'reason' => trim($input['reason']), 'created_by' => $actorId, 'source_before' => $childBefore,
                    'source_after' => Group::childSnapshot(SubjectCreditState::capture($source, $invoice)),
                    'target_after' => SubjectCreditState::capture($target, $movement['invoice'])]);
                if ((int) $movement['out']->Amount + (int) $movement['in']->Amount !== 0) throw new \LogicException('Credit movement does not conserve money.');
            }
            $after = $this->snapshot($source, $invoice);
            if ((int) $after['course']['SessionCount'] !== $plan['source_sessions']
                || (int) $after['course']['UsedSessions'] !== $plan['source_used']
                || (int) $after['course']['RemainingSessions'] !== $plan['source_remaining']
                || (int) $after['course']['Charge'] !== $plan['source_amount']
                || (int) $after['invoice']['TotalAmount'] !== $plan['source_amount']
                || (int) $after['invoice']['PaidAmount'] !== $plan['source_amount']
                || (int) $after['invoice']['PaidAmount'] + $amount !== (int) $before['invoice']['PaidAmount']) {
                throw new \LogicException('Existing-course credit conservation failed.');
            }
            $group->forceFill(['source_after' => $after, 'targets_after' => $targetsAfter])->save();
            return ['status' => 201, 'body' => ['replayed' => false, 'allocation_group' => $this->describe($group)]];
        }, 3);
    }

    public function reverse(StudentClass $course, int $id, string $reason, int $actorId, ?array $campusIds): array
    {
        if ($actorId < 1) abort(403);
        $reason = trim($reason);
        if ($reason === '' || mb_strlen($reason) > 100) $this->refuse('existing_credit_reason_invalid', '請填寫整批還原的原因。', 422);
        return DB::transaction(function () use ($course, $id, $reason, $actorId, $campusIds) {
            $student = $this->sourcePolicy->lockAuthorizedStudent($course, $campusIds);
            if (!Group::storageReady()) $this->refuse('existing_credit_schema_unready', '配置功能尚未完成資料結構更新。', 503);
            $group = Group::whereKey($id)->where('source_student_class_id', $course->ID)->lockForUpdate()->firstOrFail();
            if ((int) $group->getAttribute('student_id') !== (int) $student->id
                || (int) $group->getAttribute('campus_id') !== (int) $student->CampusID) abort(403);
            $targetIds = array_map('intval', array_keys($group->getAttribute('targets_before'))); sort($targetIds);
            $ids = array_values(array_unique([(int) $course->ID, ...$targetIds])); sort($ids);
            if ($targetIds === [] || count($ids) !== count($targetIds) + 1 || min($ids) < 1) $this->changed();
            // Native guard order: covering metadata keys -> ALL occupancy ranges -> clustered course X locks.
            $metadata = StudentClass::withoutGlobalScope(OperationalTenantScope::class)
                ->from(DB::raw('StudentClass FORCE INDEX (idx_sc_current_metadata)'))
                ->where('StudentID', $student->id)->whereIn('ID', $ids)->orderBy('ID')
                ->select(['ID', 'StudentID', 'TeacherID', 'ClassType', 'room_id'])->sharedLock()->get()->keyBy('ID');
            if ($metadata->count() !== count($ids) || !Campus::whereKey($student->CampusID)->sharedLock()->first(['id'])) abort(403);
            if ($group->getAttribute('reversed_at') !== null) {
                if ((int) $group->getAttribute('reversed_by') !== $actorId || $group->getAttribute('reverse_reason') !== $reason)
                    $this->refuse('existing_credit_reverse_reused', '這筆配置已還原，請重新整理後查看原紀錄。');
                return ['replayed' => true, 'allocation_group' => $this->describe($group)];
            }
            $lessons = collect($group->getAttribute('released_sessions'))->sortBy(fn ($r) =>
                [$r['before']['SessionDate'], $r['before']['StartTime'], (int) $r['before']['id']])->values();
            $meta = $metadata->get((int) $course->ID);
            if (!$meta) $this->changed();
            if ($lessons->isNotEmpty() && (int) $meta->TeacherID < 1) {
                $this->refuse('existing_credit_calendar_unverified', '原課程缺少老師資料，無法安全核對原時段；這批配置尚未還原。');
            }
            foreach ($lessons as $row) {
                $old = $row['before']; $date = substr((string) $old['SessionDate'], 0, 10);
                if (Carbon::parse($date . ' ' . $old['EndTime'])->lessThanOrEqualTo(Carbon::parse($date . ' ' . $old['StartTime'])))
                    $this->refuse('existing_credit_calendar_unverified', '原課堂跨日，目前無法安全核對還原時段；這批配置尚未還原。');
                if (!Carbon::parse($date . ' ' . $old['StartTime'])->isFuture())
                    $this->refuse('existing_credit_reverse_expired', '原先釋出的堂次已有開始上課的時間，不能在這裡還原。');
                $conflicts = app(ScheduleGuardService::class)->validateScheduleOccurrence([
                    'teacher_id' => (int) $meta->TeacherID, 'class_type' => $meta->ClassType, 'room_id' => $meta->room_id,
                    'branch_id' => (int) $student->CampusID, 'schedule_date' => $date,
                    'start_time' => $old['StartTime'], 'end_time' => $old['EndTime'],
                    'exclude_course_id' => (int) $course->ID, 'exclude_student_id' => (int) $student->id,
                ], true);
                if ($conflicts !== []) $this->refuse('existing_credit_reverse_capacity', '原時段已有其他排課或教室已滿，這批配置尚未還原。');
            }
            $scope = $this->preview->lockScope($course, ['target_course_ids' => $targetIds], $campusIds);
            ['source' => $source, 'targets' => $targets] = $scope;
            $children = SubjectCreditTransfer::where('allocation_group_id', $group->id)->orderByDesc('id')->lockForUpdate()->get();
            if ($children->count() !== count($targetIds) || $children->contains(fn ($c) => $c->reversed_at !== null)
                || $children->pluck('target_student_class_id')->map(fn ($v) => (int) $v)->sort()->values()->all() !== $targetIds) $this->changed();
            $amount = (int) $children->sum('amount'); $sessions = (int) $children->sum('sessions');
            $before = $group->getAttribute('source_before'); $after = $group->getAttribute('source_after');
            if ($amount < 1 || $sessions < 1 || $amount !== (int) $group->getAttribute('amount') || $sessions !== (int) $group->getAttribute('sessions')
                || (int) $before['course']['SessionCount'] !== (int) $after['course']['SessionCount'] + $sessions
                || (int) $before['course']['UsedSessions'] !== (int) $after['course']['UsedSessions']
                || (int) $before['course']['RemainingSessions'] !== (int) $after['course']['RemainingSessions'] + $sessions
                || (int) $before['course']['Charge'] !== (int) $after['course']['Charge'] + $amount
                || (int) $before['invoice']['TotalAmount'] !== (int) $after['invoice']['TotalAmount'] + $amount
                || (int) $before['invoice']['PaidAmount'] !== (int) $after['invoice']['PaidAmount'] + $amount) $this->changed();
            $invoices = Invoice::where(fn ($q) => $q->whereIn('StudentClassID', $ids)
                ->orWhereHas('items', fn ($items) => $items->whereIn('StudentClassID', $ids)))
                ->orderBy('id')->lockForUpdate()->get()->keyBy('id');
            if ($invoices->count() !== count($targetIds) + 1) $this->changed();
            Payment::whereIn('InvoiceID', $invoices->keys())->orderBy('id')->lockForUpdate()->get();
            $invoice = $invoices->get((int) $group->getAttribute('source_invoice_id'));
            if (!$invoice || !hash_equals(SubjectCreditState::digest($group->getAttribute('source_after')), SubjectCreditState::digest($this->snapshot($source, $invoice)))) $this->changed();
            // Reuse the existing source cash/transfer provenance authority, including unique payment links and units.
            app(SubjectCreditFunding::class)->knownPayments($source, $invoice,
                Payment::where('InvoiceID', $invoice->id)->orderBy('id')->lockForUpdate()->get(), (int) $student->CampusID);
            foreach ($children as $child) {
                $target = $targets->firstWhere('ID', $child->target_student_class_id);
                $bill = $invoices->get((int) $child->target_invoice_id);
                if (!$target || !$bill || (int) $child->student_id !== (int) $student->id || (int) $child->campus_id !== (int) $student->CampusID
                    || (int) $child->source_student_class_id !== (int) $source->ID
                    || (int) $child->source_invoice_id !== (int) $invoice->id || (int) $child->template_student_class_id !== (int) $target->ID
                    || (int) $child->getAttribute('amount') !== (int) $group->getAttribute('targets_after')[$target->ID]['invoice']['PaidAmount']
                    || (int) $child->getAttribute('sessions') !== (int) $group->getAttribute('targets_after')[$target->ID]['course']['SessionCount']
                    || !hash_equals(SubjectCreditState::digest($group->getAttribute('targets_after')[$target->ID]), SubjectCreditState::digest($this->snapshot($target, $bill)))) $this->changed();
            }
            $restoring = ClassSession::where('StudentClassID', $source->ID)->whereIn('id', $lessons->pluck('before.id'))->orderBy('id')->lockForUpdate()->get()->keyBy('id');
            if ($restoring->count() !== $lessons->count()
                || ClassSessionDeleteGuard::referencedSessionIds($restoring->keys()->all(), true) !== []
                || ClassSessionDeleteGuard::datedScheduleSessionIds($restoring, true) !== []) $this->changed();
            $candidate = clone $source;
            $candidate->forceFill(['Stop' => $group->getAttribute('source_before')['course']['Stop'],
                'RemainingSessions' => $group->getAttribute('source_before')['course']['RemainingSessions']]);
            foreach ($lessons as $row) {
                $current = $restoring->get((int) $row['before']['id']);
                if (!$current || !hash_equals(SubjectCreditState::digest($row['after']), SubjectCreditState::digest($this->lessonSnapshot($current)))) $this->changed();
                if (app(ClassSessionMaterializationService::class)->findStudentSlotConflict($candidate,
                    substr((string) $row['before']['SessionDate'], 0, 10), $row['before']['StartTime'], $row['before']['EndTime'], null, [], true))
                    $this->refuse('existing_credit_reverse_student_overlap', '學生原時段已有其他課程，這批配置尚未還原。');
            }
            foreach ($children as $child) {
                $target = $targets->firstWhere('ID', $child->target_student_class_id);
                $bill = $invoices->get((int) $child->target_invoice_id); $amount = (int) $child->getAttribute('amount');
                if (!$target || !$bill) throw new \LogicException('Locked allocation inverse target is missing.');
                $out = Payment::create(['InvoiceID' => $bill->id, 'Amount' => -$amount, 'Method' => 'transfer_reversal_out',
                    'PaidAt' => Carbon::today()->toDateString(), 'Note' => '既有課程整批轉用還原#' . $group->id]);
                $back = Payment::create(['InvoiceID' => $invoice->id, 'Amount' => $amount, 'Method' => 'transfer_reversal_in',
                    'PaidAt' => Carbon::today()->toDateString(), 'Note' => '既有課程整批轉用還原#' . $group->id]);
                InvoiceItem::create(['InvoiceID' => $bill->id, 'StudentClassID' => $target->ID, 'Amount' => -$amount, 'Description' => '既有課程整批轉用還原']);
                $bill->forceFill(['TotalAmount' => 0, 'PaidAmount' => 0, 'Status' => 'void'])->save();
                $target->forceFill(array_intersect_key($group->getAttribute('targets_before')[$target->ID], array_flip(['Pay', 'Paid', 'PayDate'])))->save();
                $child->forceFill(['reversed_at' => now(), 'reversed_by' => $actorId, 'reverse_reason' => $reason,
                    'reverse_out_payment_id' => $out->id, 'reverse_in_payment_id' => $back->id])->save();
            }
            $before = $group->getAttribute('source_before');
            $source->forceFill(array_intersect_key($before['course'], array_flip(['SessionCount', 'UsedSessions', 'RemainingSessions', 'Charge', 'Pay', 'Paid', 'Stop', 'closed_reason', 'EndDate'])))->save();
            $invoice->forceFill(array_intersect_key($before['invoice'], array_flip(['TotalAmount', 'PaidAmount', 'Status'])))->save();
            foreach ($before['items'] as $item) InvoiceItem::whereKey($item['id'])->where('InvoiceID', $invoice->id)->update(['Amount' => $item['Amount']]);
            foreach ($lessons as $row) {
                $current = $restoring->get((int) $row['before']['id']);
                if (!$current) throw new \LogicException('Locked allocation lesson is missing.');
                $current->Status = $row['before']['Status']; $current->save();
            }
            $group->forceFill(['reversed_at' => now(), 'reversed_by' => $actorId, 'reverse_reason' => $reason])->save();
            return ['replayed' => false, 'allocation_group' => $this->describe($group)];
        }, 3);
    }

    private function changed(): never
    {
        $this->refuse('existing_credit_reverse_changed', '配置後的合約、帳務或排課已有異動，這批配置尚未還原。');
    }

    /** Bounded post-state plus digests: do not duplicate course notes or student names in the audit. */
    private function snapshot(StudentClass $course, Invoice $invoice): array
    {
        $fresh = StudentClass::whereKey($course->ID)->lockForUpdate()->firstOrFail();
        return SubjectCreditState::capture($fresh, $invoice) + [
            'course_context_digest' => SubjectCreditState::digest($fresh->getAttributes()),
            'bookings_digest' => SubjectCreditState::digest(SubjectCreditState::lockBookings((int) $fresh->ID)->map(fn ($r) => (array) $r)->all()),
        ];
    }

    private function lessonSnapshot(ClassSession $session): array
    {
        return $session->only(['id', 'StudentClassID', 'SubjectID', 'SessionDate', 'StartTime', 'EndTime', 'Status', 'IsContractException', 'session_charge', 'updated_at']);
    }

    private function describe(Group $group): array
    {
        return ['id' => (int) $group->id, 'source_course_id' => (int) $group->getAttribute('source_student_class_id'),
            'sessions' => (int) $group->getAttribute('sessions'), 'amount' => (int) $group->getAttribute('amount'),
            'reversed' => $group->getAttribute('reversed_at') !== null];
    }

    private function refuse(string $code, string $message, int $status = 409): never
    {
        abort(response()->json(['message' => $message, 'code' => $code], $status));
    }
}
