<?php

namespace App\Services\Billing;

use App\Helpers\FeatureFlag;
use App\Models\Invoice;
use App\Models\PaymentReport;
use App\Models\StudentClass;
use App\Services\InvoiceAmountReconciliationService;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * #5113 (in-app #522), design PR 3a: READ-ONLY half of "move a confirmed receipt to the correct bill".
 *
 * This slice only answers "may this receipt move to that bill, and what would the two bills look like?"
 * ({@see preview()}, {@see targets()}). It never writes: the unapply/reapply transaction, link row, idempotency
 * replay and audit event are PR 3b, which consumes the same refusals and the `state_hash` returned here.
 *
 * Rules (each refusal has a code and a plain zh-TW message that names the blocker):
 *  - same campus only; the caller must own the campus of the receipt and of the target bill (checked before any state);
 *  - the amount is the receipt's own Payment row on the source bill; every balance is the canonical payable from
 *    {@see InvoiceAmountReconciliationService::resolve()} (read-only), never summed by hand;
 *  - a director may act alone only within `billing.receipt_reassign_director_max_amount` and
 *    `billing.receipt_reassign_director_max_age_days` (both env-driven, 0 = off for directors); above that the
 *    receipt goes to super_admin (HQ) unchanged;
 *  - feature flag `receipt_reassign` (per-campus override supported), default off.
 */
final class ReceiptReassignService
{
    public const FLAG = 'receipt_reassign';

    /** Payment methods that mean a refund or contract transfer already touched the bill's money. */
    private const MONEY_MOVED_METHODS = ['transfer_out', 'transfer_in', 'refund'];

    public function __construct(private InvoiceAmountReconciliationService $amounts)
    {
    }

