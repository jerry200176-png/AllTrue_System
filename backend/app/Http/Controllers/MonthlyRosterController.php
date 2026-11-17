<?php

namespace App\Http\Controllers;

use App\Models\ClassSession;
use App\Models\Invoice;
use App\Models\Payment;
use App\Models\Student;
use App\Models\StudentClass;
use App\Services\Billing\ContractMoneyState;
use App\Services\Billing\ContractMoneyVerdict;
use App\Services\ClassSessionIndexProjectionService;
use App\Services\ClassSessionIndexReadService;
use App\Services\MonthlyBillingService;
use App\Services\StudentClassPricingService;
use App\Support\AttendanceStatus;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * 月對帳表 (Founder 2026-11-17): one campus, one month, one row per student per course — the month's lessons, what the
 * course still owes and what came in this month. It follows how directors reconciled in their old monthly sheet.
 * Read-only. Each figure comes from the code the other screens use, so they always agree: money from
 * ContractMoneyVerdict (student ledger), lessons from the calendar's sign-in-aware status and its 預排 projection,
 * the unit price from StudentClassPricingService (payment slip).
 */
final class MonthlyRosterController extends Controller
{
    public function __construct(
        private readonly ContractMoneyVerdict $verdicts,
        private readonly ClassSessionIndexProjectionService $projection,
        private readonly StudentClassPricingService $pricing,
        private readonly MonthlyBillingService $billing,
    ) {
    }

