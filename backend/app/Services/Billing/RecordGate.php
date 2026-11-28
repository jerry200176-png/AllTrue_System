<?php

namespace App\Services\Billing;

use App\Models\Invoice;
use App\Models\StudentClass;
use App\Services\BillingPayableResolver;
use App\Services\InvoiceAmountReconciliationService;
use App\Services\MonthlyBillingService;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Collection;

/**
 * Server decides, screen obeys: the amount-independent refusals of POST /payment-reports/director-record with no bill
 * named, moved out of PaymentReportController so the ledger's record_block is that same answer. directorRecord calls
 * the three steps in its own order (its amount checks sit between them); the ledger calls refusal(). Same codes and
 * sentences as before. $lock = the write path's locking reads (the caller holds the course row lock); the ledger reads plain.
 */
final class RecordGate
{
    public function __construct(private MonthlyBillingService $monthlyBilling)
    {
    }

    /**
     * director-record with no invoice_id, before any amount check: D (course money status), E (the bill it would collect
     * against is already covered), F (no open bill: partly billed month, already paid, lessons outside the dates).
     *
     * @return array{0: ?JsonResponse, 1: ?Invoice} the refusal, and the open bill director-record would collect against
     */
    public function refusal(StudentClass $course, bool $lock = false): array
    {
        if ($refusal = $this->courseRefusal($course, (int) ($course->getAttribute('Paid') ?? 0))) {
            return [$refusal, null];
        }

        return $this->billRefusal($course, $lock);
    }

    /**
     * Ledger only (plain reads): refusal() for many courses, with the resolver asked ONCE for all of them (a thrown
     * resolver error = every course 'unknown', as courseMoneyBlockReason fails closed). E/F run only for courses D lets through.
     *
     * @param  Collection<int, StudentClass>  $courses
     * @param  Collection<int, Invoice>|null  $invoices  the student's bills with items and payments, when already loaded
     * @return array<int, JsonResponse> course id => refusal
     */
    public function refusals(Collection $courses, ?Collection $invoices = null): array
    {
        if ($courses->isEmpty()) {
            return [];
        }
        try {
            $statuses = app(BillingPayableResolver::class)->courseStatusesByStudentClassIds($courses->pluck('ID')->all(), $courses);
        } catch (\Throwable) {
            $statuses = null;
        }
        $out = [];
        foreach ($courses as $course) {
            $id = (int) $course->getKey();
            $own = $invoices?->filter(fn (Invoice $i) => (int) $i->StudentClassID === $id && (string) $i->Status !== 'void')->values();
            $reason = $statuses === null ? 'unknown' : self::blockReason($statuses[$id]['status'] ?? null);
            $refusal = $reason !== null ? $this->duplicateCoursePaymentResponse($reason, $course, $own) : $this->billRefusal($course, false, $own)[0];
            if ($refusal) {
                $out[$id] = $refusal;
            }
        }

        return $out;
    }

    /**
     * E then F, after D let the course through.
     *
     * @param  Collection<int, Invoice>|null  $own  this course's non-void bills, when already loaded (F reads them instead of querying)
     * @return array{0: ?JsonResponse, 1: ?Invoice}
     */
    private function billRefusal(StudentClass $course, bool $lock, ?Collection $own = null): array
    {
        $invoice = $this->pickOpenInvoice((int) $course->getKey(), $course, $lock);
        if ($invoice) {
            return [$this->coveredRefusal($invoice, $course, $lock), $invoice];
        }

        return [$this->noBillRefusal($course, (int) ($course->getAttribute('Paid') ?? 0), $own), null];
    }

    /** D: the resolver says paid / free / no answer. */
    public function courseRefusal(StudentClass $course, int $coursePaid): ?JsonResponse
    {
        $reason = $this->courseMoneyBlockReason((int) $course->getKey(), $coursePaid);

        return $reason === null ? null : $this->duplicateCoursePaymentResponse($reason, $course);
    }

    /** E: the bill to collect against is settled by its Payment rows. */
    public function coveredRefusal(Invoice $invoice, ?StudentClass $course, bool $lock = false): ?JsonResponse
    {
        return $this->invoiceCoveredByPayments($invoice, $course, $lock) ? $this->invoiceAlreadyCoveredResponse($invoice) : null;
    }

