<?php

namespace App\Services\Billing;

use App\Models\CoursePackage;
use App\Models\Invoice;
use App\Models\StudentClass;
use App\Services\BillingPayableResolver;
use App\Services\MonthlyBillingService;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Collection;

/**
 * One home for the rules of money landing on a course, so every path that records a payment applies the same ones
 * (confirm(), and the receipt move of #5113): which courses may take money at all ({@see gate()}), and what a payment
 * settles afterwards ({@see settle()}: the course Paid flag and a shared package's paid mark). Extracted unchanged from
 * PaymentReportController.
 */
final class CollectionTarget
{
    /**
     * confirm()'s course gate, in confirm()'s order: a waived or refunded contract, a tutoring or zero-fee course, and a month
     * another course's live bill already carries take no money. Null = the money may land; otherwise the exact refusal
     * confirm() answers. $invoiceId is the bill the money would land on (null = none named yet).
     */
    public static function gate(?StudentClass $sc, ?int $invoiceId = null, mixed $paymentDate = null): ?JsonResponse
    {
        return self::courseGate($sc) ?? ($sc ? self::sharedBillRefusal($sc, $invoiceId, $paymentDate) : null);
    }

    /** The course half of {@see gate()} (waived / refunded, then tutoring / zero-fee), for callers that batch the shared-month half. */
    public static function courseGate(?StudentClass $sc): ?JsonResponse
    {
        return ContractMoneyState::waivedRefusal($sc, '此合約已確認不收，不能再確認回報') ?? ($sc ? self::tutoringRefusal($sc) : null);
    }

    /** A monthly month already on another course's live (shared) bill is collected there, never again on this course (#4867). */
    public static function sharedBillRefusal(StudentClass $course, ?int $invoiceId, mixed $paymentDate): ?JsonResponse
    {
        if ($course->getAttribute('ScheduleMode') !== 'date') {
            return null;
        }
        $id = (int) $course->getKey();
        $own = $invoiceId === null ? null : Invoice::where('id', $invoiceId)->where('StudentClassID', $id)->value('billing_period');
        $carriers = Invoice::query()->notVoided()->where('StudentClassID', '!=', $id)
            ->whereHas('items', fn ($q) => $q->where('StudentClassID', $id))->orderBy('id')->get();
        $shared = self::carrierOf($course, $own, $paymentDate, $carriers);

        return $shared === null ? null : response()->json([
            'message' => sprintf('這個月的課已經在另一門課的帳單上（帳單 #%d），請從那門課的帳單收款，不能再收一次。沒有新增任何收款。', $shared->id),
            'code' => 'period_on_shared_bill', 'invoice_id' => (int) $shared->id,
        ], 422);
    }

    /**
     * {@see sharedBillRefusal()} for many bills at once, one query for every carrier (the receipt-move picker, #5113).
     *
     * @param iterable<Invoice> $invoices each bill's own course is $courses[StudentClassID]
     * @param Collection<int, StudentClass> $courses
     * @return list<int> ids of the bills whose month another course's live bill already carries
     */
    public static function sharedBillRefusedIds(iterable $invoices, Collection $courses, mixed $paymentDate): array
    {
        $dated = collect($invoices)->filter(fn (Invoice $i) => $courses->get((int) $i->StudentClassID)?->getAttribute('ScheduleMode') === 'date');
        if ($dated->isEmpty()) {
            return [];
        }
        $ids = $dated->pluck('StudentClassID')->map(fn ($v) => (int) $v)->unique()->values()->all();
        $carriers = Invoice::query()->notVoided()->with('items')->whereHas('items', fn ($q) => $q->whereIn('StudentClassID', $ids))->orderBy('id')->get();
        $refused = [];
        foreach ($dated as $invoice) {
            $id = (int) $invoice->StudentClassID;
            $mine = $carriers->filter(fn (Invoice $c) => (int) $c->StudentClassID !== $id && $c->items->contains(fn ($item) => (int) $item->StudentClassID === $id));
            if (self::carrierOf($courses->get($id), $invoice->billing_period, $paymentDate, $mine) !== null) {
                $refused[] = (int) $invoice->id;
            }
        }

        return $refused;
    }