    /**
     * @param list<int>|null $campusIds caller's campuses; null = super_admin (all campuses, still same-campus moves only)
     * @return array{status:int, body:array<string, mixed>}
     */
    public function preview(int $reportId, int $targetInvoiceId, ?array $campusIds, bool $isSuperAdmin, ?string $reason = null): array
    {
        $ctx = $this->sourceContext($reportId, $campusIds);
        if (isset($ctx['refusal'])) {
            return $ctx['refusal'];
        }
        $report = $ctx['report'];
        $from = $ctx['from'];
        $amount = $ctx['amount'];

        if ($reason !== null && mb_strlen($reason) > 500) {
            return $this->refuse('reassign_reason_too_long', '更正原因請寫在 500 字以內。');
        }

        $toInvoice = DB::table('Invoice')->where('id', $targetInvoiceId)->first();
        if (!$toInvoice) {
            return $this->refuse('reassign_target_not_found', '找不到要移到的帳單，請重新選擇。', 404);
        }
        $toStudentCampus = (int) DB::table('Student')->where('id', $toInvoice->StudentID)->value('CampusID');
        if ($campusIds !== null && !in_array($toStudentCampus, $campusIds, true)) {
            return $this->refuse('reassign_cross_campus', '這張帳單不在你可以管理的分校，不能移過去。', 403);
        }
        if ($toStudentCampus !== $ctx['campus_id']) {
            return $this->refuse('reassign_cross_campus', '只能移到同一分校的帳單。', 422);
        }
        if ((int) $toInvoice->id === (int) $from['invoice']->id) {
            return $this->refuse('reassign_same_bill', '收據已經在這張帳單上，請選另一張帳單。');
        }
        if ((string) $toInvoice->Status === 'void') {
            return $this->refuse('reassign_target_voided', '這張帳單已作廢，不能把收款移過去。');
        }
        $toCourse = $toInvoice->StudentClassID ? DB::table('StudentClass')->where('ID', $toInvoice->StudentClassID)->first() : null;
        if (!$toCourse || (int) $toCourse->StudentID !== (int) $toInvoice->StudentID) {
            return $this->refuse('reassign_target_other_owner', '這張帳單找不到對應的課程，或課程屬於別位學生，請聯絡總部對帳。');
        }
        if ((string) ($toCourse->closed_reason ?? '') === 'refunded' || (string) ($from['course']->closed_reason ?? '') === 'refunded') {
            return $this->refuse('reassign_course_refunded', '這門課有退費紀錄，收據不能移動，請聯絡總部對帳。');
        }
        if ($this->moneyMoved((int) $toInvoice->id) || $this->moneyMoved((int) $from['invoice']->id)) {
            return $this->refuse('reassign_refund_or_transfer_exists', '其中一張帳單有退費或轉課紀錄，收據不能移動，請聯絡總部對帳。');
        }
        if ($this->hasPendingReport((int) $toCourse->ID, $reportId) || $this->hasPendingReport((int) $from['course']->ID, $reportId)) {
            return $this->refuse('reassign_pending_report', '其中一門課還有待核對的收款回報，請先處理完再移動收據。');
        }

        if ($this->packagePaidHold((int) $toInvoice->id, (string) $toInvoice->Status, (int) $toInvoice->StudentClassID)) {
            return $this->refuse('reassign_target_package_paid', '這張帳單屬於已繳清的共用課程包，不能把收款移過去，請聯絡總部對帳。');
        }

        // The canonical payable (same resolver as confirm and every billing surface), not TotalAmount minus payments.
        $toMoney = $this->resolve((int) $toInvoice->id);
        $toBefore = (int) $toMoney['outstanding_amount'];
        if ($amount > $toBefore) {
            $msg = (int) $toMoney['total_amount'] <= 0 ? '這張帳單依實際上課堂數算出來是 0 元，放不下這筆收款。'
                : ($toBefore <= 0 ? '這張帳單已經全部繳清，放不下這筆收款。' : "這張帳單只剩 {$toBefore} 元未繳，收據金額 {$amount} 元放不下。");

            return $this->refuse('reassign_target_overpay', $msg, 422, ['remaining' => max(0, $toBefore)]);
        }

        $ageDays = $this->ageDays($report);
        $hq = $this->hqReason($amount, $ageDays);
        $needsRole = $hq === null ? 'director' : 'super_admin';
        if ($hq !== null && !$isSuperAdmin) {
            $why = [
                'off' => '目前還沒有開放主任自行移動收據，請聯絡總部處理。',
                'amount' => '這筆金額超過主任可自行處理的上限，請聯絡總部處理。',
                'age' => $ageDays === null ? '無法確認這張收據的日期，請聯絡總部處理。' : '這張收據已超過可自行更正的期限，請聯絡總部處理。',
            ][$hq];

            return $this->refuse('reassign_needs_super_admin', $why, 422, ['needs_role' => 'super_admin', 'hq_reason' => $hq]);
        }

        $fromBefore = (int) $from['money']['outstanding_amount'];
        $warnings = ['source_bill_becomes_unpaid'];
        if ((int) ($from['course']->PackageID ?? 0) > 0 || (int) ($toCourse->PackageID ?? 0) > 0) {
            $warnings[] = 'shared_package';
        }

        return ['status' => 200, 'body' => [
            'preview' => true,
            'amount' => $amount,
            'needs_role' => $needsRole,
            'report_age_days' => $ageDays,
            'from' => [
                'student_id' => (int) $from['invoice']->StudentID,
                'course_id' => (int) $from['course']->ID,
                'invoice_id' => (int) $from['invoice']->id,
                'balance_before' => $fromBefore,
                // Money left on the bill after this receipt is taken off, against the resolver's own total (an over-collected bill keeps its surplus).
                'balance_after' => max(0, (int) $from['money']['total_amount'] - ((int) $from['money']['net_applied'] - $amount)),
            ],
            'to' => [
                'student_id' => (int) $toInvoice->StudentID,
                'course_id' => (int) $toCourse->ID,
                'invoice_id' => (int) $toInvoice->id,
                'balance_before' => $toBefore,
                'balance_after' => $toBefore - $amount,
            ],
            'warnings' => $warnings,
            'state_hash' => hash('sha256', json_encode([
                (int) $report->id, (string) $report->status, $amount, (int) $from['invoice']->id, (int) ($report->payment_id ?? 0),
                $from['net'], (string) $from['invoice']->Status, (int) $from['money']['total_amount'], $fromBefore,
                (int) $toInvoice->id, (int) $toMoney['net_applied'], (string) $toInvoice->Status, (int) $toMoney['total_amount'], $toBefore,
            ], JSON_THROW_ON_ERROR)),
        ]];
    }

