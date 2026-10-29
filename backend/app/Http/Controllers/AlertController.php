<?php

namespace App\Http\Controllers;

use App\Models\Campus;
use App\Models\ClassSession;
use App\Models\CoursePackage;
use App\Models\FeeCharge;
use App\Models\Invoice;
use App\Models\PaymentReport;
use App\Models\Student;
use App\Models\StudentClass;
use App\Services\Billing\ContractMoneyState;
use App\Services\InvoiceAmountReconciliationService;
use App\Services\MonthlyBillingService;
use App\Services\BillingPayableResolver;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class AlertController extends Controller
{
    public function __construct(
        private MonthlyBillingService $monthlyBilling,
        private InvoiceAmountReconciliationService $invoiceAmounts,
        private BillingPayableResolver $payableResolver
    )
    {
    }

    /**
     * GET /api/v1/alerts/tuition
     * 主任儀表板「繳費提醒」資料源。規則見 docs/DIRECTOR_PAYMENT_ALERT_RULES.md
     *
     * 堂數制 (ScheduleMode=count)：未繳費，或剩餘堂數 <= 2（含 0 堂）。
     * 月結制 (ScheduleMode=date)：有設定 settlement_day，且 (a) 未繳費且已過本月繳費日 → 一律提醒；
     * (b) 未繳費且尚未到繳費日 → 距繳費日 <= 5 天提醒；(c) 已繳費 → 僅在「下一個」繳費日前 <= 5 天提醒。
     */
    public function tuition(Request $request)
    {
        $role = $request->attributes->get('auth_role');
        $campusIds = $role === 'super_admin' ? [] : $request->attributes->get('auth_campus_ids', []);
        if ($role !== 'super_admin' && empty($campusIds)) { // #3942 review: an empty campus scope never means "every campus"
            return response()->json(['message' => 'Forbidden'], 403);
        }

        if ($request->filled('branch_id')) {
            $bid = (int) $request->input('branch_id');
            if ($role !== 'super_admin' && !in_array($bid, $campusIds, true)) {
                return response()->json(['message' => 'Forbidden'], 403);
            }
            $campusIds = [$bid];
        }

        $studentIds = empty($campusIds) ? null : Student::whereIn('CampusID', $campusIds)->pluck('id');

        $today = Carbon::today();

        $pendingReportClassIds = DB::table('payment_reports')
            ->where('status', 'pending')
            ->pluck('StudentClassID')
            ->filter()
            ->map(fn ($id) => (int) $id)
            ->unique()
            ->values()
            ->all();

        $pendingReportPkgIds = !empty($pendingReportClassIds)
            ? StudentClass::query()
                ->whereIn('ID', $pendingReportClassIds)
                ->whereNotNull('PackageID')
                ->pluck('PackageID')
                ->filter()
                ->map(fn ($id) => (int) $id)
                ->unique()
                ->values()
                ->all()
            : [];

        $countQuery = StudentClass::query()
            ->where('Stop', 0)
            ->where('ScheduleMode', 'count')
            ->where(function ($q) {
                $q->whereRaw("LOWER(TRIM(COALESCE(ClassType, ''))) <> ?", ['tutoring']);
            })
            ->where(function ($q) use ($pendingReportClassIds) {
                $q->where('Paid', 0)
                  ->orWhereNull('Paid')
                  ->orWhere('RemainingSessions', '<=', 2)
                  // Founder Q2: invoices + payments are the truth; Paid=1 never hides a contract whose bill is still open.
                  ->orWhereExists(fn ($inv) => $inv->select(DB::raw(1))->from('Invoice')
                      ->whereColumn('Invoice.StudentClassID', 'StudentClass.ID')
                      ->whereRaw(Invoice::mayOweSql()));
                if (!empty($pendingReportClassIds)) {
                    $q->orWhereIn('ID', $pendingReportClassIds);
                }
            });
        if ($studentIds !== null) {
            $countQuery->whereIn('StudentID', $studentIds);
        }

        $dateQuery = StudentClass::query()
            ->where('Stop', 0)
            ->where('ScheduleMode', 'date')
            ->where(function ($q) {
                $q->whereRaw("LOWER(TRIM(COALESCE(ClassType, ''))) <> ?", ['tutoring']);
            })
            ->whereNotNull('settlement_day')
            ->whereBetween('settlement_day', [1, 31]);
        if ($studentIds !== null) {
            $dateQuery->whereIn('StudentID', $studentIds);
        }

        $countResults = $countQuery->with('student')->get();
        $dateResults  = $dateQuery->with('student')->get();
        // Stopped contracts that still owe per the invoices (resolver: unpaid/partial/unbilled with
        // outstanding > 0) stay actionable in 帳務中心, whatever closed_reason says (paused ones included).
        // waived = 確認不收 is history, never pending. F7 S3a.
        $stoppedResults = StudentClass::query()
            ->where('Stop', 1)
            ->where(function ($q) {
                $q->whereRaw("LOWER(TRIM(COALESCE(ClassType, ''))) <> ?", ['tutoring']);
            })
            ->where(function ($q) {
                $q->whereNull('closed_reason')->orWhere('closed_reason', '!=', 'waived');
            })
            ->with('student');
        if ($studentIds !== null) {
            $stoppedResults->whereIn('StudentID', $studentIds);
        }
        $stoppedRows = $stoppedResults->get();
        // In-app #440 (Founder 2026-10-29): a plain pause that never started is not owed while paused (StudentClass::unstartedPauseIds).
        $unstartedPauses = StudentClass::unstartedPauseIds($stoppedRows->pluck('ID')->all());
        $stoppedResults = $stoppedRows->reject(fn (StudentClass $c) => isset($unstartedPauses[(int) $c->ID]))->values();
        $stoppedStatuses = $this->payableResolver
            ->courseStatusesByStudentClassIds($stoppedResults->pluck('ID')->all(), $stoppedResults);
        // In-app #394: a review_required verdict built from issued invoices still owes their open balance.
        // Dropping it hid the student from the list (shown 已收) while the drawer showed 未繳 for the same bill.
        // Review without an issued invoice is an estimate only and stays in 已結清 → 待核對.
        $pendingSettlementResults = $stoppedResults
            ->filter(function (StudentClass $c) use ($stoppedStatuses) {
                $s = $stoppedStatuses[(int) $c->ID] ?? [];

                return (in_array($s['status'] ?? null, ['unpaid', 'partial', 'unbilled'], true) && (int) ($s['outstanding'] ?? 0) > 0)
                    || BillingPayableResolver::isAmountPendingConfirmation($c, $s);
            })
            ->values();

        $countPkgQuery = CoursePackage::query()
            ->where('billing_mode', CoursePackage::BILLING_MODE_SESSION)
            ->where('stop', false)
            ->where(function ($q) use ($pendingReportPkgIds) {
                $q->where('paid', false)
                  ->orWhereNull('paid')
                  ->orWhere('remaining_sessions', '<=', 2);
                if (!empty($pendingReportPkgIds)) {
                    $q->orWhereIn('id', $pendingReportPkgIds);
                }
            });
        if ($studentIds !== null) {
            $countPkgQuery->whereIn('student_id', $studentIds);
        }
        $countPkgs = $countPkgQuery->with('student')->get();
        $countPkgMemberIds = collect();
        $countPkgAnchorById = collect();
        $countPkgMembersByPkgId = collect();
        if ($countPkgs->isNotEmpty()) {
            $members = StudentClass::whereIn('PackageID', $countPkgs->pluck('id'))
                ->orderBy('Stop')
                ->orderBy('ID')
                ->get(['ID', 'PackageID', 'Stop']);
            $countPkgMemberIds = $members->pluck('ID');
            $countPkgMembersByPkgId = $members->groupBy('PackageID');
            $countPkgAnchorById = $countPkgMembersByPkgId
                ->map(fn ($items) => $items->first());
        }

        $allClassIds = $countResults->pluck('ID')
            ->merge($dateResults->pluck('ID'))
            ->merge($pendingSettlementResults->pluck('ID'))
            ->merge($countPkgMemberIds)
            ->unique()
            ->values()
            ->all();
        $paidAtMap = $this->payableResolver->lastPaidAtByStudentClassIds($allClassIds);
        $paymentHistoryMap = $this->payableResolver->studentClassIdsWithPaymentHistory($allClassIds);
        $invoiceAggMap = $this->payableResolver->invoiceAggregateByStudentClassIds($allClassIds);
        $allResults = $countResults->merge($dateResults)->merge($pendingSettlementResults)->keyBy('ID');
        $payableMap = $this->payableResolver->byStudentClassIds($allClassIds, $allResults);
        // F7 S3b: `outstanding` is the course-level answer (all open periods); payable_outstanding stays per invoice.
        $courseStatuses = $this->payableResolver->courseStatusesByStudentClassIds($allClassIds, $allResults);
        // #3203: courses the resolver calls `free` (tutoring or zero-fee, e.g. a 0-total trial) owe nothing,
        // so they never appear as unpaid — the same rule the SQL above already applies to tutoring.
        $freeIds = collect($courseStatuses)->filter(fn ($s) => ($s['status'] ?? null) === 'free')->keys()->map(fn ($k) => (int) $k)->all();
        if ($freeIds !== []) {
            $notFree = fn ($c) => !in_array((int) $c->ID, $freeIds, true);
            $countResults = $countResults->filter($notFree)->values();
            $dateResults = $dateResults->filter($notFree)->values();
            $pendingSettlementResults = $pendingSettlementResults->filter($notFree)->values();
        }
        $openMonthlyInvoiceMap = $this->openInvoiceByStudentClassIds($dateResults->pluck('ID')->unique()->values()->all());
        $pendingReportMap = self::latestPendingReportByStudentClassIds($allClassIds);
        $newerCourseMap = self::newerCourseByStudentClassIds($allClassIds);
        $subjectNameMap = $this->subjectNameMapForCourses($countResults->merge($dateResults)->merge($pendingSettlementResults));

        // ── Monthly billing package members: used to filter out member courses from individual alerts ──
        $monthlyPkgMemberIds = collect();
        $monthlyPkgAlerts = collect();

        $monthlyPkgQuery = CoursePackage::query()
            ->where('billing_mode', CoursePackage::BILLING_MODE_MONTHLY)
            ->where('stop', false);
        if ($studentIds !== null) {
            $monthlyPkgQuery->whereIn('student_id', $studentIds);
        }
        $monthlyPkgs = $monthlyPkgQuery->with('student')->get();

        if ($monthlyPkgs->isNotEmpty()) {
            $allMemberScIds = StudentClass::whereIn('PackageID', $monthlyPkgs->pluck('id'))
                ->pluck('ID');
            $monthlyPkgMemberIds = $allMemberScIds;

            // Count attended sessions this month (start of month to today) per member
            $startOfMonth = $today->copy()->startOfMonth()->toDateString();
            $todayStr = $today->toDateString();
            $attendedStatuses = ['attended', 'completed', 'late'];

            $memberSessionCounts = DB::table('ClassSession')
                ->whereBetween('SessionDate', [$startOfMonth, $todayStr])
                ->whereIn('Status', $attendedStatuses)
                ->whereIn('StudentClassID', $allMemberScIds)
                ->select('StudentClassID', DB::raw('COUNT(*) as cnt'))
                ->groupBy('StudentClassID')
                ->pluck('cnt', 'StudentClassID')
                ->map(fn ($v) => (int) $v)
                ->toArray();

            $membersByPkg = StudentClass::whereIn('PackageID', $monthlyPkgs->pluck('id'))
                ->select('ID', 'PackageID')
                ->get()
                ->groupBy('PackageID');

            // #3985 (Founder 2026-10-29): the members' billing verdict, not the package flag or an open invoice alone, decides
            // whether the package row still owes (the window then follows its settlement day).
            $ownerOf = $monthlyPkgs->pluck('student_id', 'id'); // a course with a stale PackageID of another student never feeds the verdict
            $ownMemberIds = StudentClass::query()->whereIn('PackageID', $monthlyPkgs->pluck('id'))->get(['ID', 'PackageID', 'StudentID'])
                ->filter(fn ($m) => (int) $m->StudentID === (int) $ownerOf[(int) $m->PackageID])->pluck('ID')->map(fn ($id) => (int) $id)->all();
            $memberVerdicts = $this->payableResolver->courseStatusesByStudentClassIds($ownMemberIds);

            foreach ($monthlyPkgs as $pkg) {
                $members = $membersByPkg->get($pkg->id, collect());
                $statuses = $members->filter(fn ($m) => isset($memberVerdicts[(int) $m->ID]))->map(fn ($m) => $memberVerdicts[(int) $m->ID]['status']);
                $pkgPaid = $statuses->contains(fn ($s) => in_array($s, ['unpaid', 'partial', 'unbilled'], true)) ? false
                    : ($statuses->isNotEmpty() && $statuses->every(fn ($s) => in_array($s, ['paid', 'free'], true)) ? true : (bool) $pkg->paid);
                $monthlySessions = $members->sum(fn ($m) => $memberSessionCounts[(int) $m->ID] ?? 0);
                $rate = (float) $pkg->rate;
                $charge = round($monthlySessions * $rate, 2);

                $alertRow = $this->mapMonthlyAlert(
                    $this->makeFakeSCForPackage($pkg, $pkgPaid),
                    $today
                );
                if ($alertRow === null) {
                    continue;
                }

                $monthlyPkgAlerts->push(array_merge($alertRow, [
                    'id'               => null,
                    'package_id'       => $pkg->id,
                    'student_id'       => (int) $pkg->student_id,
                    'student_name'     => $pkg->student->name ?? 'Unknown',
                    'campus_id'        => (int) ($pkg->student->CampusID ?? 0),
                    'subject'          => $pkg->name,
                    'schedule_mode'    => 'date',
                    'monthly_sessions' => $monthlySessions,
                    'rate'             => round($rate, 2),
                    'charge'           => $charge,
                    'estimated_amount' => $charge,
                    'charge_semantics' => 'estimate_only',
                    'payable_amount'   => null,
                    'payable_outstanding' => null,
                    'payable_status'    => 'unbilled',
                    'payable_source'    => null,
                    'payable_invoice_id' => null,
                    'billing_period'    => null,
                    'outstanding'      => $pkgPaid ? 0 : $charge,
                    'paid'             => $pkgPaid,
                    'paid_at'          => $pkg->paid_at ? substr($pkg->paid_at, 0, 10) : null,
                    'last_paid_at'     => $pkg->paid_at ? substr($pkg->paid_at, 0, 10) : null,
                    'paid_amount'      => $pkgPaid ? (int) round($charge) : 0,
                    'payment_status'   => $pkgPaid ? 'monthly_due_soon' : 'unpaid',
                    'latest_payment_report_id' => null,
                    'has_newer_course'         => false,
                    'newer_course_id'          => null,
                    'newer_course_remaining'   => null,
                    'newer_course_start_date'  => null,
                ]));
            }
        }

        $countPkgAlerts = $countPkgs->map(function (CoursePackage $pkg) use (
            $countPkgAnchorById,
            $countPkgMembersByPkgId,
            $paidAtMap,
            $invoiceAggMap,
            $pendingReportMap,
            $payableMap,
            $courseStatuses
        ) {
            $anchor = $countPkgAnchorById->get($pkg->id);
            if (!$anchor) {
                return null;
            }
            $anchorId = (int) $anchor->ID;
            $charge = $this->packageCountModeCharge($pkg);
            $invoiceAgg = $invoiceAggMap[$anchorId] ?? null;
            $payable = $payableMap[$anchorId] ?? $this->payableResolver->unbilled();
            $paidAmount = $invoiceAgg ? (int) $invoiceAgg['paid_amount'] : 0;
            $isPaid = $this->isFullyPaid((bool) $pkg->paid, $paidAmount, $charge);

            $pkgMembers = $countPkgMembersByPkgId->get($anchor->PackageID, collect());
            $pkgMemberIds = $pkgMembers->pluck('ID')->map(fn ($id) => (int) $id)->all();
            $pendingReportId = null;
            foreach ($pkgMemberIds as $mid) {
                if (isset($pendingReportMap[$mid])) {
                    $pendingReportId = $pendingReportMap[$mid];
                    break;
                }
            }

            $remaining = max(0, (int) ($pkg->remaining_sessions ?? 0));

            return [
                'id'                 => $anchorId,
                'package_id'         => (int) $pkg->id,
                'member_class_ids'   => $pkgMemberIds,
                'student_id'         => (int) $pkg->student_id,
                'student_name'       => $pkg->student->name ?? 'Unknown',
                'campus_id'          => (int) ($pkg->student->CampusID ?? $pkg->campus_id ?? 0),
                'subject'            => $pkg->name,
                'schedule_mode'      => 'count',
                'remaining_sessions' => $remaining,
                'sessions_purchased' => (int) ($pkg->total_sessions ?? 0),
                'paid'               => $isPaid,
                'alert_type'         => $isPaid ? 'low_sessions' : 'unpaid',
                'days_until_settlement' => null,
                'settlement_day'     => null,
                'due_date'           => null,
                'paid_at'            => $pkg->paid_at ? substr((string) $pkg->paid_at, 0, 10) : null,
                'last_paid_at'       => ($pkg->paid_at ? substr((string) $pkg->paid_at, 0, 10) : null) ?? ($paidAtMap[$anchorId] ?? null),
                'charge'             => $charge,
                'estimated_amount'   => $charge,
                'charge_semantics'   => 'estimate_only',
                'payable_amount'     => $payable['payable_amount'],
                'payable_outstanding' => $payable['payable_outstanding'],
                'payable_status'     => $payable['payable_status'],
                'payable_source'     => $payable['payable_source'],
                'payable_invoice_id' => $payable['payable_invoice_id'],
                'billing_period'    => $payable['payable_billing_period'],
                'paid_amount'        => $paidAmount,
                // F7 S3b: one outstanding answer = the resolver's course-level value; no invoice => estimate.
                'outstanding'        => $this->courseOutstanding($courseStatuses[$anchorId] ?? null) ?? ($isPaid ? 0 : max(0, $charge - $paidAmount)),
                'payment_status'     => $this->computePackageCountPaymentStatus($pkg, $paidAmount, $charge, $pendingReportId !== null),
                'latest_payment_report_id' => $pendingReportId,
                'has_newer_course'         => false,
                'newer_course_id'          => null,
                'newer_course_remaining'   => null,
                'newer_course_start_date'  => null,
            ];
        })->filter()->values();

        $rows = collect()
            ->merge(
                $countResults
                    ->filter(fn ($c) => !$countPkgMemberIds->contains($c->ID))
                    ->map(fn ($c) => $this->mapCountModeAlert($c, $subjectNameMap, $courseStatuses[(int) $c->ID] ?? null))->filter()
            )
            ->merge(
                // Exclude monthly-package members from individual date-mode alerts
                $dateResults->filter(fn ($c) => !$monthlyPkgMemberIds->contains($c->ID))
                    ->map(fn ($c) => $this->mapMonthlyAlert($c, $today, $openMonthlyInvoiceMap[(int) $c->ID] ?? null, $subjectNameMap))->filter()
            )
            ->merge(
                $pendingSettlementResults->map(fn ($c) => $this->mapPendingSettlementAlert($c, $subjectNameMap))
            )
            ->map(function ($row) use ($paidAtMap, $paymentHistoryMap, $allResults, $invoiceAggMap, $pendingReportMap, $newerCourseMap, $today, $openMonthlyInvoiceMap, $payableMap, $stoppedStatuses, $courseStatuses) {
                $classId = (int) $row['id'];
                $sc = $allResults->get($classId);
                $directPaidAt = ($sc && $sc->PayDate) ? substr($sc->PayDate, 0, 10) : null;
                $invoicePaidAt = $paidAtMap[$classId] ?? null;

                $openInvoice = $openMonthlyInvoiceMap[$classId] ?? null;
                $invoiceProjection = $openInvoice && isset($openInvoice['invoice'])
                    ? $this->invoiceAmounts->resolve($openInvoice['invoice'], $sc)
                    : null;
                $charge = $sc && ($sc->ScheduleMode ?? 'count') === 'date'
                    ? ($invoiceProjection
                        ? (int) $invoiceProjection['total_amount']
                        : (int) $this->monthlyBilling->summarize($sc, $today)['charge'])
                    : $this->countModeCharge($sc);
                $invoiceAgg = $invoiceAggMap[$classId] ?? null;
                $payable = $payableMap[$classId] ?? $this->payableResolver->unbilled();
                // ARCH2: what is paid / what status = the Verdict (invoices + Payment rows), not the stored PaidAmount sum.
                $verdict = $courseStatuses[$classId] ?? null;
                $paidAmount = ($verdict['source'] ?? null) === 'invoice'
                    ? (int) $verdict['applied']
                    : ($invoiceAgg ? (int) $invoiceAgg['paid_amount'] : 0);
                $rawPaid = (int) ($sc->Paid ?? 0) === 1;
                $scIsPaid = $this->isFullyPaid($rawPaid || ($sc ? $sc->isEffectivelyPaid() : false), $paidAmount, $charge);
                // F7 S3b: one outstanding answer = the resolver's course-level value; no invoice => estimate.
                $outstanding = $this->courseOutstanding($courseStatuses[$classId] ?? null) ?? ($scIsPaid ? 0 : max(0, $charge - $paidAmount));

                $pendingReportId = $pendingReportMap[$classId] ?? null;

                $paymentStatus = ContractMoneyState::alertStatus($sc, $paidAmount, $charge, $pendingReportId !== null, $verdict['status'] ?? null);
                $amountPendingConfirmation = false;
                if ($row['alert_type'] === 'pending_reconciliation') {
                    // F7 S3a: stopped + still owing per the invoices. Every amount the UI reads comes from the
                    // resolver's cumulative totals (never the single-invoice payable or the contract price).
                    $stopped = $stoppedStatuses[$classId];
                    $charge = (int) $stopped['payable_total'];
                    $paidAmount = (int) $stopped['applied'];
                    $outstanding = (int) $stopped['outstanding'];
                    $payable = array_merge($payable, [
                        'payable_amount' => $charge,
                        'payable_outstanding' => $outstanding,
                        'payable_invoice_id' => $stopped['current_invoice_id'],
                    ]);
                    $paymentStatus = $pendingReportId !== null ? 'pending_report' : 'pending_reconciliation';
                    // Founder D2 (in-app #394): a review_required bill stays findable, but its amount is still to be
                    // confirmed. Its own status keeps it out of every confirmed-unpaid count and total. The amount is
                    // the resolver's `outstanding`, the same field 已結清 → 待核對 (AccountingController::settledCourses) shows.
                    if ($sc instanceof StudentClass && BillingPayableResolver::isAmountPendingConfirmation($sc, $stopped)) {
                        $amountPendingConfirmation = true;
                        // A pending parent report stays confirmable (確認入帳 / 待核對入帳); the amount is still unconfirmed.
                        $paymentStatus = $pendingReportId !== null ? 'pending_report' : 'amount_review';
                    }
                }

                $newerInfo = $newerCourseMap[$classId] ?? null;

                // #1100 (FD-3): leave-cascade extension of this (A) course's EndDate must
                // never silently shift a pre-purchased (B) course's StartDate — surface an
                // explicit, director-facing overlap flag instead. Read-only: no date is
                // changed here, and no session/billing row is touched.
                $currentEndDate = $sc && $sc->EndDate ? substr((string) $sc->EndDate, 0, 10) : null;
                $newerCourseOverlap = $newerInfo
                    && !empty($newerInfo['start_date'])
                    && $currentEndDate !== null
                    && $currentEndDate >= $newerInfo['start_date'];

                return $row + [
                    'closed_reason'            => $sc?->getAttribute('closed_reason'),
                    'paid_at'                  => $directPaidAt,
                    // #4123: only a receipt still standing on the net Payment ledger gives 最近繳費. The legacy PayDate
                    // is a fallback only for contracts with no Payment history at all (any row, void/reversed included).
                    'last_paid_at'             => $invoicePaidAt ?? (isset($paymentHistoryMap[$classId]) ? null : $directPaidAt),
                    'charge'                   => $charge,
                    'estimated_amount'         => $charge,
                    'charge_semantics'         => 'estimate_only',
                    'payable_amount'           => $payable['payable_amount'],
                    'payable_outstanding'      => $payable['payable_outstanding'],
                    'payable_status'           => $payable['payable_status'],
                    'payable_source'           => $payable['payable_source'],
                    'payable_invoice_id'       => $payable['payable_invoice_id'],
                    'billing_period'           => $payable['payable_billing_period'],
                    'payable_amount_source'    => $payable['payable_amount_source'],
                    'paid_amount'              => $paidAmount,
                    'outstanding'              => $outstanding,
                    // Every period that still owes (not just the one bill `payable_*` describes): the student list sums these.
                    'open_periods'             => ($courseStatuses[$classId]['source'] ?? null) === 'invoice'
                        ? collect($courseStatuses[$classId]['periods'])
                            ->filter(fn ($p) => ($p['invoice_ids'] ?? []) !== [] && (int) ($p['outstanding'] ?? 0) > 0)
                            ->map(fn ($p) => ['billing_period' => $p['billing_period'], 'outstanding' => (int) $p['outstanding'], 'due_date' => $p['due_date'] ?? null])
                            ->values()->all()
                        : [],
                    'payment_status'           => $paymentStatus,
                    'amount_pending_confirmation' => $amountPendingConfirmation,
                    'pending_confirmation_amount' => $amountPendingConfirmation ? $outstanding : 0,
                    'latest_payment_report_id' => $pendingReportId,
                    'has_newer_course'         => $newerInfo !== null,
                    'newer_course_id'          => $newerInfo['newer_id'] ?? null,
                    'newer_course_remaining'   => $newerInfo['remaining'] ?? null,
                    'newer_course_start_date'  => $newerInfo['start_date'] ?? null,
                    'current_course_end_date'  => $currentEndDate,
                    'newer_course_overlap'     => $newerCourseOverlap,
                    'invoice_stored_amount'    => $invoiceProjection['stored_total_amount'] ?? null,
                    'invoice_computed_amount'  => $invoiceProjection['computed_total_amount'] ?? null,
                    'invoice_amount_source'    => $invoiceProjection['amount_source'] ?? null,
                    'invoice_amount_discrepancy' => $invoiceProjection['amount_discrepancy'] ?? false,
                    'invoice_period_sessions' => $invoiceProjection['period_sessions'] ?? null,
                    'course_start_date'         => $sc?->StartDate ? substr((string) $sc->StartDate, 0, 10) : null,
                    'course_end_date'           => $sc?->EndDate ? substr((string) $sc->EndDate, 0, 10) : null,
                ];
            })
            ->filter(fn ($row) => ($row['charge'] ?? 0) > 0)
            ->values();

        $rows = $this->suppressRenewedLowSessionAlerts($rows);

        // Merge package-level alerts after individual rows are normalized.
        $rows = $rows->merge($countPkgAlerts)->merge($monthlyPkgAlerts)->values();

        // #3942: the 收據 entry opens a confirmed report. A confirmed partial payment keeps the bill `partial` but already has
        // a receipt, so the row carries the confirmed report of ITS CURRENT BILL, never an older term's receipt. A package row
        // reads its anchor's bill, the same bill its amounts come from (member-wide package money + receipts: follow-up).
        // A report filed without InvoiceID but linked to a Payment on the bill (receipt_without_invoice) counts for that bill.
        $billOf = DB::raw('COALESCE(pr.InvoiceID, p.InvoiceID)');
        $receiptByInvoice = DB::table('payment_reports as pr')->leftJoin('Payment as p', 'p.id', '=', 'pr.payment_id')->where('pr.status', 'confirmed')
            ->whereIn($billOf, $rows->pluck('payable_invoice_id')->filter()->all())
            ->groupBy($billOf)->selectRaw('COALESCE(pr.InvoiceID, p.InvoiceID) AS bill_id, MAX(pr.id) AS report_id')->pluck('report_id', 'bill_id');
        $rows = $rows->map(function ($row) use ($receiptByInvoice) {
            $reportId = $receiptByInvoice[$row['payable_invoice_id'] ?? 0] ?? null;

            return $row + ['has_confirmed_receipt' => $reportId !== null, 'receipt_report_id' => $reportId !== null ? (int) $reportId : null];
        })->values();

        return response()->json($this->markLegacyPaidFlagOnly($rows, $campusIds === [] ? null : array_map('intval', $campusIds)));
    }

    /**
     * #4114 (Founder Q3): a paid status (paid / renew_needed / monthly_due_soon) with no receipt standing on the contract or
     * its package rests on the legacy Paid / package `paid` flag alone. `payment_status` is unchanged, so the row is still
     * not owed and sends no reminder; `legacy_paid_flag_only` makes the list read 需核對 instead of 已收. Only a row whose
     * legacy flag is actually set qualifies (StudentClass.Paid=1 / its package paid, or the package row's own `paid`); package
     * evidence is read inside the caller's campuses only (#4174 review).
     *
     * @param int[]|null $campusIds null = super admin (every campus)
     */
    private function markLegacyPaidFlagOnly(\Illuminate\Support\Collection $rows, ?array $campusIds): \Illuminate\Support\Collection
    {
        $paidLike = fn (array $row) => in_array($row['payment_status'] ?? null, ['paid', 'renew_needed', 'monthly_due_soon'], true);
        $candidates = $rows->filter($paidLike);
        $isPackageRow = fn (array $row) => isset($row['package_id']);
        $packageFlag = CoursePackage::query()->whereIn('id', $candidates->filter($isPackageRow)->pluck('package_id')->map(fn ($id) => (int) $id)->all())
            // in-app #428: a trial package (create-multi-subject) that carries paid_at records its fee by that date alone.
            ->get(['id', 'paid', 'class_type', 'paid_at'])
            ->mapWithKeys(fn (CoursePackage $p) => [(int) $p->id => (bool) $p->paid && !$p->isTrialFeeRecordedByDate()])->all();
        $classFlag = StudentClass::query()->with(['coursePackage', 'student'])
            ->whereIn('ID', $candidates->reject($isPackageRow)->pluck('id')->filter()->map(fn ($id) => (int) $id)->all())->get()
            // in-app #428: a trial that carries its payment date (no invoice by design) has no legacy flag to verify.
            ->mapWithKeys(fn (StudentClass $c) => [(int) $c->ID => $c->isEffectivelyPaid() && !$c->isTrialFeeRecordedByDate()])->all();
        $hasFlag = fn (array $row) => $isPackageRow($row)
            ? ($packageFlag[(int) $row['package_id']] ?? false)
            : ($classFlag[(int) ($row['id'] ?? 0)] ?? false);
        $flagged = $candidates->filter($hasFlag);
        // A package row asks about its package (the resolver reads only the owner's in-scope members), never about the raw
        // member_class_ids list, which is not campus-filtered.
        $evidence = $this->payableResolver->validPaymentEvidence(
            $flagged->reject($isPackageRow)->pluck('id')->filter()->all(),
            $flagged->filter($isPackageRow)->pluck('package_id')->filter()->all(),
            $campusIds,
        );

        return $rows->map(fn ($row) => $row + ['legacy_paid_flag_only' => $paidLike($row) && $hasFlag($row) && ($isPackageRow($row)
            ? !isset($evidence['packages'][(int) $row['package_id']])
            : !isset($evidence['classes'][(int) ($row['id'] ?? 0)]))])->values();
    }

    /** @param array<string, mixed>|null $verdict BillingPayableResolver course row; invoices that still owe beat the Paid flag (Founder Q2) */
    private function mapCountModeAlert(StudentClass $c, array $subjectNameMap = [], ?array $verdict = null): ?array
    {
        $remaining = max(0, (int) ($c->RemainingSessions ?? 0));
        $owesOnInvoices = ($verdict['source'] ?? null) === 'invoice' && in_array($verdict['status'] ?? null, ['unpaid', 'partial'], true);
        $isPaid = !$owesOnInvoices && ((int) ($c->Paid ?? 0) === 1 || $c->isEffectivelyPaid());
        $isUnpaid = !$isPaid;
        $isLowSessions = $remaining <= 2;

        if (!$isUnpaid && !$isLowSessions) {
            return null;
        }

        return [
            'id'                 => $c->ID,
            'student_id'         => (int) $c->StudentID,
            'student_name'       => $c->student->name ?? 'Unknown',
            'campus_id'          => (int) ($c->student->CampusID ?? 0),
            'subject'            => $this->subjectLabel($c, $subjectNameMap),
            'schedule_mode'      => 'count',
            'remaining_sessions' => $remaining,
            'sessions_purchased' => (int) ($c->SessionCount ?? 0),
            'paid'               => $isPaid,
            'alert_type'         => $isUnpaid ? 'unpaid' : 'low_sessions',
            'days_until_settlement' => null,
            'settlement_day'     => $c->settlement_day !== null ? (int) $c->settlement_day : null,
            'due_date'           => null,
        ];
    }

    private function mapPendingSettlementAlert(StudentClass $c, array $subjectNameMap = []): array
    {
        return [
            'id' => $c->getAttribute('ID'),
            'student_id' => (int) $c->getAttribute('StudentID'),
            'student_name' => $c->student->name ?? 'Unknown',
            'campus_id' => (int) ($c->student->CampusID ?? 0),
            'subject' => $this->subjectLabel($c, $subjectNameMap),
            'schedule_mode' => (string) (($c->ScheduleMode ?? 'count') === 'date' ? 'date' : 'count'),
            'remaining_sessions' => max(0, (int) ($c->RemainingSessions ?? 0)),
            'sessions_purchased' => (int) ($c->SessionCount ?? 0),
            'paid' => false,
            'alert_type' => 'pending_reconciliation',
            'days_until_settlement' => null,
            'settlement_day' => $c->getAttribute('settlement_day') ? (int) $c->getAttribute('settlement_day') : null,
            'due_date' => null,
        ];
    }

    private function packageCountModeCharge(CoursePackage $pkg): int
    {
        $sessions = max(0, (int) ($pkg->total_sessions ?? 0));
        $rate = (float) ($pkg->rate ?? 0);
        return (int) round($sessions * $rate);
    }

    /**
     * Founder Q3 (2026-10-11): a count-mode contract with no invoice owes StudentClass.Charge on every screen, the amount
     * ContractMoneyVerdict reports for it (this used to be Rate x sessions, #230). Where the two differ the director
     * confirms the contract. Two shapes keep the Rate x sessions figure, as an ESTIMATE only: a package member (its package
     * owns the amount; unchanged) and Charge = 0 beside a rate ("not filled in", the Verdict reads review_required), which
     * would otherwise drop out of the queue. The tuition slip never prices that second shape (see tuitionSlipData).
     */
    private function countModeCharge(StudentClass $course): int
    {
        $charge = max(0, (int) ($course->Charge ?? 0));
        $rate = (float) ($course->Rate ?? 0);
        $sessions = max(0, (int) ($course->SessionCount ?? 0));
        if (($charge > 0 && !$course->isPartOfPackage()) || $rate <= 0 || $sessions <= 0) {
            return $charge;
        }

        $rateUnit = strtolower(trim((string) ($course->rate_unit ?? 'session')));
        if ($rateUnit === 'hour') {
            $hours = (int) ($course->TotalHours ?? 0);
            if ($hours <= 0) {
                $duration = max(30, (int) ($course->SessionDuration ?? 120));
                $hours = (int) round(($sessions * $duration) / 60);
            }
            return max(0, (int) round($rate * $hours));
        }

        return max(0, (int) round($rate * $sessions));
    }

    private function computePackageCountPaymentStatus(CoursePackage $pkg, int $paidAmount, int $charge, bool $hasPendingReport): string
    {
        if ($hasPendingReport) {
            return 'pending_report';
        }
        $isPaid = $this->isFullyPaid((bool) $pkg->paid, $paidAmount, $charge);
        if (!$isPaid && $paidAmount > 0 && $charge > 0 && $paidAmount < $charge) {
            return 'partial';
        }
        if (!$isPaid) {
            return 'unpaid';
        }
        return (int) ($pkg->remaining_sessions ?? 0) <= 2 ? 'renew_needed' : 'paid';
    }

    private function mapMonthlyAlert(StudentClass $c, Carbon $today, ?array $openInvoice = null, array $subjectNameMap = []): ?array
    {
        $settlementDay = (int) ($c->settlement_day ?? 0);
        if ($settlementDay < 1 || $settlementDay > 31) {
            return null;
        }

        if ($openInvoice !== null && !empty($openInvoice['due_date'])) {
            $invoiceDue = Carbon::parse($openInvoice['due_date'])->startOfDay();
            $daysUntilDue = (int) $today->copy()->startOfDay()->diffInDays($invoiceDue, false);
            if ($daysUntilDue > 5) {
                return null;
            }

            return $this->monthlyAlertRow($c, $invoiceDue, $daysUntilDue, $openInvoice, $subjectNameMap);
        }

        $isPaid = (int) ($c->Paid ?? 0) === 1 || $c->isEffectivelyPaid();
        $thisDue = $this->settlementDateInMonth((int) $today->year, (int) $today->month, $settlementDay);

        if (!$isPaid) {
            if ($today->lte($thisDue)) {
                $daysLeft = (int) $today->copy()->startOfDay()->diffInDays($thisDue->copy()->startOfDay(), false);
                if ($daysLeft > 5) {
                    return null;
                }

                return $this->monthlyAlertRow($c, $thisDue, $daysLeft, null, $subjectNameMap);
            }
            $daysLate = (int) $thisDue->copy()->startOfDay()->diffInDays($today->copy()->startOfDay(), false);

            return $this->monthlyAlertRow($c, $thisDue, -$daysLate, null, $subjectNameMap);
        }

        // 已繳費：若尚未過「本月」繳費日，下一個截止日仍為本月；否則為下月同日（遇短月則取月底）
        if ($today->lte($thisDue)) {
            $daysLeft = (int) $today->copy()->startOfDay()->diffInDays($thisDue->copy()->startOfDay(), false);
            if ($daysLeft > 5) {
                return null;
            }

            return $this->monthlyAlertRow($c, $thisDue, $daysLeft, null, $subjectNameMap);
        }

        $nextMonth = $today->copy()->startOfMonth()->addMonthNoOverflow();
        $nextDue = $this->settlementDateInMonth((int) $nextMonth->year, (int) $nextMonth->month, $settlementDay);
        $daysLeft = (int) $today->copy()->startOfDay()->diffInDays($nextDue->copy()->startOfDay(), false);
        if ($daysLeft > 5) {
            return null;
        }

        return $this->monthlyAlertRow($c, $nextDue, $daysLeft, null, $subjectNameMap);
    }

    private function monthlyAlertRow(StudentClass $c, Carbon $dueDate, int $daysUntilSettlement, ?array $invoice = null, array $subjectNameMap = []): array
    {
        $isPaid = (int) ($c->Paid ?? 0) === 1 || $c->isEffectivelyPaid();

        $row = [
            'id'                 => $c->ID,
            'student_id'         => (int) $c->StudentID,
            'student_name'       => $c->student->name ?? 'Unknown',
            'campus_id'          => (int) ($c->student->CampusID ?? 0),
            'subject'            => $this->subjectLabel($c, $subjectNameMap),
            'schedule_mode'      => 'date',
            'remaining_sessions' => max(0, (int) ($c->RemainingSessions ?? 0)),
            'sessions_purchased' => (int) ($c->SessionCount ?? 0),
            'paid'               => $isPaid,
            'alert_type'         => 'monthly_due_soon',
            'days_until_settlement' => $daysUntilSettlement,
            'settlement_day'     => (int) ($c->settlement_day ?? 0),
            'due_date'           => $dueDate->toDateString(),
        ];

        if ($invoice !== null) {
            $row['invoice_id'] = (int) $invoice['id'];
            $row['billing_period'] = $invoice['billing_period'] ?? null;
            $row['invoice_status'] = $invoice['status'] ?? null;
        }

        return $row;
    }

    private function settlementDateInMonth(int $year, int $month, int $settlementDay): Carbon
    {
        $dim = Carbon::createFromDate($year, $month, 1)->daysInMonth;
        $d = max(1, min($settlementDay, $dim));

        return Carbon::createFromDate($year, $month, $d)->startOfDay();
    }

    private function openInvoiceByStudentClassIds(array $studentClassIds): array
    {
        $ids = array_values(array_unique(array_map('intval', $studentClassIds)));
        if (empty($ids)) {
            return [];
        }

        $rows = Invoice::with(['items', 'payments' => function ($query) {
                $query->select(['id', 'InvoiceID', 'Amount', 'Method']);
            }])
            ->whereIn('StudentClassID', $ids)
            ->whereIn('Status', ['unpaid', 'partial'])
            ->orderBy('DueDate')
            ->orderBy('id')
            ->get(['id', 'StudentClassID', 'DueDate', 'billing_period', 'IssueDate', 'TotalAmount', 'Status']);

        $map = [];
        foreach ($rows as $invoice) {
            $classId = (int) $invoice->StudentClassID;
            if (isset($map[$classId])) {
                continue;
            }
            $map[$classId] = [
                'id' => (int) $invoice->id,
                'due_date' => $invoice->DueDate ? substr((string) $invoice->DueDate, 0, 10) : null,
                'billing_period' => $invoice->billing_period,
                'status' => $invoice->Status,
                'invoice' => $invoice,
            ];
        }

        return $map;
    }

    /**
     * Build a minimal fake StudentClass object to drive mapMonthlyAlert for a CoursePackage.
     * Only settlement_day, Paid, ID, and student (for campus_id) are needed by mapMonthlyAlert.
     */
    private function makeFakeSCForPackage(\App\Models\CoursePackage $pkg, ?bool $paid = null): StudentClass
    {
        $sc = new StudentClass();
        $sc->ID = null;
        $sc->StudentID = $pkg->student_id;
        $sc->settlement_day = $pkg->settlement_day;
        $sc->Paid = ($paid ?? (bool) $pkg->paid) ? 1 : 0;
        $sc->Charge = 0;
        $sc->ScheduleMode = 'date';
        $sc->student = $pkg->student;

        return $sc;
    }

    /**
     * GET /api/v1/alerts/tuition-slip/{studentClassId}
     * Returns slip-ready DTO from StudentClass (no Invoice required).
     * Only allowed for Paid != 1 (unpaid).
     */
    public function tuitionSlipData(Request $request, int $studentClassId)
    {
        // Authorize first: a missing record, a record without a student and another campus's record all answer the same
        // bare 403, before any money is read (no status, amount or name can leak through a different error).
        $role = $request->attributes->get('auth_role');
        $campusIds = $role === 'super_admin' ? null : array_map('intval', (array) $request->attributes->get('auth_campus_ids', []));
        $sc = StudentClass::with(['student', 'coursePackage'])->find($studentClassId);
        $student = $sc?->student;
        if (!$sc instanceof StudentClass || !$student || ($campusIds !== null && !in_array((int) $student->CampusID, $campusIds, true))) {
            abort(403);
        }

        // ARCH2: a slip exists only while the Verdict says the contract owes. Paid=1 beside an unpaid invoice still gets
        // its slip (the money is owed); Payment rows that settle a Paid=0 contract refuse it. No Verdict answer
        // (review_required) keeps the legacy flag test.
        $verdict = $this->payableResolver->courseStatusesByStudentClassIds([$studentClassId], [$sc])[$studentClassId] ?? [];
        $settlement = $verdict['status'] ?? null;
        // Founder D2 (#4121 review): an issued bill whose amount is still to be confirmed never reaches a parent.
        if (BillingPayableResolver::isAmountPendingConfirmation($sc, $verdict)) {
            return response()->json(['message' => '金額待確認，請先確認應收金額'], 422);
        }
        if ($settlement === 'free') {
            return response()->json(['message' => '這門課是免費課程，不需要繳費單'], 422);
        }
        // Founder 2026-10-14: a count-mode contract whose Charge is empty ("not filled in") is never priced from Rate on a slip
        // a parent can download; the queue row keeps its estimate until a director fills in the Charge.
        if ($settlement === 'review_required' && ($sc->ScheduleMode ?? 'count') === 'count' && !$sc->isPartOfPackage()
            && (int) ($sc->getAttribute('Charge') ?? 0) <= 0) {
            return response()->json(['message' => '金額待確認，請先確認應收金額'], 422);
        }
        $settled = in_array($settlement, ['unpaid', 'partial', 'unbilled'], true)
            ? false
            : ($settlement === 'paid' || (int) ($sc->getAttribute('Paid') ?? 0) === 1 || $sc->isEffectivelyPaid());
        if ($settled) {
            return response()->json(['message' => '此課程已繳費，不需產生繳費單'], 422);
        }

        $campus = Campus::find((int) $student->CampusID);
        $subject = $this->subjectLabel($sc);
        $remaining = max(0, (int) ($sc->RemainingSessions ?? 0));
        $mode = $sc->ScheduleMode ?? 'count';
        $today = Carbon::today();
        $studentClassId = (int) $sc->getAttribute('ID');
        // The slip asks for the bill the queue shows (monthly = oldest owing period), so its period,
        // lessons and deadline come from that same bill, not the newest stored-open one.
        $payable = $this->payableResolver->byStudentClassIds([$studentClassId], [$sc])[$studentClassId]
            ?? $this->payableResolver->unbilled();
        // in-app #432 (#4573 review): a settled bill does not hold the slip while a later month is still unbilled.
        if ($mode === 'date' && (int) ($payable['payable_outstanding'] ?? 0) <= 0 && $this->monthlyBilling->unbilledPeriodFor($sc) !== null) {
            $payable = $this->payableResolver->unbilled();
        }
        $payablePeriod = (string) ($payable['payable_billing_period'] ?? '');
        // The payable bill itself (its item service range drives the lesson list); else the newest open one.
        $payableInvoice = $mode === 'date' && (int) ($payable['payable_invoice_id'] ?? 0) > 0
            ? Invoice::query()->where('StudentClassID', $studentClassId)->whereKey((int) $payable['payable_invoice_id'])->first()
            : null;
        $openInvoice = $mode === 'date'
            ? ($payableInvoice ?? Invoice::query()
                ->where('StudentClassID', $studentClassId)
                ->whereIn('Status', ['unpaid', 'partial'])
                ->orderByDesc('billing_period')
                ->orderByDesc('id')
                ->first())
            : null;
        $openBillingPeriod = preg_match('/^\d{4}-\d{2}$/', $payablePeriod)
            ? $payablePeriod
            : ($openInvoice ? $openInvoice->getAttribute('billing_period') : null);
        $billingPeriod = $mode === 'date'
            ? (preg_match('/^\d{4}-\d{2}$/', (string) ($openBillingPeriod ?? ''))
                ? (string) $openBillingPeriod
                : $this->monthlyBilling->collectionPeriodFor($sc, $today)) // in-app #432: oldest unbilled month first
            : null;
        $billing = $mode === 'date'
            ? $this->monthlyBilling->summarizePeriod($sc, $billingPeriod)
            : [
                // Same amount as the queue row and the Verdict (Founder Q3): the stored Charge.
                'charge' => $this->countModeCharge($sc),
                'period_sessions' => null,
                'period_start' => null,
                'period_end' => null,
                'source' => 'stored_charge_count_mode',
            ];
        $charge = (int) $billing['charge'];

        $dueDate = null;
        $daysUntilSettlement = null;

        $storedDue = $payableInvoice?->getAttribute('DueDate');
        if ($mode === 'date' && $storedDue) {
            // The payable bill's own deadline wins over today's settlement_day (Codex P1 r4254523790).
            $thisDue = Carbon::parse($storedDue)->startOfDay();
            $dueDate = $thisDue->toDateString();
            $daysUntilSettlement = $today->lte($thisDue) ? (int) $today->diffInDays($thisDue, false) : -(int) $thisDue->diffInDays($today, false);
        } elseif ($mode === 'date') {
            $sd = (int) ($sc->settlement_day ?? 0);
            if ($sd >= 1 && $sd <= 31) {
                // An older owing period is due on its own month's settlement day.
                $dueMonth = $billingPeriod !== null && $billingPeriod < $today->format('Y-m')
                    ? Carbon::createFromFormat('Y-m-d', $billingPeriod . '-01')
                    : $today;
                $thisDue = $this->settlementDateInMonth((int) $dueMonth->year, (int) $dueMonth->month, $sd);
                if ($today->lte($thisDue)) {
                    $dueDate = $thisDue->toDateString();
                    $daysUntilSettlement = (int) $today->diffInDays($thisDue, false);
                } else {
                    $dueDate = $thisDue->toDateString();
                    $daysUntilSettlement = -(int) $thisDue->diffInDays($today, false);
                }
            }
        }

        $authUser = $request->attributes->get('auth_user');
        Log::info('[TuitionSlip] generated', [
            'user_id' => $authUser?->id,
            'student_class_id' => $sc->ID,
            'student_id' => $student->id,
            'campus_id' => $student->CampusID,
        ]);

        return response()->json([
            'student_class_id' => $sc->ID,
            'student_name'     => $student->name ?? '—',
            'campus_name'      => $campus->name ?? '',
            'subject'          => $subject,
            'schedule_mode'    => $mode,
            'remaining_sessions' => $remaining,
            'period_sessions' => $billing['period_sessions'],
            'billing_period_start' => $billing['period_start'],
            'billing_period_end' => $billing['period_end'],
            'charge'           => $charge,
            'estimated_amount' => $charge,
            'charge_semantics' => 'estimate_only',
            'payable_amount'   => $payable['payable_amount'],
            'payable_outstanding' => $payable['payable_outstanding'],
            'payable_status'   => $payable['payable_status'],
            'payable_source'   => $payable['payable_source'],
            'payable_invoice_id' => $payable['payable_invoice_id'],
            'payable_billing_period' => $payable['payable_billing_period'],
            'billing_period'   => $billingPeriod,
            'due_date'         => $dueDate,
            'days_until_settlement' => $daysUntilSettlement,
            'note'             => $sc->Memo ?? '',
            'sessions' => $mode === 'date'
                ? $this->monthlyBilling->slipSessionDetailsForPeriod(
                    $sc,
                    $billingPeriod,
                    ...($openInvoice
                        ? $this->monthlyBilling->serviceRangeForCourse($openInvoice, $studentClassId)
                        : [null, null]),
                )
                : ClassSession::sessionsForPaymentSlip(
                    [$studentClassId],
                    $billing['period_start'],
                    $billing['period_end']
                ),
        ] + FeeCharge::slipBlock((int) $student->id, (int) $student->CampusID, $billingPeriod)); // #439: beside tuition, never in it
    }

    /**
     * Suppress low_sessions (續課提醒) for courses where the same student already
     * has a newer active course with the same subject and sufficient remaining
     * sessions — indicating a renewal has been created.
     */
    private function suppressRenewedLowSessionAlerts($rows)
    {
        $lowSessionIds = $rows->filter(fn ($r) => ($r['alert_type'] ?? '') === 'low_sessions')->pluck('id')->all();
        if (empty($lowSessionIds)) {
            return $rows;
        }

        $lowCourses = StudentClass::whereIn('ID', $lowSessionIds)->get(['ID', 'StudentID', 'SubjectID']);
        $renewedIds = [];

        foreach ($lowCourses as $lc) {
            $hasRenewal = StudentClass::where('StudentID', $lc->StudentID)
                ->where('SubjectID', $lc->SubjectID)
                ->where('ID', '!=', $lc->ID)
                ->where('Stop', 0)
                ->where('RemainingSessions', '>', 2)
                ->exists();
            if ($hasRenewal) {
                $renewedIds[$lc->ID] = true;
            }
        }

        if (empty($renewedIds)) {
            return $rows;
        }

        return $rows->filter(fn ($r) => !isset($renewedIds[$r['id']]))->values();
    }

    /**
     * @param  array<int, string>|null  $subjectNameMap  SubjectID => name, pre-batched by
     *                                                    subjectNameMapForCourses() to avoid
     *                                                    StudentClass::displaySubjectName()'s
     *                                                    per-row Subject/BaseData fallback
     *                                                    query (#984). Pass null (the single-
     *                                                    course call sites' default) to allow
     *                                                    the one-off per-row query; pass a map
     *                                                    (even an empty one, from the bulk
     *                                                    tuition() path) to force '課程' instead
     *                                                    of re-querying for an id the batch
     *                                                    lookup already tried and found nothing.
     */
    private function subjectLabel(StudentClass $c, ?array $subjectNameMap = null): string
    {
        $subject = $c->getAttribute('Subject');
        if ($subject !== null && $subject !== '') {
            return (string) $subject;
        }
        $id = (int) ($c->SubjectID ?? 0);
        if ($id <= 0) {
            return '課程';
        }
        if ($subjectNameMap === null) {
            return $c->displaySubjectName();
        }

        return $subjectNameMap[$id] ?? '課程';
    }

    /**
     * Batch-resolve subject display names for a set of courses in at most two
     * queries total, instead of StudentClass::displaySubjectName()'s per-row
     * Subject/BaseData fallback (#984 — was ~2 extra queries per unpaid course
     * whenever the StudentClass.Subject string column was empty).
     *
     * @param  \Illuminate\Support\Collection  $courses  Collection of StudentClass
     * @return array<int, string>  SubjectID => name
     */
    private function subjectNameMapForCourses(\Illuminate\Support\Collection $courses): array
    {
        $ids = $courses
            ->filter(fn ($c) => empty($c->getAttribute('Subject')))
            ->pluck('SubjectID')
            ->filter(fn ($id) => (int) $id > 0)
            ->unique()
            ->values()
            ->all();
        if (empty($ids)) {
            return [];
        }

        $fromSubject = DB::table('Subject')->whereIn('id', $ids)->pluck('Subject_Name', 'id')->all();
        $missing = array_diff($ids, array_keys($fromSubject));
        $fromBase = empty($missing)
            ? []
            : DB::table('BaseData')->where('Name', '課程')->whereIn('id', $missing)->pluck('Val', 'id')->all();

        return $fromSubject + $fromBase;
    }

    /** Course-level outstanding from the resolver; null (caller keeps its estimate) unless it is derived from invoices. */
    private function courseOutstanding(?array $status): ?int
    {
        return ($status['source'] ?? null) === 'invoice' ? (int) $status['outstanding'] : null;
    }

    /**
     * Thin delegate to StudentClass::isFullyPaid — single display-path truth (TD-083 B0).
     * Kept private on the controller so existing call sites stay local; do not
     * reimplement the predicate here.
     */
    private function isFullyPaid(bool $rawPaidFlag, int $paidAmount, int $charge): bool
    {
        return StudentClass::isFullyPaid($rawPaidFlag, $paidAmount, $charge);
    }

    /**
     * Batch-fetch the latest pending PaymentReport ID per StudentClass ID.
     *
     * @param  int[]  $studentClassIds
     * @return array<int, int>  keyed by StudentClassID => latest pending report id
     */
    public static function latestPendingReportByStudentClassIds(array $studentClassIds): array
    {
        if (empty($studentClassIds)) {
            return [];
        }

        $rows = DB::table('payment_reports')
            ->whereIn('StudentClassID', $studentClassIds)
            ->where('status', 'pending')
            ->select('StudentClassID', DB::raw('MAX(id) as latest_id'))
            ->groupBy('StudentClassID')
            ->get();

        $map = [];
        foreach ($rows as $row) {
            $map[(int) $row->StudentClassID] = (int) $row->latest_id;
        }

        return $map;
    }

    /**
     * Batch-detect newer same-subject courses for the given StudentClass IDs.
     * For each ID, finds another active (Stop=0) StudentClass with the same
     * StudentID + SubjectID but a different ID.
     *
     * @param  int[]  $studentClassIds
     * @return array<int, array{newer_id: int, remaining: int, start_date: string|null}>
     */
    public static function newerCourseByStudentClassIds(array $studentClassIds): array
    {
        if (empty($studentClassIds)) {
            return [];
        }

        $courses = StudentClass::whereIn('ID', $studentClassIds)->get(['ID', 'StudentID', 'SubjectID']);
        if ($courses->isEmpty()) {
            return [];
        }

        $pairs = $courses->map(fn ($c) => ['id' => $c->ID, 'sid' => $c->StudentID, 'sub' => $c->SubjectID]);

        $studentSubjectPairs = $pairs->map(fn ($p) => $p['sid'] . '-' . $p['sub'])->unique()->values();

        $candidates = StudentClass::where('Stop', 0)
            ->whereIn('StudentID', $pairs->pluck('sid')->unique())
            ->whereNotIn('ID', $studentClassIds)
            ->get(['ID', 'StudentID', 'SubjectID', 'RemainingSessions', 'StartDate']);

        $candidateIndex = [];
        foreach ($candidates as $c) {
            $key = $c->StudentID . '-' . $c->SubjectID;
            if (!isset($candidateIndex[$key]) || $c->ID > $candidateIndex[$key]->ID) {
                $candidateIndex[$key] = $c;
            }
        }

        $map = [];
        foreach ($pairs as $p) {
            $key = $p['sid'] . '-' . $p['sub'];
            if (isset($candidateIndex[$key])) {
                $newer = $candidateIndex[$key];
                $map[$p['id']] = [
                    'newer_id'   => (int) $newer->ID,
                    'remaining'  => (int) ($newer->RemainingSessions ?? 0),
                    'start_date' => $newer->StartDate ? substr($newer->StartDate, 0, 10) : null,
                ];
            }
        }

        return $map;
    }
}