    /** @param Collection<int, Invoice> $carriers live bills of other courses with an item of $course, ascending id */
    private static function carrierOf(StudentClass $course, ?string $ownPeriod, mixed $paymentDate, Collection $carriers): ?Invoice
    {
        $monthlyBilling = app(MonthlyBillingService::class);
        $id = (int) $course->getKey();
        $period = preg_match('/^\d{4}-\d{2}$/', (string) $ownPeriod) ? (string) $ownPeriod : $monthlyBilling->collectionPeriodFor($course, $paymentDate);
        $from = Carbon::createFromFormat('!Y-m', $period)->startOfMonth()->toDateString();
        $to = Carbon::createFromFormat('!Y-m', $period)->endOfMonth()->toDateString();

        return $carriers->first(function (Invoice $invoice) use ($id, $period, $from, $to, $monthlyBilling) {
            [$start, $end] = $monthlyBilling->serviceRangeForCourse($invoice, $id);

            return $start !== null ? $start <= $to && $end >= $from : (string) $invoice->billing_period === $period;
        });
    }

    /** A tutoring course, or one the resolver calls `free` (#3203), has no payment obligation; fail open on a resolver error. */
    public static function tutoringRefusal(mixed $course, bool $explicitFreeGift = false): ?JsonResponse
    {
        if (!$course instanceof StudentClass) {
            return null;
        }

        if (strtolower(trim((string) ($course->ClassType ?? ''))) === 'tutoring') {
            return response()->json([
                'message' => '輔導課無須繳費，不能建立付款回報、收款或付款義務。請先檢查課程帳務資料。',
                'code' => 'tutoring_no_payment_obligation',
            ], 422);
        }
        // #3203: any course the resolver calls `free` (e.g. a 0-total trial) has no payment obligation either.
        // Only 0-charge courses can be free; paid courses skip this and keep the later fail-closed guards.
        if ((int) ($course->Charge ?? 0) > 0 || $explicitFreeGift) {
            return null;
        }
        $id = (int) $course->getKey();
        try {
            $free = (app(BillingPayableResolver::class)->courseStatusesByStudentClassIds([$id])[$id]['status'] ?? null) === 'free';
        } catch (\Throwable $e) {
            $free = false; // the duplicate-payment guard below fails closed on its own
        }
        if ($free) {
            return response()->json([
                'message' => '這門課金額為 0，無須繳費，不能建立付款回報、收款或付款義務。',
                'code' => 'zero_fee_no_payment_obligation',
            ], 422);
        }

        return null;
    }

    /**
     * What a payment settles once its bill is recomputed. F7 S7: only the resolver (all non-void invoices) may settle the
     * course; a partial leaves Paid=0. A count package is one shared obligation; a monthly ('date') member settles only itself
     * (#3892 D4, in-app #478); a bill billed at the entered amount because the package price is unknown never marks it (#4597).
     */
    public static function settle(?StudentClass $sc, ?CoursePackage $package, string $billStatus, bool $enteredOnlyPackageBill, mixed $paymentDate): void
    {
        if ($sc && !$sc->Paid && self::resolvedPaid($sc)) {
            $sc->update([
                'Paid' => 1,
                'PayDate' => Carbon::today()->toDateString(),
                'closed_reason' => $sc->getAttribute('closed_reason') === 'settled_pending'
                    ? 'settled'
                    : $sc->getAttribute('closed_reason'),
            ]);
        }
        if ($package && $billStatus === 'paid' && !$enteredOnlyPackageBill && $sc->getAttribute('ScheduleMode') !== 'date') {
            $paidAt = Carbon::make($paymentDate)?->toDateString()
                ?? Carbon::today()->toDateString();
            PackagePaidMarks::mark($package, $paidAt);
        }
    }

    /** RFC §R4: round(rate × total_sessions) for a per-session count package; null when it cannot be determined. */
    public static function packagePrice(CoursePackage $package): ?int
    {
        $rate = (float) ($package->rate ?? 0);
        $total = (int) ($package->total_sessions ?? 0);

        return ($package->rate_unit ?? null) === 'session' && $rate > 0 && $total > 0 ? (int) round($rate * $total) : null;
    }

    private static function resolvedPaid(StudentClass $sc): bool
    {
        $id = (int) $sc->ID;

        return (app(BillingPayableResolver::class)->courseStatusesByStudentClassIds([$id])[$id]['status'] ?? null) === 'paid';
    }
}
