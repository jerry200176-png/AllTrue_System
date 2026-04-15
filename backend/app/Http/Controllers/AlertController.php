<?php

namespace App\Http\Controllers;

use App\Models\Campus;
use App\Models\ClassSession;
use App\Models\Student;
use App\Models\StudentClass;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class AlertController extends Controller
{
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

        if ($request->filled('branch_id')) {
            $bid = (int) $request->input('branch_id');
            if ($role !== 'super_admin' && !empty($campusIds) && !in_array($bid, $campusIds, true)) {
                return response()->json(['message' => 'Forbidden'], 403);
            }
            $campusIds = [$bid];
        }

        $studentIds = empty($campusIds) ? null : Student::whereIn('CampusID', $campusIds)->pluck('id');

        $today = Carbon::today();

        $countQuery = StudentClass::query()
            ->where('Stop', 0)
            ->where('ScheduleMode', 'count')
            ->where(function ($q) {
                $q->where('Paid', 0)
                  ->orWhereNull('Paid')
                  ->orWhere('RemainingSessions', '<=', 2);
            });
        if ($studentIds !== null) {
            $countQuery->whereIn('StudentID', $studentIds);
        }

        $dateQuery = StudentClass::query()
            ->where('Stop', 0)
            ->where('ScheduleMode', 'date')
            ->whereNotNull('settlement_day')
            ->whereBetween('settlement_day', [1, 31]);
        if ($studentIds !== null) {
            $dateQuery->whereIn('StudentID', $studentIds);
        }

        $countResults = $countQuery->with('student')->get();
        $dateResults  = $dateQuery->with('student')->get();

        $allClassIds = $countResults->pluck('ID')->merge($dateResults->pluck('ID'))->unique()->values()->all();
        $paidAtMap = self::lastPaidAtByStudentClassIds($allClassIds);

        $rows = collect()
            ->merge(
                $countResults->map(fn ($c) => $this->mapCountModeAlert($c))->filter()
            )
            ->merge(
                $dateResults->map(fn ($c) => $this->mapMonthlyAlert($c, $today))->filter()
            )
            ->map(function ($row) use ($paidAtMap, $countResults, $dateResults) {
                $allResults = $countResults->merge($dateResults);
                $sc = $allResults->first(fn ($c) => (int) $c->ID === (int) $row['id']);
                $directPaidAt = ($sc && $sc->PayDate) ? substr($sc->PayDate, 0, 10) : null;
                $invoicePaidAt = $paidAtMap[(int) $row['id']] ?? null;
                return $row + [
                    'paid_at'      => $directPaidAt,
                    'last_paid_at' => $directPaidAt ?? $invoicePaidAt,
                ];
            })
            ->values();

        return response()->json($rows);
    }

    private function mapCountModeAlert(StudentClass $c): ?array
    {
        $remaining = max(0, (int) ($c->RemainingSessions ?? 0));
        $isPaid = (int) ($c->Paid ?? 0) === 1;
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
            'subject'            => $this->subjectLabel($c),
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

    private function mapMonthlyAlert(StudentClass $c, Carbon $today): ?array
    {
        $settlementDay = (int) ($c->settlement_day ?? 0);
        if ($settlementDay < 1 || $settlementDay > 31) {
            return null;
        }

        $isPaid = (int) ($c->Paid ?? 0) === 1;
        $thisDue = $this->settlementDateInMonth((int) $today->year, (int) $today->month, $settlementDay);

        if (!$isPaid) {
            if ($today->lte($thisDue)) {
                $daysLeft = (int) $today->copy()->startOfDay()->diffInDays($thisDue->copy()->startOfDay(), false);
                if ($daysLeft > 5) {
                    return null;
                }

                return $this->monthlyAlertRow($c, $thisDue, $daysLeft);
            }
            $daysLate = (int) $thisDue->copy()->startOfDay()->diffInDays($today->copy()->startOfDay(), false);

            return $this->monthlyAlertRow($c, $thisDue, -$daysLate);
        }

        // 已繳費：若尚未過「本月」繳費日，下一個截止日仍為本月；否則為下月同日（遇短月則取月底）
        if ($today->lte($thisDue)) {
            $daysLeft = (int) $today->copy()->startOfDay()->diffInDays($thisDue->copy()->startOfDay(), false);
            if ($daysLeft > 5) {
                return null;
            }

            return $this->monthlyAlertRow($c, $thisDue, $daysLeft);
        }

        $nextMonth = $today->copy()->startOfMonth()->addMonthNoOverflow();
        $nextDue = $this->settlementDateInMonth((int) $nextMonth->year, (int) $nextMonth->month, $settlementDay);
        $daysLeft = (int) $today->copy()->startOfDay()->diffInDays($nextDue->copy()->startOfDay(), false);
        if ($daysLeft > 5) {
            return null;
        }

        return $this->monthlyAlertRow($c, $nextDue, $daysLeft);
    }

    private function monthlyAlertRow(StudentClass $c, Carbon $dueDate, int $daysUntilSettlement): array
    {
        $isPaid = (int) ($c->Paid ?? 0) === 1;

        return [
            'id'                 => $c->ID,
            'student_id'         => (int) $c->StudentID,
            'student_name'       => $c->student->name ?? 'Unknown',
            'campus_id'          => (int) ($c->student->CampusID ?? 0),
            'subject'            => $this->subjectLabel($c),
            'schedule_mode'      => 'date',
            'remaining_sessions' => max(0, (int) ($c->RemainingSessions ?? 0)),
            'sessions_purchased' => (int) ($c->SessionCount ?? 0),
            'paid'               => $isPaid,
            'alert_type'         => 'monthly_due_soon',
            'days_until_settlement' => $daysUntilSettlement,
            'settlement_day'     => (int) ($c->settlement_day ?? 0),
            'due_date'           => $dueDate->toDateString(),
        ];
    }

    private function settlementDateInMonth(int $year, int $month, int $settlementDay): Carbon
    {
        $dim = Carbon::createFromDate($year, $month, 1)->daysInMonth;
        $d = max(1, min($settlementDay, $dim));

        return Carbon::createFromDate($year, $month, $d)->startOfDay();
    }

    /**
     * GET /api/v1/alerts/tuition-slip/{studentClassId}
     * Returns slip-ready DTO from StudentClass (no Invoice required).
     * Only allowed for Paid != 1 (unpaid).
     */
    public function tuitionSlipData(Request $request, int $studentClassId)
    {
        $sc = StudentClass::with('student')->findOrFail($studentClassId);

        if ((int) ($sc->Paid ?? 0) === 1) {
            return response()->json(['message' => '此課程已繳費，不需產生繳費單'], 422);
        }

        $student = $sc->student;
        if (!$student) {
            return response()->json(['message' => '找不到學生資料'], 404);
        }

        $role = $request->attributes->get('auth_role');
        $campusIds = $role === 'super_admin' ? [] : array_map('intval', (array) $request->attributes->get('auth_campus_ids', []));
        if (!empty($campusIds) && !in_array((int) $student->CampusID, $campusIds, true)) {
            abort(403);
        }

        $campus = Campus::find((int) $student->CampusID);
        $subject = $this->subjectLabel($sc);
        $remaining = max(0, (int) ($sc->RemainingSessions ?? 0));
        $charge = (int) ($sc->Charge ?? 0);
        $mode = $sc->ScheduleMode ?? 'count';

        $today = Carbon::today();
        $dueDate = null;
        $daysUntilSettlement = null;

        if ($mode === 'date') {
            $sd = (int) ($sc->settlement_day ?? 0);
            if ($sd >= 1 && $sd <= 31) {
                $thisDue = $this->settlementDateInMonth((int) $today->year, (int) $today->month, $sd);
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
            'charge'           => $charge,
            'due_date'         => $dueDate,
            'days_until_settlement' => $daysUntilSettlement,
            'note'             => $sc->Memo ?? '',
            'sessions' => ClassSession::sessionsForPaymentSlip([(int) $sc->ID]),
        ]);
    }

    private function subjectLabel(StudentClass $c): string
    {
        return $c->displaySubjectName();
    }

    /**
     * Batch-fetch the latest Payment.PaidAt per StudentClass ID.
     * Uses Invoice.StudentClassID -> Payment.InvoiceID chain.
     *
     * @param  int[]  $studentClassIds
     * @return array<int, string|null>  keyed by StudentClass ID, value is date string or null
     */
    public static function lastPaidAtByStudentClassIds(array $studentClassIds): array
    {
        if (empty($studentClassIds)) {
            return [];
        }

        $rows = DB::table('Invoice')
            ->join('Payment', 'Payment.InvoiceID', '=', 'Invoice.id')
            ->whereIn('Invoice.StudentClassID', $studentClassIds)
            ->select('Invoice.StudentClassID', DB::raw('MAX(Payment.PaidAt) as last_paid_at'))
            ->groupBy('Invoice.StudentClassID')
            ->get();

        $map = [];
        foreach ($rows as $row) {
            $date = $row->last_paid_at;
            if ($date) {
                $map[(int) $row->StudentClassID] = substr($date, 0, 10);
            }
        }

        return $map;
    }
}
