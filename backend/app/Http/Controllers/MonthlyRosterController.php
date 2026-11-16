<?php

namespace App\Http\Controllers;

use App\Models\ClassSession;
use App\Models\Student;
use App\Models\StudentClass;
use App\Services\Billing\ContractMoneyVerdict;
use App\Support\AttendanceStatus;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * 月對帳表 (Founder 2026-11-17): one campus, one month, one row per student per course — the month's lessons, what the
 * course still owes and what came in this month. It follows how directors reconciled in their old monthly sheet.
 * Read-only. Money comes from ContractMoneyVerdict, the verdict the student ledger shows, so the two always agree.
 */
final class MonthlyRosterController extends Controller
{
    public function __construct(private readonly ContractMoneyVerdict $verdicts)
    {
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

        $lessons = ClassSession::query()
            ->whereIn('StudentClassID', StudentClass::query()->select('ID')->whereIn('StudentID', $studentIds))
            ->whereBetween('SessionDate', [$start, $end])
            ->whereNotIn('Status', ['cancelled', 'voided']) // a cancelled lesson was taken off the calendar; 請假 stays
            ->orderBy('SessionDate')->orderBy('StartTime')
            ->get(['StudentClassID', 'SessionDate', 'StartTime', 'Status'])
            ->groupBy('StudentClassID');
        // Courses with a lesson this month, plus courses with this month's bill or any bill still unpaid.
        // ponytail: a bill with no course on its header is left to the student ledger.
        $billed = DB::table('Invoice')->whereIn('StudentID', $studentIds)->where('StudentClassID', '>', 0)
            ->whereRaw("COALESCE(Status, '') NOT IN ('void', 'cancelled')")
            ->where(fn ($q) => $q->where('billing_period', $period)->orWhereRaw('TotalAmount > PaidAmount'))
            ->pluck('StudentClassID');
        $courses = StudentClass::with(['student', 'teacher'])->whereIn('StudentID', $studentIds)
            ->whereIn('ID', $lessons->keys()->merge($billed)->map(fn ($id) => (int) $id)->unique()->values())
            ->get();
        $verdicts = $this->verdicts->forCourses($courses);

        $courseIds = $courses->pluck('ID');
        $paid = DB::table('Payment')->join('Invoice', 'Invoice.id', '=', 'Payment.InvoiceID')
            ->whereIn('Invoice.StudentClassID', $courseIds)->whereBetween('Payment.PaidAt', [$start . ' 00:00:00', $end . ' 23:59:59'])
            ->orderBy('Payment.PaidAt')
            ->get(['Invoice.StudentClassID', 'Payment.Amount', 'Payment.PaidAt', 'Payment.Method'])
            ->groupBy('StudentClassID');
        $pending = DB::table('payment_reports')->whereIn('StudentClassID', $courseIds)->where('status', 'pending')
            ->selectRaw('StudentClassID, COUNT(*) AS n')->groupBy('StudentClassID')->pluck('n', 'StudentClassID');

        $held = array_flip(AttendanceStatus::payableSessionStatuses());
        $rows = $courses->map(function (StudentClass $c) use ($lessons, $verdicts, $paid, $pending, $held, $period) {
            $id = (int) $c->ID;
            $v = $verdicts[$id] ?? null;
            $month = $v ? collect($v->periods)->firstWhere('billing_period', $period) : null;
            $rate = (float) ($c->Rate ?? 0) > 0 ? (float) $c->Rate
                : ((int) ($c->SessionCount ?? 0) > 0 ? (float) ($c->Charge ?? 0) / (int) $c->SessionCount : 0.0);
            $courseLessons = ($lessons[$id] ?? collect())->map(fn ($l) => [
                'date' => (string) $l->SessionDate, 'start' => substr((string) $l->StartTime, 0, 5),
                'kind' => isset($held[$l->Status]) ? 'held' : (in_array($l->Status, ['scheduled', 'rescheduled'], true) ? 'upcoming' : 'off'),
                'status' => (string) $l->Status,
            ])->values();
            $payments = ($paid[$id] ?? collect())->map(fn ($p) => [
                'date' => substr((string) $p->PaidAt, 0, 10), 'amount' => (int) $p->Amount, 'method' => (string) $p->Method,
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
                'rate' => (int) round($rate),
                'stopped' => (bool) $c->Stop,
                'lessons' => $courseLessons,
                'held' => $courseLessons->where('kind', 'held')->count(),
                'money' => [
                    'status' => $v?->status, 'outstanding' => $v?->outstanding ?? 0, 'current_period' => $v?->currentPeriod,
                    // This month's amount for a monthly course; not invoiced yet = the month-end estimate.
                    'month_total' => $month['total'] ?? null, 'month_invoiced' => !empty($month['invoice_ids'] ?? []),
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
