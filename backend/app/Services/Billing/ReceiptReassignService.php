<?php

namespace App\Services\Billing;

use App\Helpers\FeatureFlag;
use App\Models\CoursePackage;
use App\Models\Invoice;
use App\Models\Payment;
use App\Models\PaymentReport;
use App\Models\PaymentReportReassignment;
use App\Models\SecurityAuditEvent;
use App\Models\StudentClass;
use App\Services\InvoiceAmountReconciliationService;
use App\Support\DeadlockRetry;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * #5113 (in-app #522): move a confirmed receipt to the correct bill.
 *
 * {@see preview()} and {@see targets()} only read (design PR 3a). {@see apply()} (PR 3b) is one transaction: it locks the
 * rows, recomputes the same plan under the locks, refuses unless the caller's `state_hash` still matches, then takes the
 * money off the source bill (a reversal Payment linked to the old receipt and to the move), voids the old receipt,
 * records a new confirmed receipt with its Payment on the target bill, and writes one link row and one audit event.
 * Original Payment rows are never edited (unapply + reapply). The same request again replays the stored move.
 *
 * Rules (each refusal has a code and a plain zh-TW message that names the blocker):
 *  - same campus only; the caller must own the campus of the receipt and of the target bill (checked before any state);
 *  - the amount is the receipt's own Payment row on the source bill; every balance, before and after, is the canonical
 *    payable from {@see InvoiceAmountReconciliationService::resolve()}, never summed by hand;
 *  - a director may act alone only within `billing.receipt_reassign_director_max_amount` and
 *    `billing.receipt_reassign_director_max_age_days` (both env-driven, 0 = off for directors); above that the
 *    receipt goes to super_admin (HQ) unchanged;
 *  - feature flag `receipt_reassign` (per-campus override supported), default off.
 */
final class ReceiptReassignService
{
    public const FLAG = 'receipt_reassign';

    /** Bumped whenever the hashed fields change, so a hash from an older preview can never match. */
    public const STATE_HASH_VERSION = 'v2';

    public const AUDIT_EVENT = 'billing.receipt_reassigned';

    /** Payment methods that mean a refund or contract transfer already touched the bill's money. */
    private const MONEY_MOVED_METHODS = ['transfer_out', 'transfer_in', 'refund'];

    public function __construct(private InvoiceAmountReconciliationService $amounts)
    {
    }

    /** Marker on both Payment rows a move writes: which move they belong to (a later move of the new receipt has its own id). */
    public static function paymentTag(int $reassignmentId): string
    {
        return "[receipt_reassign:{$reassignmentId}]";
    }

    /** The move a Payment row belongs to, or null for any other payment. */
    public static function reassignmentIdOf(?string $note): ?int
    {
        return preg_match('/^\[receipt_reassign:(\d+)\]/', (string) $note, $m) ? (int) $m[1] : null;
    }

    /**
     * @param list<int>|null $campusIds caller's campuses; null = super_admin (all campuses, still same-campus moves only)
     * @return array{status:int, body:array<string, mixed>}
     */
    public function preview(int $reportId, int $targetInvoiceId, ?array $campusIds, bool $isSuperAdmin, ?string $reason = null): array
    {
        $plan = $this->plan($reportId, $targetInvoiceId, $campusIds, $isSuperAdmin, $reason);
        unset($plan['ctx']);

        return $plan;
    }

