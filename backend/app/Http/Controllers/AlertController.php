<?php

namespace App\Http\Controllers;

use App\Models\Student;
use App\Models\StudentClass;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class AlertController extends Controller
{
    /**
     * GET /api/v1/alerts/tuition
     * 主任儀表板「繳費提醒」資料源。規則見 docs/DIRECTOR_PAYMENT_ALERT_RULES.md
     *
     * 堂數制 (ScheduleMode=count)：未繳費，或剩餘堂數 <= 2（含 0 堂）。
     * 月結制 (ScheduleMode=date)：有設定 settlement_day，且 (a) 未繳費且已過本月繳費日 → 一律提醒；
     * (b) 未繳費且尚未到繳費日 → 距繳費日 < 5 天提醒；(c) 已繳費 → 僅在「下一個」繳費日前 < 5 天提醒。
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

        $rows = collect()
            ->merge(
                $countQuery->with('student')->get()->map(fn ($c) => $this->mapCountModeAlert($c))->filter()
            )
            ->merge(
                $dateQuery->with('student')->get()->map(fn ($c) => $this->mapMonthlyAlert($c, $today))->filter()
            )
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
                if ($daysLeft >= 5) {
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
            if ($daysLeft >= 5) {
                return null;
            }

            return $this->monthlyAlertRow($c, $thisDue, $daysLeft);
        }

        $nextMonth = $today->copy()->startOfMonth()->addMonthNoOverflow();
        $nextDue = $this->settlementDateInMonth((int) $nextMonth->year, (int) $nextMonth->month, $settlementDay);
        $daysLeft = (int) $today->copy()->startOfDay()->diffInDays($nextDue->copy()->startOfDay(), false);
        if ($daysLeft >= 5) {
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

    private function subjectLabel(StudentClass $c): string
    {
        $subject = $c->getAttribute('Subject');
        if ($subject !== null && $subject !== '') {
            return (string) $subject;
        }
        $id = (int) ($c->SubjectID ?? 0);
        if ($id <= 0) {
            return '課程';
        }

        return (string) (DB::table('Subject')->where('id', $id)->value('Subject_Name')
            ?? DB::table('BaseData')->where('Name', '課程')->where('id', $id)->value('Val')
            ?? '課程');
    }
}
