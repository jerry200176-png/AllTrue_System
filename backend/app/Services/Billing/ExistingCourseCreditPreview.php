<?php

namespace App\Services\Billing;

use App\Models\{ClassSession, ExistingCourseCreditAllocationGroup, Invoice, InvoiceItem, PaymentReport, StudentClass};
use App\Services\SessionDeductionService;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

/** Existing unpaid contracts only. Preview rolls back; a writer must repeat all checks under these same locks. */
final class ExistingCourseCreditPreview
{
    public function __construct(private SubjectCreditPreview $sourcePreview, private ExistingCourseCreditReservations $reservations) {}

    public function run(StudentClass $course, array $input, ?array $campusIds): array
    {
        DB::beginTransaction();
        try { return $this->inspectLocked($course, $input, $campusIds)['response']; }
        finally { DB::rollBack(); }
    }

    /** Separate read-only entry; no request flag can ask the writer to invent reservation releases. */
    public function propose(StudentClass $course, array $input, ?array $campusIds): array
    {
        DB::beginTransaction();
        try { return $this->inspect($this->lockScope($course, $input, $campusIds), $input, true)['response']; }
        finally { DB::rollBack(); }
    }

    public function inspectLocked(StudentClass $course, array $input, ?array $campusIds): array
    {
        return $this->inspectScopeLocked($this->lockScope($course, $input, $campusIds), $input);
    }

    /** @return array{student:\App\Models\Student,source:StudentClass,target:StudentClass,targets:\Illuminate\Database\Eloquent\Collection<int,StudentClass>} */
    public function lockScope(StudentClass $course, array $input, ?array $campusIds): array
    {
        $ids = $input['target_course_ids'] ?? [];
        $releaseIds = $input['released_session_ids'] ?? [];
        if (!is_array($ids) || count($ids) < 1 || count($ids) > 20 || !array_is_list($ids)
            || count(array_unique($ids, SORT_REGULAR)) !== count($ids)
            || count(array_filter($ids, fn ($id) => is_int($id) && $id > 0 && $id !== (int) $course->ID)) !== count($ids)
            || !is_array($releaseIds) || !array_is_list($releaseIds)) {
            $this->refuse('existing_credit_targets_invalid', '請選擇同一學生的既有課程，且不可重複選取。');
        }
        sort($ids, SORT_NUMERIC);
        $scope = $this->sourcePreview->lockScope($course, ['target_course_id' => $ids[0]], $campusIds, $ids);
        $scope['targets'] = StudentClass::where('StudentID', $scope['student']->id)->whereIn('ID', $ids)->orderBy('ID')->lockForUpdate()->get();
        return $scope;
    }

    /** Server-owned scope from lockScope(); replay may be checked before target funding eligibility. */
    public function inspectScopeLocked(array $scope, array $input): array
    {
        return $this->inspect($scope, $input, false);
    }