    /**
     * Apply the move. Refusals write nothing. Replays (same report, target and state_hash) return the stored move.
     *
     * @param list<int>|null $campusIds
     * @return array{status:int, body:array<string, mixed>}
     */
    public function apply(int $reportId, int $targetInvoiceId, ?array $campusIds, bool $isSuperAdmin, ?string $reason, ?string $stateHash, int $actorId): array
    {
        // Routing ids only, outside the transaction: a plain read inside it would fix the REPEATABLE-READ snapshot before
        // this connection waits on the locks below (as PaymentReportController::resolveReportCourseId).
        $route = $this->route($reportId, $targetInvoiceId);
        // A caller outside either campus never reaches the locks (the same answers as the plan's own authz, rechecked inside).
        if ($route !== null && $campusIds !== null) {
            $report = DB::table('payment_reports')->where('id', $reportId)->first(['StudentID']);
            $targetCampus = (int) DB::table('Invoice')->join('Student', 'Student.id', '=', 'Invoice.StudentID')->where('Invoice.id', $targetInvoiceId)->value('Student.CampusID');
            $denied = $this->gate($report, $campusIds);
            if (is_array($denied)) {
                return $denied;
            }
            if (!in_array($targetCampus, $campusIds, true)) {
                return $this->refuse('reassign_cross_campus', '這張帳單不在你可以管理的分校，不能移過去。', 403);
            }
        }
        if ($route === null) {
            $refusal = $this->preview($reportId, $targetInvoiceId, $campusIds, $isSuperAdmin, $reason); // its refusal, nothing to lock

            return $refusal['status'] !== 200 ? $refusal : $this->refuse('reassign_state_changed', '畫面上的帳單資料已經變動，請重新預覽確認後再送出。', 409);
        }

        return DB::transaction(function () use ($route, $reportId, $targetInvoiceId, $campusIds, $isSuperAdmin, $reason, $stateHash, $actorId) {
            // Lock order of confirm()/void(): courses (ascending), the report, packages, then bills and their payments. The courses
            // include every member of either side's package, because the package marks rewrite them (PackagePaidMarks).
            // Every read before the plan is a locking read, so the snapshot of the plan's plain reads starts after the waits.
            $courses = StudentClass::query()->whereIn('ID', $route['courses'])->orderBy('ID')->lockForUpdate()->get(['ID', 'PackageID']);
            $report = PaymentReport::query()->whereKey($reportId)->lockForUpdate()->first();
            $packageIds = $route['packages'];
            if ($packageIds !== []) {
                CoursePackage::query()->whereIn('id', $packageIds)->orderBy('id')->lockForUpdate()->get(['id']);
            }
            $invoiceIds = array_values(array_unique([$route['from_invoice'], $targetInvoiceId]));
            sort($invoiceIds);
            Invoice::query()->whereIn('id', $invoiceIds)->orderBy('id')->lockForUpdate()->get(['id']);
            Payment::query()->whereIn('InvoiceID', $invoiceIds)->orderBy('id')->lockForUpdate()->get(['id']);

            if (!$report) {
                return $this->refuse('reassign_report_not_found', '找不到這張收據。', 404);
            }
            $gate = $this->gate($report, $campusIds);
            if (is_array($gate)) {
                return $gate;
            }
            $key = self::idempotencyKey($reportId, $targetInvoiceId, (string) $stateHash);
            $prior = PaymentReportReassignment::query()->where('from_report_id', $reportId)->first();
            if ($prior) {
                // The same request again (double click, retry after a lost response, a twin that waited on our locks).
                return $prior->idempotency_key === $key
                    ? ['status' => 200, 'body' => ['replayed' => true] + $this->describe($prior)]
                    : $this->refuse('reassign_state_changed', '這張收據已經移到別張帳單了，請重新整理後再確認。', 409);
            }
            // A package joined or left between the routing read and the locks: the locked sibling set is not the one to write.
            if (self::packageIdsOf($courses->whereIn('ID', [$route['from_course'], $route['to_course']])) !== $packageIds) {
                return $this->refuse('reassign_state_changed', '畫面上的帳單資料已經變動，請重新預覽確認後再送出。', 409);
            }
            if (!is_string($stateHash) || $stateHash === '') {
                return $this->refuse('reassign_state_hash_required', '請先預覽一次，確認兩張帳單的金額後再送出。');
            }
            if (!is_string($reason) || trim($reason) === '') {
                return $this->refuse('reassign_reason_required', '請寫下為什麼要把收據移到這張帳單。');
            }

            $plan = $this->plan($reportId, $targetInvoiceId, $campusIds, $isSuperAdmin, $reason); // recomputed under the locks
            if ($plan['status'] !== 200) {
                return $plan;
            }
            $ctx = $plan['ctx'];
            if (!hash_equals((string) $plan['body']['state_hash'], $stateHash)
                || (int) $ctx['from']['invoice']->id !== $route['from_invoice'] || (int) $ctx['to']['course']->ID !== $route['to_course']) {
                return $this->refuse('reassign_state_changed', '畫面上的帳單資料已經變動，請重新預覽確認後再送出。', 409);
            }

            return $this->write($ctx, trim($reason), $key, $actorId);
        }, DeadlockRetry::ATTEMPTS); // a deadlock victim re-runs whole: locks, replay check and plan again, so a re-run never moves twice
    }

    /** @param iterable<object> $courses @return list<int> their package ids, ascending */
    private static function packageIdsOf(iterable $courses): array
    {
        $ids = [];
        foreach ($courses as $c) {
            if ((int) ($c->PackageID ?? 0) > 0) {
                $ids[] = (int) $c->PackageID;
            }
        }
        $ids = array_values(array_unique($ids));
        sort($ids);

        return $ids;
    }