    /**
     * Shortlist of bills that could take the receipt: same campus, live, not refunded, enough remaining.
     * It is a convenience for the picker; {@see preview()} is the authority and re-checks everything.
     *
     * @param list<int>|null $campusIds
     * @return array{status:int, body:array<string, mixed>}
     */
    public function targets(int $reportId, ?int $studentId, ?array $campusIds): array
    {
        $ctx = $this->sourceContext($reportId, $campusIds);
        if (isset($ctx['refusal'])) {
            return $ctx['refusal'];
        }
        $studentId = $studentId ?: (int) $ctx['report']->StudentID;
        $studentCampus = (int) DB::table('Student')->where('id', $studentId)->value('CampusID');
        if ($campusIds !== null && !in_array($studentCampus, $campusIds, true)) {
            return $this->refuse('reassign_cross_campus', '這位學生不在你可以管理的分校。', 403);
        }
        if ($studentCampus !== $ctx['campus_id']) {
            return $this->refuse('reassign_cross_campus', '只能移到同一分校學生的帳單。', 422);
        }

        $amount = $ctx['amount'];
        $moved = "'" . implode("','", self::MONEY_MOVED_METHODS) . "'";
        // Loose SQL shortlist (same exclusions as preview); the resolver below decides what each bill can really take.
        $candidates = DB::table('Invoice')
            ->join('StudentClass', 'StudentClass.ID', '=', 'Invoice.StudentClassID')
            ->where('Invoice.StudentID', $studentId)
            ->whereColumn('StudentClass.StudentID', 'Invoice.StudentID')
            ->where('Invoice.id', '!=', (int) $ctx['from']['invoice']->id)
            ->whereRaw("COALESCE(Invoice.Status, '') <> 'void'")
            ->whereRaw("COALESCE(StudentClass.closed_reason, '') <> 'refunded'")
            ->whereRaw("NOT EXISTS (SELECT 1 FROM Payment PM WHERE PM.InvoiceID = Invoice.id AND PM.Method IN ($moved))")
            ->orderByDesc('Invoice.id')
            ->limit(200)
            ->pluck('Invoice.id')->all();

        $targets = [];
        $courseIds = DB::table('Invoice')->whereIn('id', $candidates)->pluck('StudentClassID')->unique()->map(fn ($v) => (int) $v)->values()->all();
        $this->amounts->preloadSessions($courseIds);
        try {
            foreach ($candidates as $invoiceId) {
                $inv = DB::table('Invoice')->where('id', $invoiceId)->first();
                if ($this->packagePaidHold((int) $inv->id, (string) $inv->Status, (int) $inv->StudentClassID)) {
                    continue;
                }
                if ($this->hasPendingReport((int) $inv->StudentClassID, $reportId)) {
                    continue; // package-aware, same scope as the preview refusal
                }
                $money = $this->resolve((int) $inv->id);
                if ((int) $money['outstanding_amount'] < $amount) {
                    continue;
                }
                $targets[] = [
                    'invoice_id' => (int) $inv->id,
                    'course_id' => (int) $inv->StudentClassID,
                    'billing_period' => $inv->billing_period,
                    'total' => (int) $money['total_amount'],
                    'remaining' => (int) $money['outstanding_amount'],
                    'status' => $inv->Status,
                ];
                if (count($targets) >= 50) {
                    break;
                }
            }
        } finally {
            $this->amounts->forgetPreloadedSessions();
        }

        return ['status' => 200, 'body' => ['amount' => $amount, 'student_id' => $studentId, 'targets' => $targets]];
    }

