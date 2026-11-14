<?php

namespace App\Services\Billing;

use App\Models\{ClassSession, Invoice, InvoiceItem, Payment, PaymentReport, Student, StudentClass};
use App\Services\SessionDeductionService;
use App\Services\Scheduling\ContractSessionSchedule;
use App\Support\SessionStatus;
use Brick\Math\BigRational;
use Brick\Math\Exception\MathException;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * In-app #535: eligibility only. No ledger/counter repair, materialization or transfer.
 * A future writer must recompute under its own locks; this hash is not authorization.
 */
final class SubjectCreditPreview
{
    public function __construct(private ContractMoneyVerdict $verdict)
    {
    }

    /** @param array{target_course_id:int, sessions:int} $input @param list<int>|null $campusIds */
    public function run(StudentClass $routeCourse, array $input, ?array $campusIds): array
    {
        DB::beginTransaction();
        try {
            return $this->inspectLocked($this->lockScope($routeCourse, $input, $campusIds), $input)['response'];
        } finally {
            DB::rollBack();
        }
    }

    /** @return array{student:Student,source:StudentClass,target:StudentClass} */
    public function lockScope(StudentClass $routeCourse, array $input, ?array $campusIds): array
    {
        if (DB::transactionLevel() < 1) {
            throw new \LogicException('Subject credit requires a transaction.');
        }
        $student = Student::query()->whereKey($routeCourse->StudentID)->lockForUpdate()->first();
        if (!$student || ($campusIds !== null && !in_array((int) $student->CampusID, $campusIds, true))) {
            abort(403);
        }
        $courses = StudentClass::query()->where('StudentID', $student->id)
            ->whereIn('ID', [(int) $routeCourse->getKey(), (int) $input['target_course_id']])
            ->orderBy('ID')->lockForUpdate()->get()->keyBy('ID');
        $source = $courses->get((int) $routeCourse->getKey());
        $target = $courses->get((int) $input['target_course_id']);
        if (!$source || !$target || $courses->count() !== 2 || (int) $source->SubjectID === (int) $target->SubjectID) {
            $this->refuse('subject_credit_bad_target', '請選擇同一學生、同分校的另一科合約。');
        }
        return ['student' => $student, 'source' => $source, 'target' => $target];
    }