    public function index(Request $request)
    {
        $input = $request->validate([
            'year' => ['required', 'integer', 'min:2020', 'max:2100'],
            'month' => ['required', 'integer', 'min:1', 'max:12'],
            'branch_id' => ['nullable', 'integer'],
        ]);
        $campusId = $this->campusId($request);
        if ($campusId === null) {
            return response()->json(['message' => '請先選分校'], 422);
        }
        if ($campusId === 0) {
            return response()->json(['message' => 'Forbidden'], 403);
        }

        $first = Carbon::createFromDate((int) $input['year'], (int) $input['month'], 1)->startOfDay();
        [$start, $end, $period] = [$first->toDateString(), $first->copy()->endOfMonth()->toDateString(), $first->format('Y-m')];
        $studentIds = Student::query()->where('CampusID', $campusId)->pluck('id');

        $campusCourses = StudentClass::query()->select('ID')->whereIn('StudentID', $studentIds);

        // Every row incl. cancelled ones (the projection must not re-plan a cancelled date); the status is the one the
        // attendance screen shows, so a lesson signed in but still stored as scheduled counts as held.
        $sessions = DB::table('ClassSession as cs')
            ->join('StudentClass as sc', 'sc.ID', '=', 'cs.StudentClassID')
            ->whereRaw(ClassSessionIndexReadService::HIDE_STOPPED_SCHEDULED_SQL) // as the attendance screen; history stays
            ->leftJoin('StudentSingIn as si', fn ($join) => $join->on('si.ClassSessionID', '=', 'cs.id')
                ->whereRaw(ClassSessionIndexReadService::LATEST_SIGN_IN_SQL))
            ->whereIn('cs.StudentClassID', $campusCourses)
            ->whereBetween('cs.SessionDate', [$start, $end])
            ->select(['cs.id', 'cs.StudentClassID', 'cs.SessionDate as session_date', 'cs.StartTime as start_time', 'cs.EndTime as end_time', 'cs.Status as status', 'cs.session_charge'])
            ->selectRaw(...ClassSessionIndexReadService::effectiveStatusSelect())
            ->get()->groupBy('StudentClassID');
        // 預排 not yet written as lessons, exactly as the calendar plans them (true = count contracts too).
        $activeIds = (clone $campusCourses)->where(fn ($q) => $q->where('Stop', 0)->orWhereNull('Stop'))->pluck('ID')->map(fn ($id) => (int) $id)->all();
        $projected = $this->projection->buildProjectedByClassForIndex(
            $request, $sessions->map(fn ($rows) => $rows->all())->all(), $start, $end, $activeIds, true);

        $held = array_flip(AttendanceStatus::attendedSessionStatuses());
        $lessons = collect(array_unique(array_merge($sessions->keys()->all(), array_keys($projected))))
            ->mapWithKeys(fn ($id) => [(int) $id => collect($sessions[$id] ?? [])
                ->reject(fn ($l) => in_array($l->status, ['cancelled', 'voided'], true)) // taken off the calendar; 請假 stays
                ->map(fn ($l) => ['date' => (string) $l->session_date, 'start' => substr((string) $l->start_time, 0, 5),
                    'kind' => isset($held[$l->effective_status]) ? 'held' : (in_array($l->effective_status, ['scheduled', 'rescheduled'], true) ? 'upcoming' : 'off'),
                    'status' => (string) $l->effective_status])
                ->concat(collect($projected[(string) $id] ?? [])->map(fn ($p) => ['date' => (string) $p['session_date'],
                    'start' => substr((string) $p['start_time'], 0, 5), 'kind' => 'upcoming', 'status' => 'projected']))
                ->sortBy(fn ($l) => $l['date'] . ' ' . $l['start'])->values()])
            ->filter(fn ($rows) => $rows->isNotEmpty());

        // Money in this month, whichever bill it paid (an October debt collected in November counts in November).
        // A receipt moved to another student or course (ReceiptReassignService) is bookkeeping, not money in or out:
        // its original payment and the reversal are skipped, the replacement (new course, original date) counts.
        $moved = DB::table('payment_report_reassignments')->select('from_report_id');
        $paid = DB::table('Payment')->join('Invoice', 'Invoice.id', '=', 'Payment.InvoiceID')
            ->whereIn('Invoice.StudentID', $studentIds)->where('Invoice.StudentClassID', '>', 0)
            ->whereNotIn('Payment.id', DB::table('payment_reports')->whereIn('id', $moved)->whereNotNull('payment_id')->select('payment_id'))
            ->where(fn ($q) => $q->whereNull('Payment.payment_report_id')->orWhereNotIn('Payment.payment_report_id', $moved))
            ->whereBetween('Payment.PaidAt', [$start . ' 00:00:00', $end . ' 23:59:59'])
            ->orderBy('Payment.PaidAt')
            ->get(['Invoice.StudentClassID', 'Payment.Amount', 'Payment.PaidAt', 'Payment.Method'])
            ->groupBy('StudentClassID');
        // Courses with a lesson this month, money this month, this month's bill or any bill still unpaid.
        // ponytail: a bill with no course on its header is left to the student ledger.
        $bills = DB::table('Invoice')->whereIn('StudentID', $studentIds)->where('StudentClassID', '>', 0)
            ->whereRaw("COALESCE(Status, '') NOT IN ('void', 'cancelled')");
        // A bill without a period belongs to its IssueDate month, as InvoiceAmountReconciliationService::resolve() reads it.
        $billed = (clone $bills)->where(fn ($q) => $q->where('billing_period', $period)
            ->orWhere(fn ($legacy) => $legacy->where(fn ($none) => $none->whereNull('billing_period')->orWhere('billing_period', ''))
                ->where('IssueDate', 'like', $period . '%')))
            ->pluck('StudentClassID');
        $listed = $lessons->keys()->merge($paid->keys())->merge($billed)->map(fn ($id) => (int) $id)->unique();
        // An older bill that may still owe is only a candidate: its stored Status can lag the Payment rows, which are the
        // truth (Founder Q2/R28), so such a course stays only when its verdict really has money outstanding.
        $owing = (clone $bills)->whereRaw(Invoice::mayOweSql('Invoice'))->pluck('StudentClassID')->map(fn ($id) => (int) $id);
        $courses = StudentClass::with(['student', 'teacher', 'pricingAmendments', 'coursePackage'])->whereIn('StudentID', $studentIds)
            ->whereIn('ID', $listed->merge($owing)->unique()->values())
            ->get();
        $verdicts = $this->verdicts->forCourses($courses);
        // 確認不收 (waived) keeps Charge with Paid=0 and void bills, so its verdict still reads as owed: nothing is collected (ledger: isWaivedContract).
        $owes = fn (StudentClass $c) => !ContractMoneyState::isWaived($c) && ($verdicts[(int) $c->ID]->outstanding ?? 0) > 0;
        $courses = $courses->filter(fn (StudentClass $c) => $listed->contains((int) $c->ID) || $owes($c))->values();

        $courseIds = $courses->pluck('ID');
        $pending = DB::table('payment_reports')->whereIn('StudentClassID', $courseIds)->where('status', 'pending')
            ->selectRaw('StudentClassID, COUNT(*) AS n')->groupBy('StudentClassID')->pluck('n', 'StudentClassID');

        $rows = $courses->map(function (StudentClass $c) use ($sessions, $lessons, $verdicts, $owes, $paid, $pending, $period, $start, $end) {
            $id = (int) $c->ID;
            $v = $verdicts[$id] ?? null;
            $month = $v ? collect($v->periods)->firstWhere('billing_period', $period) : null;
            // The payment slip's 單價: null when the price changed inside the month (no single figure is true).
            $price = $this->pricing->slipUnitPrice($c, $start, $end);
            $rate = $price['unit_price'];
            if ($rate === null && (float) ($c->Rate ?? 0) <= 0 && (int) ($c->SessionCount ?? 0) > 0
                && $this->pricing->forDate($c, $end)['source'] === 'student_class_rate') {
                $rate = (int) round((float) ($c->Charge ?? 0) / (int) $c->SessionCount); // a package priced by its total
            }
            $courseLessons = $lessons[$id] ?? collect();
            // 學收 (the old 當月學收 report's figure), priced by the bill engine; the rows carry the calendar's status.
            $tuition = $this->billing->monthTuition($c, ClassSession::hydrate(collect($sessions[$id] ?? [])
                ->map(fn ($l) => ['id' => $l->id, 'StudentClassID' => $l->StudentClassID, 'SessionDate' => $l->session_date, 'StartTime' => $l->start_time,
                    'EndTime' => $l->end_time, 'Status' => $l->effective_status, 'session_charge' => $l->session_charge])->values()->all()));
            // Credit moved between contracts is not money coming in; a reversal counts negative however it was stored.
            $payments = ($paid[$id] ?? collect())->reject(fn ($p) => Payment::isCreditMovement($p))->map(fn ($p) => [
                'date' => substr((string) $p->PaidAt, 0, 10),
                'amount' => Payment::isReversal($p) ? -abs((int) $p->Amount) : (int) $p->Amount,
                'method' => (string) $p->Method,
            ])->values();

            return [
                'student_id' => (int) $c->StudentID,
                'student_name' => (string) ($c->student->name ?? ''),
                'school' => (string) ($c->student->SchoolName ?? ''),
                'course_id' => $id,
                'subject' => $c->displaySubjectName(),
                'teacher_name' => (string) ($c->teacher->Name ?? ''),
                'class_type' => (string) ($c->ClassType ?? ''),
                'schedule_mode' => (string) ($c->ScheduleMode ?? 'count'),
                'rate' => $rate,
                'rate_unit' => $price['unit_price_unit'],
                'stopped' => (bool) $c->Stop,
                'lessons' => $courseLessons,
                'held' => $courseLessons->where('kind', 'held')->count(),
                'tuition_lessons' => $tuition['lessons'],
                'tuition' => $tuition['amount'],
                'money' => [
                    'status' => ContractMoneyState::isWaived($c) ? 'waived' : $v?->status, 'outstanding' => $owes($c) ? $v->outstanding : 0, 'current_period' => $v?->currentPeriod,
                    // This month's amount for a monthly course; not invoiced yet = the month-end estimate.
                    'month_total' => $month['total'] ?? null, 'month_invoiced' => !empty($month['invoice_ids'] ?? []),
                    // Old data: only the Paid mark says paid (no bill, no payment) — the student ledger shows 需核對; a trial fee recorded by date is the exception.
                    'legacy_unverified' => $v?->source === 'legacy_flag' && !$c->isTrialFeeRecordedByDate(),
                ],
                'paid_this_month' => $payments,
                'pending_reports' => (int) ($pending[$id] ?? 0),
            ];
        });

        $students = $rows->groupBy('student_id')->map(fn ($courseRows) => [
            'student_id' => $courseRows[0]['student_id'],
            'student_name' => $courseRows[0]['student_name'],
            'school' => $courseRows[0]['school'],
            'outstanding' => $courseRows->sum('money.outstanding'),
            'courses' => $courseRows->sortBy('subject')->values()->map(fn ($r) => array_diff_key($r, array_flip(['student_id', 'student_name', 'school']))),
        ])->sortBy('student_name')->values();

        return response()->json([
            'data' => $students,
            'summary' => [
                'period' => $period,
                'students' => $students->count(),
                'courses' => $rows->count(),
                'outstanding' => $rows->sum('money.outstanding'),
                'paid_this_month' => $rows->sum(fn ($r) => $r['paid_this_month']->sum('amount')),
                'tuition_total' => (int) $rows->sum('tuition'), // 學收合計
                'tuition_unpriced' => $rows->filter(fn ($r) => $r['tuition'] === null)->count(), // courses left out of it: no known price
                'pending_reports' => $rows->sum('pending_reports'),
            ],
        ]);
    }

    /** One campus: a director's own (or the one picked among theirs); a super admin must pick one. 0 = not allowed. */
    private function campusId(Request $request): ?int
    {
        $role = $request->attributes->get('auth_role');
        $own = array_map('intval', (array) $request->attributes->get('auth_campus_ids', []));
        $picked = $request->filled('branch_id') ? (int) $request->input('branch_id') : null;
        if ($role === 'super_admin') {
            return $picked;
        }
        if ($own === []) {
            return 0;
        }
        if ($picked !== null) {
            return in_array($picked, $own, true) ? $picked : 0;
        }

        return count($own) === 1 ? $own[0] : null;
    }
}
