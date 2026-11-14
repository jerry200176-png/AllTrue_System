<?php

namespace App\Services\Billing;

use App\Models\CoursePackage;
use App\Models\StudentClass;
use App\Services\BillingPayableResolver;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;

/**
 * One home for the rules of money landing on a course, so every path that records a payment applies the same ones
 * (confirm(), and the receipt move of #5113): which courses may take money at all ({@see gate()}), and what a payment
 * settles afterwards ({@see settle()}: the course Paid flag and a shared package's paid mark). Extracted unchanged from
 * PaymentReportController.
 */
final class CollectionTarget
{
    /**
     * confirm()'s course gate: a waived or refunded contract, a tutoring course or a zero-fee course takes no money.
     * Null = the course may take money; otherwise the exact refusal confirm() answers.
     */
    public static function gate(?StudentClass $sc): ?JsonResponse
    {
        return ContractMoneyState::waivedRefusal($sc, '此合約已確認不收，不能再確認回報') ?? ($sc ? self::tutoringRefusal($sc) : null);
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