    /**
     * The plan shared by preview and apply; `ctx` carries the loaded rows to apply and is never returned to a client.
     *
     * @param list<int>|null $campusIds
     * @return array{status:int, body:array<string, mixed>, ctx?:array<string, mixed>}
     */
    private function plan(int $reportId, int $targetInvoiceId, ?array $campusIds, bool $isSuperAdmin, ?string $reason): array
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
        $toCourse = $toInvoice->StudentClassID ? StudentClass::query()->where('ID', $toInvoice->StudentClassID)->first() : null;
        if (!$toCourse || (int) $toCourse->StudentID !== (int) $toInvoice->StudentID) {
            return $this->refuse('reassign_target_other_owner', '這張帳單找不到對應的課程，或課程屬於別位學生，請聯絡總部對帳。');
        }
        if ((string) ($toCourse->closed_reason ?? '') === 'refunded' || (string) ($from['course']->closed_reason ?? '') === 'refunded') {
            return $this->refuse('reassign_course_refunded', '這門課有退費紀錄，收據不能移動，請聯絡總部對帳。');
        }
        // confirm()'s own course gate (CollectionTarget), not a copy: a course confirm() would refuse money for refuses the move too.
        if ($closed = CollectionTarget::gate($toCourse, (int) $toInvoice->id, $report->payment_date)) {
            $why = (array) $closed->getData(true);

            return $this->refuse('reassign_target_not_collectable', (string) ($why['message'] ?? ''), 422, ['reason' => $why['code'] ?? null]);
        }
        if ($this->moneyMoved((int) $toInvoice->id) || $this->moneyMoved((int) $from['invoice']->id)) {
            return $this->refuse('reassign_refund_or_transfer_exists', '其中一張帳單有退費或轉課紀錄，收據不能移動，請聯絡總部對帳。');
        }
        if ($this->hasPendingReport((int) $toCourse->ID, $reportId, (int) $toInvoice->id) || $this->hasPendingReport((int) $from['course']->ID, $reportId, (int) $from['invoice']->id)) {
            return $this->refuse('reassign_pending_report', '其中一門課還有待核對的收款回報，請先處理完再移動收據。');
        }

        if (CollectionTarget::packagePaidHold((int) $toInvoice->StudentClassID, (string) $toInvoice->Status)) { // confirm()'s hold, shared
            return $this->refuse('reassign_target_package_paid', '這張帳單屬於已繳清的共用課程包，不能把收款移過去，請聯絡總部對帳。');
        }
        // Paying the target can mark its package paid: a PackageID pointing at another student's or campus's package is not ours to mark.
        if ((int) ($toCourse->PackageID ?? 0) > 0 && self::packageMismatch(DB::table('course_packages')->where('id', (int) $toCourse->PackageID)->first(['student_id', 'campus_id']),
            (int) $toCourse->StudentID, (int) $ctx['campus_id'])) {
            return $this->refuse('reassign_target_package_mismatch', '這張帳單的課程指向的共用方案不屬於同一位學生或分校，請聯絡總部對帳。');
        }
        // Move-only, stricter than confirm(): with no package price nobody can tell whether moving money here settles the package.
        if ((int) ($toCourse->PackageID ?? 0) > 0 && strtolower((string) $toCourse->getAttribute('ScheduleMode')) !== 'date'
            && ($toPackage = CoursePackage::query()->whereKey((int) $toCourse->PackageID)->first()) && CollectionTarget::packagePrice($toPackage) === null) {
            return $this->refuse('reassign_target_package_unpriced', '這張帳單屬於沒有固定總價的共用方案，無法確認移過去後算不算繳清，請聯絡總部處理。');
        }
        // Taking the money back can clear the source package's paid mark: it must be the same student's, in this campus, too.
        if ((int) ($from['course']->PackageID ?? 0) > 0 && self::packageMismatch(DB::table('course_packages')->where('id', (int) $from['course']->PackageID)->first(['student_id', 'campus_id']),
            (int) $from['course']->StudentID, (int) $ctx['campus_id'])) {
            return $this->refuse('reassign_source_package_mismatch', '這張收據所在課程指向的共用方案不屬於同一位學生或分校，請聯絡總部對帳。');
        }

        // The canonical payable (same resolver as confirm and every billing surface), not TotalAmount minus payments.
        [$toModel, $toMoney] = $this->money((int) $toInvoice->id, $toCourse);
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