    /** @param array{student:Student,source:StudentClass,target:StudentClass} $scope */
    public function inspectLocked(array $scope, array $input): array
    {
        ['student' => $student, 'source' => $source, 'target' => $target] = $scope;
        if ((int) $student->enable !== 1) {
            $this->refuse('subject_credit_student_inactive', '請先確認學生仍為在籍狀態。');
        }
        $this->assertUnits($source, $target);
        if ((int) $source->Stop !== 0 || (string) ($source->closed_reason ?? '') !== '') {
            $this->refuse('subject_credit_source_closed', '來源合約已停課或結案，請先核對原合約。');
        }

        // Lock financial and lesson evidence before the first repeatable-read projection.
        // Include indirect item links: a shared invoice must never masquerade as a plain paid contract.
        $invoices = Invoice::query()->where(function ($q) use ($source) {
            $q->where('StudentClassID', $source->ID)->orWhereHas('items', fn ($items) => $items->where('StudentClassID', $source->ID));
        })->orderBy('id')->lockForUpdate()->get();
        $invoice = $invoices->first();
        if ($invoices->count() !== 1 || !$invoice || (int) $invoice->StudentID !== (int) $student->id
            || (int) $invoice->StudentClassID !== (int) $source->ID || $invoice->Status !== 'paid') {
            $this->refusePaid();
        }
        $items = InvoiceItem::query()->where('InvoiceID', $invoice->getKey())->orderBy('id')->lockForUpdate()->get();
        $payments = Payment::query()->where('InvoiceID', $invoice->getKey())->orderBy('id')->lockForUpdate()->get();
        $reports = PaymentReport::query()->where(function ($q) use ($source, $invoice) {
            $q->where('StudentClassID', $source->ID)->orWhere('InvoiceID', $invoice->getKey());
        })->orderBy('id')->lockForUpdate()->get();
        $sessions = ClassSession::query()->where('StudentClassID', $source->ID)->orderBy('id')->lockForUpdate()->get();

        $paid = (int) $payments->sum('Amount');
        $money = $this->verdict->forCourse($source);
        if ($payments->isEmpty() || $payments->contains(fn ($p) => (int) $p->Amount <= 0
                || in_array(strtolower((string) $p->Method), ['void', 'refund', 'transfer_in', 'transfer_out'], true))
            || $reports->contains(fn ($r) => $r->status === 'pending')
            || $items->count() !== 1 || (int) $items->first()->StudentClassID !== (int) $source->ID
            || (int) $items->first()->Amount !== $paid
            || !$money || $money->source !== 'invoice' || $money->status !== 'paid' || $money->outstanding !== 0
            || $money->overpaid !== 0 || $money->applied !== $paid || $paid < 1
            || (int) $source->Charge !== $paid || (int) $invoice->TotalAmount !== $paid || (int) $invoice->PaidAmount !== $paid) {
            $this->refusePaid();
        }
        $rate = BigRational::of((string) $source->Rate);
        if (!$rate->multipliedBy((int) $source->SessionCount)->isEqualTo($paid)
            || !$rate->multipliedBy((int) $target->SessionCount)->isEqualTo((int) $target->Charge)) {
            $this->refuse('subject_credit_price_unproven', '合約單價、堂數與學費未能完全對上，請先核對原約定價格。');
        }

        $usage = SessionDeductionService::batchExpectedUsedSessionDiagnostics([(int) $source->ID])[(int) $source->ID] ?? null;
        $purchased = (int) $source->SessionCount;
        if (!$usage || $usage['has_partial'] || $usage['cancelled_usage_artifacts'] > 0
            || $usage['uncapped_used'] > $purchased || $usage['uncapped_used'] < 0) {
            $this->refuse('subject_credit_usage_unclear', '扣堂有零碎分鐘、超扣或待核對紀錄，請先核對後再轉用。');
        }
        $used = (int) $usage['expected_used'];
        $remaining = $purchased - $used;
        $reserved = 0;
        foreach ($sessions as $session) {
            $status = strtolower((string) $session->Status);
            if (in_array($status, ['completed', 'attended', 'late'], true)) {
                // Canonical usage excludes future-linked evidence. It must not disappear from both
                // usage and reservations when an early status update marked the future lesson taught.
                if (!$session->SessionDate || Carbon::parse($session->SessionDate)->toDateString() > Carbon::today()->toDateString()) {
                    $this->refuse('subject_credit_future_usage', '來源合約有未來日期卻已標記上課的堂次，請先核對，避免重複轉用。');
                }
                continue;
            }
            if (in_array($status, SessionStatus::futureReservationExclusionStatuses(), true)) {
                continue;
            }
            if (!$session->SessionDate || !$session->EndTime) {
                $this->refuse('subject_credit_usage_unclear', '來源排課的日期或結束時間不完整，請先核對。');
            }
            $date = Carbon::parse($session->SessionDate)->toDateString();
            $end = (string) $session->EndTime;
            if (ContractSessionSchedule::sessionEndedByEndTime($date, $end)) {
                $this->refuse('subject_credit_unmarked', '來源合約有已結束但尚未點名的堂次，請先完成點名或取消。');
            }
            $reserved++;
        }
        // Conservatively reserve scheduled rows even if attendance and status have not yet converged.
        $available = max(0, $remaining - $reserved);
        $moving = (int) $input['sessions'];
        if ($moving < 1 || $moving > $available) {
            $this->refuse('subject_credit_unavailable', '可轉用堂數不足；已上課與已排課堂數都會保留。');
        }
        try {
            $amount = $rate->multipliedBy($moving)->toInt();
        } catch (MathException) {
            $this->refuse('subject_credit_fractional_amount', '這個堂數換算會產生零碎金額，請調整堂數或先核對帳務。');
        }
        $destinationMoney = $this->verdict->forCourse($target);
        $state = [
            'source' => $source->getAttributes(), 'target' => $target->getAttributes(),
            'request' => ['target_course_id' => (int) $input['target_course_id'], 'sessions' => (int) $input['sessions']],
            'invoice' => $invoice->getAttributes(), 'items' => $items->toArray(), 'payments' => $payments->toArray(),
            'reports' => $reports->toArray(), 'sessions' => $sessions->toArray(), 'usage' => $usage,
        ];

        return ['invoice' => $invoice, 'response' => [
            'preview' => true, 'execution_available' => false,
            'state_hash' => hash('sha256', json_encode($state, JSON_THROW_ON_ERROR)),
            'plan' => [
                'source_course_id' => (int) $source->ID, 'destination_template_course_id' => (int) $target->ID,
                'purchased_sessions' => $purchased, 'used_sessions' => $used, 'remaining_sessions' => $remaining,
                'reserved_sessions' => $reserved, 'available_sessions' => $available, 'moved_sessions' => $moving,
                'unit_price' => (string) $rate->simplified(), 'lesson_minutes' => (int) $source->SessionDuration,
                'amount' => $amount, 'source_keeps_amount' => $paid - $amount,
                'destination_existing_outstanding' => $destinationMoney?->outstanding,
                'stored_remaining_sessions' => (int) $source->RemainingSessions,
                'stored_used_sessions' => (int) $source->UsedSessions,
                'counters_require_review' => (int) $source->RemainingSessions !== $remaining || (int) $source->UsedSessions !== $used,
            ],
        ]];
    }

    private function assertUnits(StudentClass $source, StudentClass $target): void
    {
        foreach ([$source, $target] as $course) {
            if ($course->ScheduleMode !== 'count' || $course->getRawOriginal('rate_unit') !== 'session'
                || $course->getRawOriginal('deduction_basis') !== 'fixed_session'
                || $course->isPartOfPackage() || !in_array($course->ClassType, ['one_on_one', 'one_on_two', 'one_on_three'], true)
                || (int) $course->SessionCount < 1 || (int) $course->SessionDuration < 1 || (int) $course->Disconunt !== 0
                || !is_numeric($course->Rate) || BigRational::of((string) $course->Rate)->isLessThanOrEqualTo(0)) {
                $this->refuse('subject_credit_units_unsupported', '只支援有明確單價、堂長且無折扣的單科堂數制固定扣堂合約。');
            }
            foreach (range(1, 6) as $slot) {
                $minutes = (int) $course->getRawOriginal('duration' . $slot);
                if ($minutes > 0 && $minutes !== (int) $course->SessionDuration) {
                    $this->refuse('subject_credit_units_unsupported', '合約含不同堂長的時段，請先核對每堂的原約定單位。');
                }
            }
        }
        if ((int) $source->SessionDuration !== (int) $target->SessionDuration
            || !BigRational::of((string) $source->Rate)->isEqualTo((string) $target->Rate)) {
            $this->refuse('subject_credit_units_differ', '跨科轉用限相同原約定單價與堂長，不能換算不同價格或時長。');
        }
    }

    private function refusePaid(): never
    {
        $this->refuse('subject_credit_paid_unclear', '來源必須是可明確核對、已付清的單一帳單；特殊收款請先走帳務核對。');
    }

    private function refuse(string $code, string $message): never
    {
        abort(response()->json(['message' => $message, 'code' => $code], 422));
    }
}