    private function inspect(array $scope, array $input, bool $proposing): array
    {
        if (DB::transactionLevel() < 1) throw new \LogicException('Credit allocation evidence requires a locked transaction.');
        ['student' => $student, 'source' => $source] = $scope;
        $targets = $scope['targets'];
        $ids = $targets->modelKeys();
        $releaseIds = $input['released_session_ids'] ?? [];
        if ($source->isUsageSettlementLocked()) {
            $this->refuse('existing_credit_targets_invalid', '來源課程已結清鎖定，請先核對原合約。');
        }
        $targetStates = []; $terms = [];
        // All target rows, their financial ranges and teaching evidence precede the first derived money/usage read.
        foreach ($targets as $target) {
            $this->sourcePreview->assertUnits($source, $target);
            if ((int) $target->SubjectID === (int) $source->SubjectID || $target->isUsageSettlementLocked()
                || !in_array((string) $target->closed_reason, ['', 'completed'], true)
                || ((int) $target->Stop !== 0 && $target->closed_reason !== 'completed')) {
                $this->refuse('existing_credit_targets_invalid', '請選擇仍需支付學費的其他科既有課程。');
            }
            $bills = Invoice::where(fn ($q) => $q->where('StudentClassID', $target->ID)
                ->orWhereHas('items', fn ($items) => $items->where('StudentClassID', $target->ID)))
                ->orderBy('id')->lockForUpdate()->get();
            $items = InvoiceItem::where('StudentClassID', $target->ID)->orderBy('id')->lockForUpdate()->get();
            $reports = PaymentReport::where('StudentClassID', $target->ID)->orderBy('id')->lockForUpdate()->get();
            if ((int) $target->Paid !== 0 || (int) $target->Pay !== 0 || $bills->isNotEmpty() || $items->isNotEmpty() || $reports->isNotEmpty()) {
                $this->refuse('existing_credit_target_funded', '目的課程已有帳單或收款紀錄，請先核對，不能重複配置已付學費。');
            }
            $sessions = ClassSession::where('StudentClassID', $target->ID)->orderBy('id')->lockForUpdate()->get();
            $bookings = SubjectCreditState::lockBookings((int) $target->ID);
            DB::table('session_deduction_ledger')->where('student_class_id', $target->ID)->orderBy('id')->lockForUpdate()->get();
            $targetStates[(int) $target->ID] = ['course' => $target->getAttributes(), 'sessions' => $sessions->toArray(), 'bookings' => $bookings->toArray()];
        }
        $locked = $this->sourcePreview->inspectSourceForAllocationLocked($scope);
        $sourcePlan = $locked['response']['plan'];
        if ($sourcePlan['counters_require_review']) {
            $this->refuse('existing_credit_usage_unclear', '來源堂數紀錄尚待核對，不能用轉用操作順便修正。');
        }
        $usageById = SessionDeductionService::batchExpectedUsedSessionDiagnostics($ids);
        foreach ($targets as $target) {
            $usage = $usageById[(int) $target->ID] ?? null;
            if (!$usage || $usage['has_partial'] || $usage['cancelled_usage_artifacts'] > 0
                || $usage['uncapped_used'] < 0 || $usage['uncapped_used'] > (int) $target->SessionCount
                || $usage['expected_used'] !== (int) $target->UsedSessions
                || (int) $target->RemainingSessions !== (int) $target->SessionCount - $usage['expected_used']) {
                $this->refuse('existing_credit_usage_unclear', '目的課程的已上堂數尚待核對，不能用配置學費順便更正。');
            }
            $terms[] = ['id' => (int) $target->ID, 'sessions' => (int) $target->SessionCount, 'used' => $usage['expected_used'],
                'unit_price' => (string) $target->Rate, 'lesson_minutes' => (int) $target->SessionDuration, 'charge' => (int) $target->Charge];
            $targetStates[(int) $target->ID]['usage'] = $usage;
        }
        $eligible = $this->reservations->eligibleLocked($source, $locked['sessions']);
        try {
            $args = [$sourcePlan['purchased_sessions'], $sourcePlan['used_sessions'],
                $sourcePlan['reserved_sessions'], (string) $source->Rate, (int) $source->SessionDuration,
                $terms];
            $plan = $proposing
                ? ExistingCourseCreditAllocation::propose(...[...$args, $eligible->filter(fn ($s) => strcmp((string) $s->EndTime, (string) $s->StartTime) > 0)->pluck('id')->all()])
                : ExistingCourseCreditAllocation::plan(...[...$args, $releaseIds, $eligible->pluck('id')->all()]);
        } catch (InvalidArgumentException) {
            $this->refuse('existing_credit_release_invalid', '可用堂數不足，或選取的釋出堂次已變動。請核對目的課程，並只選擇需要釋出的未來未上課堂次。');
        }
        $released = $eligible->whereIn('id', $plan['released_session_ids'])->values();
        if ($released->isNotEmpty() && (int) $source->TeacherID < 1) {
            $this->refuse('existing_credit_calendar_unverified', '來源課程尚未指定老師，請先補齊老師再釋出已排課堂。');
        }
        // Calendar capacity currently uses day-local intervals. Do not cancel a cross-day row whose restore
        // cannot be checked by that same native authority; the financial candidate calculation alone grants no release.
        foreach ($released as $session) {
            $date = substr((string) $session->SessionDate, 0, 10);
            if (\Carbon\Carbon::parse($date . ' ' . $session->EndTime)->lessThanOrEqualTo(\Carbon\Carbon::parse($date . ' ' . $session->StartTime))) {
                $this->refuse('existing_credit_calendar_unverified', '需釋出的課堂跨日，目前無法安全核對還原時段；本次不更動額度或排課，請先由主任核對。');
            }
        }
        $hash = SubjectCreditState::digest(['source' => $locked['response']['state_hash'], 'targets' => $targetStates, 'plan' => $plan]);
        return ['scope' => $scope, 'targets' => $targets, 'invoice' => $locked['invoice'], 'released' => $released,
            'response' => ['preview' => true, 'execution_available' => false, 'storage_ready' => ExistingCourseCreditAllocationGroup::storageReady(),
                'state_hash' => $hash, 'plan' => $plan, 'source_course_id' => (int) $source->ID,
                'released_lessons' => $released->map(fn ($s) => ['id' => (int) $s->id, 'date' => substr((string) $s->SessionDate, 0, 10),
                    'start_time' => $s->StartTime, 'end_time' => $s->EndTime])->all()]];
    }

    private function refuse(string $code, string $message): never
    {
        abort(response()->json(['message' => $message, 'code' => $code], 422));
    }
}