    /** F: no open bill. */
    /** @param  Collection<int, Invoice>|null  $own  this course's non-void bills, when already loaded */
    public function noBillRefusal(StudentClass $course, int $coursePaid, ?Collection $own = null): ?JsonResponse
    {
        $hasPaidInvoice = $own !== null ? $own->contains(fn (Invoice $i) => (string) $i->Status === 'paid')
            : Invoice::where('StudentClassID', $course->ID)->where('Status', 'paid')->exists();
        // #5703: with no open bill, a month that already has one gets no second bill, whatever the stored paid marks say.
        if ($partly = $this->monthlyBilling->partlyBilledMonth($course, $own)) {
            return response()->json($partly, 422);
        }
        if (($coursePaid === 1 || $hasPaidInvoice) && !$this->unbilledMonthStillOwed($course, $hasPaidInvoice)) {
            return $this->duplicateCoursePaymentResponse('paid', $course, $own);
        }

        return $course->getAttribute('ScheduleMode') === 'date' ? $this->lessonsOutsideContractRefusal($course) : null;
    }

    /**
     * F7 S6 (B9): duplicate-payment guard on the BillingPayableResolver, not "any paid invoice" / raw Charge / SUM(PaidAmount).
     * Blocks `paid` and `free`; partial (the remainder may be recorded), unbilled, review_required and a plain unpaid
     * course may take a payment. Package members follow the resolver (a paid package counts while no non-void invoice exists).
     * Fail closed: no resolver answer (error, unknown course) blocks.
     * Founder Q2 (2026-10-11): the R28 carve-out is gone. Invoices + Payment rows are the truth; a Paid=1 flag beside an
     * unpaid/partial invoice does not block, so the owed amount can be recorded.
     *
     * Why it blocks (null = it allows): paid | free |
     * unknown (no resolver answer). The decision is the guard's; the reason only picks the sentence the director reads.
     */
    public function courseMoneyBlockReason(int $studentClassId, int $coursePaid): ?string
    {
        try {
            $status = app(BillingPayableResolver::class)->courseStatusesByStudentClassIds([$studentClassId])[$studentClassId]['status'] ?? null;
        } catch (\Throwable) {
            return 'unknown';
        }

        return self::blockReason($status);
    }

    /** The resolver status -> why director-record refuses (null = it allows); shared by the single and the batch read. */
    private static function blockReason(?string $status): ?string
    {
        return match (true) {
            $status === null => 'unknown',
            $status === 'paid' => 'paid',
            $status === 'free' => 'free',
            default => null,
        };
    }

    /**
     * in-app #432 (Founder 2026-10-28, option A): a paid monthly bill does not settle another calendar month whose
     * countable lessons no bill covers yet, so "already paid" must not refuse that month. A legacy Paid flag with no
     * paid bill keeps refusing (nothing shows which months it settled).
     */
    public function unbilledMonthStillOwed(StudentClass $sc, bool $hasPaidInvoice): bool
    {
        return $hasPaidInvoice && $this->monthlyBilling->unbilledPeriodFor($sc) !== null;
    }

    /**
     * R28 invariant (Founder Q2 coordination): a payment may only be recorded against a bill that still owes. A bill whose
     * Payment rows already cover its total is refused even when its stored Status is stale ("unpaid"), whatever the Paid flag says.
     */
    public function invoiceCoveredByPayments(Invoice $invoice, ?StudentClass $sc, bool $lock = false): bool
    {
        $projection = $this->invoiceProjection($invoice, $sc, $lock);

        return CollectionTarget::packagePaidHold((int) $invoice->StudentClassID, (string) $invoice->Status)
            || ((int) $projection['total_amount'] > 0 && (int) $projection['outstanding_amount'] <= 0);
    }

    /**
     * The invoice's money from its Payment rows (with $lock, a locking read so a concurrent confirm's committed row
     * counts; callers then already hold the course row lock), never the stored PaidAmount/Status.
     */
    public function invoiceProjection(Invoice $invoice, ?StudentClass $sc, bool $lock = false): array
    {
        $payments = $invoice->payments();
        $invoice->setRelation('payments', ($lock ? $payments->lockForUpdate() : $payments)->get());
        $invoice->loadMissing('items');

        return app(InvoiceAmountReconciliationService::class)->resolve($invoice, $sc);
    }