    /** 'director' when a director may do it alone, else 'super_admin'. Limits are read from config on every call. */
    public function needsRole(int $amount, ?int $ageDays): string
    {
        return $this->hqReason($amount, $ageDays) === null ? 'director' : 'super_admin';
    }

    /** Why a director may not act alone: 'off' (a limit is 0), 'amount' (over), 'age' (too old or unknown); null = may. */
    public function hqReason(int $amount, ?int $ageDays): ?string
    {
        $maxAmount = $this->directorMaxAmount();
        $maxAge = (int) config('billing.receipt_reassign_director_max_age_days', 0);
        if ($maxAmount <= 0 || $maxAge <= 0) {
            return 'off';
        }
        if ($amount > $maxAmount) {
            return 'amount';
        }

        return $ageDays === null || $ageDays > $maxAge ? 'age' : null;
    }

    private function directorMaxAmount(): int
    {
        return (int) config('billing.receipt_reassign_director_max_amount', 0);
    }

    /**
     * Source side shared by both reads: authz first (before any status), then flag, then the receipt's own state.
     *
     * @param list<int>|null $campusIds
     * @return array<string, mixed>
     */
    private function sourceContext(int $reportId, ?array $campusIds): array
    {
        $report = DB::table('payment_reports')->where('id', $reportId)->first();
        if (!$report) {
            return ['refusal' => $this->refuse('reassign_report_not_found', '找不到這張收據。', 404)];
        }
        $campusId = (int) DB::table('Student')->where('id', $report->StudentID)->value('CampusID');
        if ($campusId <= 0 || ($campusIds !== null && !in_array($campusId, $campusIds, true))) {
            return ['refusal' => ['status' => 403, 'body' => ['message' => 'Forbidden']]]; // before status/flag: no state leak (#4142)
        }
        if (!FeatureFlag::enabled(self::FLAG, $campusId)) {
            return ['refusal' => $this->refuse('reassign_disabled', '這個分校還沒有開放「移到正確帳單」。')];
        }
        if ((string) $report->status !== 'confirmed') {
            return ['refusal' => $this->refuse('reassign_not_confirmed', '只有已確認的收據可以移到別張帳單。')];
        }
        $reported = (float) $report->reported_amount;
        if ($reported < 0) {
            return ['refusal' => $this->refuse('reassign_amount_invalid', '這張收據的金額是負數，這裡不能移動。請聯絡總部對帳。')];
        }
        if ($reported <= 0) {
            return ['refusal' => $this->refuse('reassign_amount_zero', '這張收據金額為 0，沒有可以移動的款項。')];
        }

        // The bill the money physically sits on: the linked Payment's bill, else the receipt's own InvoiceID.
        $payment = $report->payment_id ? DB::table('Payment')->where('id', $report->payment_id)->first() : null;
        $invoiceId = $payment ? (int) $payment->InvoiceID : (int) ($report->InvoiceID ?? 0);
        $invoice = $invoiceId > 0 ? DB::table('Invoice')->where('id', $invoiceId)->first() : null;
        $course = $invoice && $invoice->StudentClassID ? DB::table('StudentClass')->where('ID', $invoice->StudentClassID)->first() : null;
        if (!$invoice || !$course) {
            return ['refusal' => $this->refuse('reassign_source_unresolvable', '這張收據對應的帳單或課程已經找不到，這裡不能移動。請聯絡總部對帳。')];
        }
        $invoiceCampus = (int) DB::table('Student')->where('id', $invoice->StudentID)->value('CampusID');
        if ($campusIds !== null && !in_array($invoiceCampus, $campusIds, true)) {
            return ['refusal' => ['status' => 403, 'body' => ['message' => 'Forbidden']]];
        }
        if ($invoiceCampus !== $campusId) {
            return ['refusal' => $this->refuse('reassign_cross_campus', '這張收據和它所在的帳單不在同一分校，請聯絡總部對帳。')];
        }
        $payments = DB::table('Payment')->where('InvoiceID', $invoice->id)->get();
        $isReversal = fn ($p) => (int) $p->Amount < 0 || (string) $p->Method === 'void';
        if ($payments->contains(fn ($p) => $isReversal($p) && (int) $p->payment_report_id === $reportId)) {
            return ['refusal' => $this->refuse('reassign_source_not_applied', '這張收據的款項已經沖回，沒有可以移動的款項。', 409)];
        }
        if ($payments->contains(fn ($p) => $isReversal($p) && $p->payment_report_id === null && !in_array((string) $p->Method, self::MONEY_MOVED_METHODS, true))) {
            return ['refusal' => $this->refuse('reassign_legacy_reversal_unreconciled', '這張帳單有舊的沖銷紀錄還沒對帳，請先找系統管理員處理。', 409)];
        }

        // The receipt's own money must be on this bill: one positive, non-void Payment of this report (as ContractMoneyState does).
        $receipts = $payments->filter(fn ($p) => (int) $p->Amount > 0 && (string) $p->Method !== 'void'
            && ((int) ($p->payment_report_id ?? 0) === $reportId || (int) $p->id === (int) ($report->payment_id ?? 0)));
        $net = $this->netPaid((int) $invoice->id);
        $amount = $receipts->count() === 1 ? (int) $receipts->first()->Amount : 0;
        if ($amount <= 0 || (float) $amount !== $reported || $net < $amount) {
            return ['refusal' => $this->refuse('reassign_source_not_applied', '這張收據的款項不在它綁定的帳單上（找不到對應的收款，或金額對不上），這裡不能移動。請聯絡總部對帳。', 409)];
        }

        return [
            'report' => $report,
            'campus_id' => $campusId,
            'amount' => $amount,
            'from' => ['invoice' => $invoice, 'course' => $course, 'net' => $net, 'money' => $this->resolve((int) $invoice->id)],
        ];
    }