        // Both after-balances through the resolver on the bill as it will stand (#5113 must-do 1): an over-collected source
        // keeps its surplus, a monthly source back at net 0 is repriced, a repriced monthly target is priced as apply saves it.
        $fromAfter = $this->projectAfter($from['model'], $from['course'], $from['money'], -$amount, false);
        $toAfter = $this->projectAfter($toModel, $toCourse, $toMoney, $amount, true);
        // The bill this receipt's confirmation auto-issued is voided with it, exactly as apply does (FirstBillAutoVoid).
        $sourceVoided = FirstBillAutoVoid::applies($from['model'], (int) $report->id, $report->payment_id ? (int) $report->payment_id : null,
            $from['model']->payments, (int) $from['course']->ID);
        if ($sourceVoided) {
            $fromAfter['outstanding_amount'] = 0;
        }
        // Package identity and membership of both sides: joining or leaving a package changes which courses the move marks.
        $packageIds = self::packageIdsOf([$from['course'], $toCourse]);
        $packageMembers = $packageIds === [] ? [] : DB::table('StudentClass')->whereIn('PackageID', $packageIds)->orderBy('ID')->pluck('ID')->map(fn ($v) => (int) $v)->all();
        $fromBefore = (int) $from['money']['outstanding_amount'];
        $warnings = [$sourceVoided ? 'source_bill_voided' : 'source_bill_becomes_unpaid'];
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
                'balance_after' => (int) $fromAfter['outstanding_amount'],
            ],
            'to' => [
                'student_id' => (int) $toInvoice->StudentID,
                'course_id' => (int) $toCourse->ID,
                'invoice_id' => (int) $toInvoice->id,
                'balance_before' => $toBefore,
                'balance_after' => (int) $toAfter['outstanding_amount'],
            ],
            'warnings' => $warnings,
            // Every value the move depends on, owner and course of both sides included (must-do 4); apply recomputes it under lock.
            'state_hash' => self::STATE_HASH_VERSION . ':' . hash('sha256', json_encode([
                (int) $report->id, (string) $report->status, $amount, (int) $from['invoice']->id, (int) ($report->payment_id ?? 0),
                $from['net'], (string) $from['invoice']->Status, (int) $from['money']['total_amount'], $fromBefore,
                (int) $toInvoice->id, (int) $toMoney['net_applied'], (string) $toInvoice->Status, (int) $toMoney['total_amount'], $toBefore,
                (int) $from['invoice']->StudentID, (int) $from['course']->ID, (string) ($from['course']->closed_reason ?? ''),
                (int) $toInvoice->StudentID, (int) $toCourse->ID, (string) ($toCourse->closed_reason ?? ''),
                // The projected results too: a session change after the preview can move a repriced balance with no stored field changing.
                (int) $fromAfter['total_amount'], (int) $fromAfter['outstanding_amount'], (int) $toAfter['total_amount'], (int) $toAfter['outstanding_amount'], $sourceVoided,
                (int) ($from['course']->PackageID ?? 0), (int) ($toCourse->PackageID ?? 0), $packageMembers,
            ], JSON_THROW_ON_ERROR)),
        ], 'ctx' => [
            'report' => $report, 'campus_id' => $ctx['campus_id'], 'amount' => $amount, 'from' => $from,
            'to' => ['invoice' => $toInvoice, 'course' => $toCourse, 'model' => $toModel, 'money' => $toMoney],
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

        // #5113 must-do 6: every per-bill read below is one query per kind for all candidates, never one per candidate.
        $invoices = Invoice::query()->with(['payments', 'items'])->whereIn('id', $candidates)->orderByDesc('id')->get();
        $courseIds = $invoices->pluck('StudentClassID')->map(fn ($v) => (int) $v)->unique()->values()->all();
        $courses = StudentClass::query()->with('pricingAmendments')->whereIn('ID', $courseIds)->get()->keyBy(fn (StudentClass $c) => (int) $c->ID);
        // confirm()'s package-paid hold (CollectionTarget), batched: a stored-paid bill of a paid shared package stays settled.
        $paidPackageCourses = CollectionTarget::paidPackageCourseIds($courseIds);
        // As hasPendingReport(): package-aware, same scope as the preview refusal.
        $scopes = PaymentReport::pendingScopeCourseIdsFor($courseIds);
        $pendingCourses = $courseIds === [] ? [] : DB::table('payment_reports')->whereIn('StudentClassID', array_merge([], ...array_values($scopes)))
            ->where('status', 'pending')->where('id', '!=', $reportId)->pluck('StudentClassID')->map(fn ($v) => (int) $v)->unique()->all();
        // As hasPendingReport(): a pending report bound to the bill itself by InvoiceID.
        $pendingInvoices = DB::table('payment_reports')->whereIn('InvoiceID', $invoices->pluck('id')->all())->where('status', 'pending')
            ->where('id', '!=', $reportId)->pluck('InvoiceID')->map(fn ($v) => (int) $v)->unique()->all();
        // As the preview's reassign_target_package_mismatch: never offer a bill whose course points at someone else's package.
        $packages = DB::table('course_packages')->whereIn('id', self::packageIdsOf($courses))->get(['id', 'student_id', 'campus_id'])->keyBy(fn ($p) => (int) $p->id);
        // As the preview's gate: confirm()'s shared-month refusal, for every candidate in one query.
        $sharedMonth = CollectionTarget::sharedBillRefusedIds($invoices, $courses, $ctx['report']->payment_date);

        $targets = [];
        $this->amounts->preloadSessions($courseIds);
        try {
            foreach ($invoices as $inv) {
                $courseId = (int) $inv->StudentClassID;
                if (CollectionTarget::packagePaidHold($courseId, (string) $inv->Status, $paidPackageCourses)) {
                    continue;
                }
                if (array_intersect($scopes[$courseId] ?? [$courseId], $pendingCourses) !== [] || in_array((int) $inv->id, $pendingInvoices, true)) {
                    continue;
                }
                $course = $courses->get($courseId);
                if ($course && (CollectionTarget::courseGate($course) !== null || in_array((int) $inv->id, $sharedMonth, true))) {
                    continue; // as the preview's reassign_target_not_collectable
                }
                if ($course && (int) ($course->PackageID ?? 0) > 0
                    && self::packageMismatch($packages->get((int) $course->PackageID), (int) $course->StudentID, (int) $ctx['campus_id'])) {
                    continue;
                }
                $money = $this->amounts->resolve($inv, $courses->get($courseId));
                if ((int) $money['outstanding_amount'] < $amount) {
                    continue;
                }
                $targets[] = [
                    'invoice_id' => (int) $inv->id,
                    'course_id' => $courseId,
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
        $campusId = $this->gate($report, $campusIds);
        if (is_array($campusId)) {
            return ['refusal' => $campusId];
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
        $course = $invoice && $invoice->StudentClassID ? StudentClass::query()->where('ID', $invoice->StudentClassID)->first() : null;
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
        if ((int) $course->StudentID !== (int) $invoice->StudentID) {
            // The move rewrites this course's Paid state (and its package's): a bill pointing at another student's course is not ours to touch.
            return ['refusal' => $this->refuse('reassign_source_other_owner', '這張收據所在的帳單指向別位學生的課程，這裡不能移動。請聯絡總部對帳。')];
        }
        $payments = DB::table('Payment')->where('InvoiceID', $invoice->id)->get();
        // void()'s own row predicates (Payment): already reversed, and a legacy unlinked reversal nobody can match.
        if ($payments->contains(fn ($p) => Payment::reversesReport($p, $reportId))) {
            return ['refusal' => $this->refuse('reassign_source_not_applied', '這張收據的款項已經沖回，沒有可以移動的款項。', 409)];
        }
        if ($payments->contains(fn ($p) => Payment::isUnlinkedLegacyReversal($p))) {
            return ['refusal' => $this->refuse('reassign_legacy_reversal_unreconciled', '這張帳單有舊的沖銷紀錄還沒對帳，請先找系統管理員處理。', 409)];
        }

        // The receipt's own money must be on this bill: exactly one money row of this receipt (ContractMoneyState's predicate).
        $receipts = $payments->filter(fn ($p) => Payment::isReceiptOf($p, $reportId, isset($report->payment_id) ? (int) $report->payment_id : null));
        // A stale payment_id may point at another receipt's money: only an unlinked (legacy) payment may be claimed by the pointer.
        if ($receipts->contains(fn ($p) => $p->payment_report_id !== null && (int) $p->payment_report_id !== $reportId)) {
            return ['refusal' => $this->refuse('reassign_source_payment_other_report', '這張收據指到的收款屬於另一張收據，這裡不能移動。請聯絡總部對帳。', 409)];
        }
        $net = $this->netPaid((int) $invoice->id);
        $amount = $receipts->count() === 1 ? (int) $receipts->first()->Amount : 0;
        if ($amount <= 0 || (float) $amount !== $reported || $net < $amount) {
            return ['refusal' => $this->refuse('reassign_source_not_applied', '這張收據的款項不在它綁定的帳單上（找不到對應的收款，或金額對不上），這裡不能移動。請聯絡總部對帳。', 409)];
        }
        [$model, $money] = $this->money((int) $invoice->id, $course);

        return [
            'report' => $report,
            'campus_id' => $campusId,
            'amount' => $amount,
            'from' => ['invoice' => $invoice, 'course' => $course, 'net' => $net, 'model' => $model, 'money' => $money],
        ];
    }

    /** @return array<string, mixed> the canonical amounts (plain read; the resolver writes nothing) */
    private function resolve(int $invoiceId): array
    {
        return $this->money($invoiceId, null)[1];
    }

    /** @return array{0: Invoice, 1: array<string, mixed>} the bill (payments and items loaded) and its canonical amounts */
    private function money(int $invoiceId, ?StudentClass $course): array
    {
        $invoice = Invoice::query()->whereKey($invoiceId)->firstOrFail();
        $invoice->loadMissing(['payments', 'items']);

        return [$invoice, $this->amounts->resolve($invoice, $course ?? StudentClass::query()->where('ID', (int) $invoice->StudentClassID)->first())];
    }

    /**
     * Authz first (before any status), then the flag.
     *
     * @param list<int>|null $campusIds
     * @return array{status:int, body:array<string, mixed>}|int the refusal, or the receipt's campus
     */
    private function gate(object $report, ?array $campusIds): array|int
    {
        $campusId = (int) DB::table('Student')->where('id', $report->StudentID)->value('CampusID');
        if ($campusId <= 0 || ($campusIds !== null && !in_array($campusId, $campusIds, true))) {
            return ['status' => 403, 'body' => ['message' => 'Forbidden']]; // before status/flag: no state leak (#4142)
        }
        if (!FeatureFlag::enabled(self::FLAG, $campusId)) {
            return $this->refuse('reassign_disabled', '這個分校還沒有開放「移到正確帳單」。');
        }

        return $campusId;
    }

    /**
     * Routing read before the transaction: the bill the money sits on, its course, and the target's course. Null when any
     * is missing (the plan then answers with its own refusal).
     *
     * @return array{from_invoice:int, from_course:int, to_course:int, courses:list<int>, packages:list<int>}|null
     */
    private function route(int $reportId, int $targetInvoiceId): ?array
    {
        $report = DB::table('payment_reports')->where('id', $reportId)->first(['payment_id', 'InvoiceID']);
        if (!$report) {
            return null;
        }
        $fromInvoice = $report->payment_id ? (int) DB::table('Payment')->where('id', $report->payment_id)->value('InvoiceID') : 0;
        $fromInvoice = $fromInvoice ?: (int) ($report->InvoiceID ?? 0);
        $fromCourse = (int) DB::table('Invoice')->where('id', $fromInvoice)->value('StudentClassID');
        $toCourse = (int) DB::table('Invoice')->where('id', $targetInvoiceId)->value('StudentClassID');
        if ($fromInvoice <= 0 || $fromCourse <= 0 || $toCourse <= 0) {
            return null;
        }
        $packages = self::packageIdsOf(DB::table('StudentClass')->whereIn('ID', [$fromCourse, $toCourse])->get(['PackageID']));
        $courses = array_merge([$fromCourse, $toCourse], $packages === [] ? []
            : DB::table('StudentClass')->whereIn('PackageID', $packages)->pluck('ID')->map(fn ($v) => (int) $v)->all());
        $courses = array_values(array_unique($courses));
        sort($courses);

        return ['from_invoice' => $fromInvoice, 'from_course' => $fromCourse, 'to_course' => $toCourse, 'courses' => $courses, 'packages' => $packages];
    }

    /** Scoped to the source receipt and the exact plan: the same preview sent twice is one move. */
    private static function idempotencyKey(int $reportId, int $targetInvoiceId, string $stateHash): string
    {
        $h = hash('sha256', "{$reportId}|{$targetInvoiceId}|{$stateHash}");

        return substr($h, 0, 8) . '-' . substr($h, 8, 4) . '-' . substr($h, 12, 4) . '-' . substr($h, 16, 4) . '-' . substr($h, 20, 12);
    }

    /**
     * The writes of one move; the caller holds every lock and has re-checked the plan.
     *
     * @param array<string, mixed> $ctx
     * @return array{status:int, body:array<string, mixed>}
     */
    private function write(array $ctx, string $reason, string $key, int $actorId): array
    {
        $old = PaymentReport::query()->whereKey((int) $ctx['report']->id)->firstOrFail();
        $amount = (int) $ctx['amount'];
        /** @var Invoice $fromInvoice */
        $fromInvoice = $ctx['from']['model'];
        /** @var Invoice $toInvoice */
        $toInvoice = $ctx['to']['model'];
        $fromCourseId = (int) $ctx['from']['course']->ID;
        $toCourseId = (int) $ctx['to']['course']->ID;

        $new = PaymentReport::create([
            'StudentID' => (int) $toInvoice->StudentID, 'StudentClassID' => $toCourseId, 'InvoiceID' => (int) $toInvoice->id,
            'billing_period' => preg_match('/^\d{4}-\d{2}$/', (string) $toInvoice->billing_period) ? $toInvoice->billing_period : null,
            'reported_by_name' => (string) $old->reported_by_name, 'payment_date' => $old->payment_date, 'payment_method' => $old->payment_method,
            'reported_amount' => $amount, 'account_last5' => $old->account_last5, 'note' => $old->displayNote() ?: null, // the receipt keeps the note it showed; the Payment note carries the move tag
            'status' => 'confirmed', 'confirmed_by' => $actorId, 'confirmed_at' => Carbon::now(),
            'report_token_hash' => hash('sha256', 'receipt-reassign|' . $key), 'token_expires_at' => Carbon::now(),
        ]);
        $link = PaymentReportReassignment::create([
            'from_report_id' => (int) $old->id, 'to_report_id' => (int) $new->id,
            'from_invoice_id' => (int) $fromInvoice->id, 'to_invoice_id' => (int) $toInvoice->id,
            'from_student_id' => (int) $fromInvoice->StudentID, 'to_student_id' => (int) $toInvoice->StudentID,
            'campus_id' => (int) $ctx['campus_id'], 'amount' => $amount, 'reason' => $reason,
            'idempotency_key' => $key, 'actor_user_id' => $actorId,
        ]);
        $tag = self::paymentTag((int) $link->id);

        // Unapply: a reversal linked to the old receipt and to this move (must-do 5), so a move back is never read as "already reversed".
        $paymentsBefore = $fromInvoice->payments;
        Payment::create(['InvoiceID' => (int) $fromInvoice->id, 'Amount' => -$amount, 'PaidAt' => Carbon::today()->toDateString(),
            'Method' => 'void', 'Note' => "{$tag} 更正移轉：{$reason}", 'payment_report_id' => (int) $old->id]);
        $this->settleBill($fromInvoice, null);
        // As void() (#531 / #5160): the bill this receipt's own confirmation auto-issued goes with its only receipt.
        $autoVoided = FirstBillAutoVoid::apply($fromInvoice, (int) $old->id, $old->payment_id === null ? null : (int) $old->payment_id,
            $paymentsBefore, $fromCourseId, $actorId);
        $this->settleSourceCourse($fromCourseId, (string) $fromInvoice->Status, $autoVoided);

        // Reapply: a repriced monthly target is saved at the total the plan used, as confirm() does (must-do 2), then the money lands.
        $toCourse = StudentClass::query()->where('ID', $toCourseId)->firstOrFail();
        if ((string) $toCourse->ScheduleMode === 'date') {
            app(MonthlyBillFreeze::class)->priceBeforeFirstPayment($toInvoice, $toCourse, $ctx['to']['money'], $old->payment_date, $ctx['to']['money']['billing_period']);
            $toInvoice->save();
        }
        $payment = Payment::create(['InvoiceID' => (int) $toInvoice->id, 'Amount' => $amount, 'PaidAt' => $old->payment_date,
            'Method' => $old->payment_method === 'transfer' ? 'transfer' : 'cash', 'Note' => "{$tag} 更正移轉自收據 #{$old->id}",
            'payment_report_id' => (int) $new->id]);
        $this->settleBill($toInvoice, $actorId);
        // confirm()'s settle rules (CollectionTarget): course Paid from the resolver, the package mark as confirm sets it on an existing bill.
        $toCourse->refresh();
        $toPackage = (int) ($toCourse->PackageID ?? 0) > 0 ? CoursePackage::query()->whereKey((int) $toCourse->PackageID)->first() : null;
        CollectionTarget::settle($toCourse, $toPackage, (string) $toInvoice->Status, false, $old->payment_date);

        $new->update(['payment_id' => (int) $payment->id]);
        $old->update(['status' => 'voided', 'voided_by' => $actorId, 'voided_at' => Carbon::now(),
            'void_reason' => mb_substr("更正移轉：{$reason}", 0, 500)]);

        SecurityAuditEvent::append(self::AUDIT_EVENT, 'success', [
            'campus_id' => (int) $ctx['campus_id'], 'actor_type' => 'user', 'actor_id' => $actorId,
            'subject_type' => 'payment_report_reassignment', 'subject_id' => (int) $link->id,
        ], ['amount' => $amount, 'reason_hash' => hash('sha256', $reason)], true); // required: an audit failure rolls the move back
        Log::info('[ReceiptReassign]', ['reassignment_id' => (int) $link->id, 'from_report_id' => (int) $old->id, 'to_report_id' => (int) $new->id,
            'from_invoice_id' => (int) $fromInvoice->id, 'to_invoice_id' => (int) $toInvoice->id, 'amount' => $amount, 'actor_id' => $actorId]);

        return ['status' => 201, 'body' => ['replayed' => false] + $this->describe($link)];
    }

    /** PaidAmount/Status from the bill's Payment rows through the resolver (never stored PaidAmount ± amount). A void bill stays void. */
    private function settleBill(Invoice $invoice, ?int $reconciledBy): void
    {
        $invoice->setRelation('payments', $invoice->payments()->get());
        $invoice->loadMissing('items');
        $money = $this->amounts->resolve($invoice, StudentClass::query()->where('ID', (int) $invoice->StudentClassID)->first());
        $net = (int) $money['net_applied'];
        $status = (string) $invoice->Status === 'void' ? 'void' : Invoice::statusFromProjection($money); // confirm()'s rule, shared
        $invoice->update(['PaidAmount' => $net, 'Status' => $status]
            + ($reconciledBy !== null ? ['reconciled_at' => Carbon::now(), 'reconciled_by' => $reconciledBy] : []));
    }

    /** void()'s unsettle rule (CollectionTarget::unsettle), with void()'s inputs: package when the bill is no longer paid, live-ledger backing. */
    private function settleSourceCourse(int $courseId, string $billStatus, bool $billAutoVoided): void
    {
        $course = StudentClass::query()->where('ID', $courseId)->firstOrFail();
        $package = $billStatus !== 'paid' && (int) ($course->PackageID ?? 0) > 0 ? CoursePackage::query()->whereKey((int) $course->PackageID)->first() : null;
        // As void(): an auto-voided bill, or money off an already-void bill with no live bill left, backs no settlement.
        CollectionTarget::unsettle($course, $package, !$billAutoVoided && ($billStatus !== 'void' || Invoice::courseHasLiveBill($courseId)));
    }

    /**
     * The resolver's view of a bill after $delta lands on it, simulated in memory (nothing is saved). The target is first
     * priced the way {@see MonthlyBillFreeze} prices it, so preview and apply agree.
     *
     * @param array<string, mixed> $before
     * @return array<string, mixed>
     */
    private function projectAfter(Invoice $invoice, ?StudentClass $course, array $before, int $delta, bool $freeze): array
    {
        $sim = clone $invoice;
        if ($freeze && $before['repriceable']) {
            $sim->setAttribute('TotalAmount', (int) $before['total_amount']);
        }
        $sim->setRelation('payments', $invoice->payments->concat([new Payment(['Amount' => $delta, 'Method' => $delta < 0 ? 'void' : 'transfer'])]));
        if ((string) $invoice->Status !== 'void') {
            $sim->setAttribute('Status', (int) $before['net_applied'] + $delta > 0 ? 'partial' : 'unpaid');
        }

        return $this->amounts->resolve($sim, $course);
    }

    /** @return array<string, mixed> the stored move (identical for the first answer and every replay) */
    private function describe(PaymentReportReassignment $link): array
    {
        $side = function (int $invoiceId): array {
            $status = (string) DB::table('Invoice')->where('id', $invoiceId)->value('Status');

            return ['invoice_id' => $invoiceId, 'balance_after' => $status === 'void' ? 0 : (int) $this->resolve($invoiceId)['outstanding_amount'], 'status' => $status];
        };

        return [
            'reassignment_id' => (int) $link->id, 'old_report_id' => (int) $link->from_report_id, 'new_report_id' => (int) $link->to_report_id,
            'amount' => (int) $link->amount, 'from_student_id' => (int) $link->from_student_id, 'to_student_id' => (int) $link->to_student_id,
            'from' => $side((int) $link->from_invoice_id), 'to' => $side((int) $link->to_invoice_id),
        ];
    }

    private function netPaid(int $invoiceId): int
    {
        return (int) DB::table('Invoice')->where('id', $invoiceId)->selectRaw(Invoice::netPaidSql('Invoice') . ' AS net')->value('net');
    }

    private function moneyMoved(int $invoiceId): bool
    {
        return DB::table('Payment')->where('InvoiceID', $invoiceId)->whereIn('Method', self::MONEY_MOVED_METHODS)->exists();
    }

    /** A pending report on the course (package-aware) or bound to the bill itself by InvoiceID, whatever course it names. */
    private function hasPendingReport(int $courseId, int $exceptReportId, int $invoiceId): bool
    {
        return DB::table('payment_reports')->where(fn ($q) => $q->whereIn('StudentClassID', PaymentReport::pendingScopeCourseIds($courseId))->orWhere('InvoiceID', $invoiceId))
            ->where('status', 'pending')->where('id', '!=', $exceptReportId)->exists();
    }

    /** The target's package must be its own student's in this campus: paying the bill can mark that package paid. */
    private static function packageMismatch(?object $package, int $studentId, int $campusId): bool
    {
        return !$package || (int) $package->student_id !== $studentId || (int) $package->campus_id !== $campusId;
    }

    /** Age of the money, not of the latest receipt: a moved receipt keeps the age of the one it replaced (no reset past the limit). */
    private function ageDays(object $report): ?int
    {
        for ($hop = 0; $hop < 50; $hop++) {
            $fromId = DB::table('payment_report_reassignments')->where('to_report_id', (int) $report->id)->value('from_report_id');
            $prev = $fromId ? DB::table('payment_reports')->where('id', $fromId)->first() : null;
            if (!$prev) {
                break;
            }
            $report = $prev;
        }
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