    /**
     * Invoice to collect against when the caller named none: the bill the collection list shows (Founder 2026-10-13),
     * i.e. the resolver's collection order (monthly: oldest period first). Every non-void bill is a candidate and its
     * Payment-row projection decides whether it still owes, whatever its stored Status (#3875: stored paid can still
     * owe). None owes → the first not-stored-paid one, so the caller refuses it as covered; never another bill.
     */
    public function pickOpenInvoice(int $studentClassId, ?StudentClass $sc, bool $lock = false): ?Invoice
    {
        $query = Invoice::where('StudentClassID', $studentClassId)
            ->where(fn ($q) => $q->whereNull('Status')->orWhere('Status', '!=', 'void'))->orderBy('id');
        $live = app(BillingPayableResolver::class)->collectionOrder(($lock ? $query->lockForUpdate() : $query)->get(), $sc);

        return $live->first(fn (Invoice $i) => !CollectionTarget::packagePaidHold((int) $i->StudentClassID, (string) $i->Status)
                && (int) $this->invoiceProjection($i, $sc, $lock)['outstanding_amount'] > 0)
            ?? $live->first(fn (Invoice $i) => (string) $i->Status !== 'paid');
    }

    /** #4765: lessons already taught outside the contract dates are invisible to billing; never price such a month from the stored Charge. */
    public function lessonsOutsideContractRefusal(\Illuminate\Database\Eloquent\Model $course): ?JsonResponse
    {
        $dates = $this->monthlyBilling->lessonsOnlyOutsideContract($course);
        if ($dates === []) {
            return null;
        }

        return response()->json([
            'message' => sprintf('這份合約已上過 %s 的課，但不在合約日期（%s–%s）內，系統算不出這期該收多少。請先把合約日期改成包含這些課，再登記收款。沒有登記任何款項。',
                implode('、', array_map(fn (string $d) => Carbon::parse($d)->format('n/j'), $dates)),
                Carbon::parse($course->getAttribute('StartDate'))->format('n/j'), Carbon::parse($course->getAttribute('EndDate'))->format('n/j')),
            'code' => 'lessons_outside_contract', 'lesson_dates' => $dates,
            'contract_start' => Carbon::parse($course->getAttribute('StartDate'))->toDateString(),
            'contract_end' => Carbon::parse($course->getAttribute('EndDate'))->toDateString(),
        ], 422);
    }

    /** The target bill is settled by its Payment rows: warn and block; the money is never moved to another bill. */
    public function invoiceAlreadyCoveredResponse(Invoice $invoice): JsonResponse
    {
        return response()->json([
            'message' => CollectionTarget::packagePaidHold((int) $invoice->StudentClassID, (string) $invoice->Status)
                ? '這張套餐帳單已標記繳清，收款紀錄待核對，不能在這裡登記；請在學生帳務核對套餐收款。沒有登記任何款項。'
                : '這張帳單依收款紀錄已繳清，不能再登記，也不會改記到其他帳單。請重新整理名單；若帳單狀態對不上，請在學生帳務核對收款紀錄，作廢重複或錯誤的帳單。沒有登記任何款項。',
            'code' => 'course_already_paid',
            'invoice_id' => (int) $invoice->id,
        ], 422);
    }

    /** @param  Collection<int, Invoice>|null  $own  the course's non-void bills with items, when already loaded */
    public function duplicateCoursePaymentResponse(string $reason, ?\Illuminate\Database\Eloquent\Model $course = null, ?Collection $own = null): JsonResponse
    {
        // #5703: "已收齊" is false when taught lessons sit in a month whose bill does not cover them; name those lessons instead.
        if ($reason === 'paid' && $course && ($partly = $this->monthlyBilling->partlyBilledMonth($course, $own))) {
            return response()->json($partly, 422);
        }
        // Same code and status for every reason; only the sentence differs so the director knows what to do next.
        return response()->json([
            'message' => match ($reason) {
                'free' => '這門課是免費課程（輔導、試上或 0 元），不需要收款。',
                'paid' => '這門課的款項已收齊，不需要再登記；若收錯了，請先作廢原收款再重新登記。',
                // Any other/unknown reason is the safe sentence, never "已收齊".
                default => '暫時無法確認這門課的繳費狀態，沒有登記任何款項。請稍後再試；一直失敗請回報。',
            },
            'code'    => 'course_already_paid',
        ], 422);
    }
}