    /** @return array<string, mixed> the canonical amounts (plain read; the resolver writes nothing) */
    private function resolve(int $invoiceId): array
    {
        $invoice = Invoice::query()->whereKey($invoiceId)->first();
        $invoice->loadMissing(['payments', 'items']);

        return $this->amounts->resolve($invoice, StudentClass::query()->where('ID', (int) $invoice->StudentClassID)->first());
    }

    /** As PaymentReportController::packagePaidHold: a stored-paid bill of a paid shared package stays settled. */
    private function packagePaidHold(int $invoiceId, string $status, int $courseId): bool
    {
        return $status === 'paid' && DB::table('StudentClass')->join('course_packages', 'course_packages.id', '=', 'StudentClass.PackageID')
            ->where('StudentClass.ID', $courseId)->where('course_packages.paid', true)->exists();
    }

    private function netPaid(int $invoiceId): int
    {
        return (int) DB::table('Invoice')->where('id', $invoiceId)->selectRaw(Invoice::netPaidSql('Invoice') . ' AS net')->value('net');
    }

    private function moneyMoved(int $invoiceId): bool
    {
        return DB::table('Payment')->where('InvoiceID', $invoiceId)->whereIn('Method', self::MONEY_MOVED_METHODS)->exists();
    }

    private function hasPendingReport(int $courseId, int $exceptReportId): bool
    {
        return DB::table('payment_reports')->whereIn('StudentClassID', PaymentReport::pendingScopeCourseIds($courseId))->where('status', 'pending')->where('id', '!=', $exceptReportId)->exists();
    }

    private function ageDays(object $report): ?int
    {
        $at = $report->confirmed_at ?? $report->created_at ?? null;
        if (!$at) {
            return null; // unknown age is never "recent": needs HQ
        }

        return (int) max(0, Carbon::parse($at)->startOfDay()->diffInDays(Carbon::today(), false));
    }

    /**
     * @param array<string, mixed> $extra
     * @return array{status:int, body:array<string, mixed>}
     */
    private function refuse(string $code, string $message, int $status = 422, array $extra = []): array
    {
        return ['status' => $status, 'body' => ['message' => $message, 'code' => $code] + $extra];
    }
}
