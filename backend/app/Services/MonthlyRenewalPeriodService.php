<?php

namespace App\Services;

use App\Models\ClassSession;
use App\Models\Invoice;
use App\Models\InvoiceItem;
use App\Models\SecurityAuditEvent;
use App\Models\StudentClass;
use App\Models\StudentClassPricingAmendment;
use App\Services\Scheduling\ContractSessionSchedule;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;

/** Shared renewal dates and historical-session safety; does not move or price sessions. */
final class MonthlyRenewalPeriodService
{
    public function inspect(StudentClass $course, ?string $end): array
    {
        $oldEnd = $course->getAttribute('EndDate') ? Carbon::parse($course->getAttribute('EndDate'))->toDateString() : null;
        $start = $oldEnd ? Carbon::parse($oldEnd)->addDay() : Carbon::today();
        $month = $start->copy()->startOfMonth();
        $day = max(1, min(31, (int) ($course->getAttribute('settlement_day') ?? 15)));
        $rows = ClassSession::query()->where('StudentClassID', $course->getKey())->whereIn('Status', ['attended', 'completed', 'late'])
            ->where(function ($query) use ($course, $oldEnd) {
                if ($oldEnd) $query->whereDate('SessionDate', '>', $oldEnd);
                if ($course->getAttribute('StartDate')) $query->orWhereDate('SessionDate', '<', Carbon::parse($course->getAttribute('StartDate'))->toDateString());
                if (!$oldEnd && !$course->getAttribute('StartDate')) $query->whereRaw('1 = 0');
            })->get(['id', 'SessionDate', 'StartTime'])->map(fn ($r) => ['id' => (int) $r->id, 'date' => Carbon::parse($r->SessionDate)->toDateString(), 'start' => substr((string) $r->StartTime, 0, 5)]);
        // A month already billed by a catch-up (same student + subject, non-void invoice for that billing_period)
        // is not a blocker: the lessons stay on this contract but their money lives on the catch-up invoice.
        $months = $rows->map(fn ($r) => substr($r['date'], 0, 7))->unique()->values()->all();
        $billedMonths = $months === [] ? collect() : DB::table('Invoice as inv')
            ->join('StudentClass as sc', 'sc.ID', '=', 'inv.StudentClassID')
            ->where('sc.StudentID', $course->getAttribute('StudentID'))->where('sc.SubjectID', $course->getAttribute('SubjectID'))
            ->whereIn('inv.billing_period', $months)
            ->where(fn ($q) => $q->whereNull('inv.Status')->orWhere('inv.Status', '<>', 'void'))
            ->pluck('inv.billing_period')->unique();
        $open = $rows->reject(fn ($r) => $billedMonths->contains(substr($r['date'], 0, 7)));
        // in-app #506: a lesson the old month wrongly holds past its end, but inside the new period, moves to the new contract.
        $inNew = $open->filter(fn ($r) => $oldEnd && $end && $r['date'] > $oldEnd && $r['date'] <= $end);
        $reason = $inNew->isEmpty() ? null : $this->adoptionBlocker($course, $inNew, $oldEnd, $end);
        $blockers = [];
        if ($reason === null) $open = $open->reject(fn ($r) => $inNew->contains('id', $r['id']));
        if ($open->count()) $blockers[] = ['code' => 'monthly_completed_sessions_outside_contract',
            'message' => "有 {$open->count()} 堂上課日在合約日期之外，先處理這幾堂才能開下一期。"];
        if ($reason && str_starts_with($reason, 'adopt_')) $blockers[] = ['code' => $reason, 'message' => '合約之外的上課要併入新合約，但舊合約不是單價乘堂數的單一未付帳單，請先請會計處理。'];
        return ['start_date' => $start->toDateString(), 'billing_period' => $end ? $start->format('Y-m') : null,
            'due_date' => $end ? $month->day(min($day, $month->daysInMonth))->toDateString() : null,
            'blockers' => $blockers, 'adoptable' => $reason === null ? $inNew->pluck('id')->values()->all() : []];
    }

    /** Null when the unpaid source can give lessons away; 'paid' (no extra blocker) or an adopt_* code otherwise. */
    private function adoptionBlocker(StudentClass $c, $rows, string $oldEnd, string $end): ?string
    {
        $n = $rows->count();
        $invoices = Invoice::query()->notVoided()->where('StudentClassID', $c->getKey())->get();
        $inv = $invoices->first();
        if ((int) ($c->Paid ?? 0) !== 0 || (float) ($c->Pay ?? 0) > 0 || $invoices->contains(fn ($i) => (float) $i->PaidAmount > 0 || $i->payments()->exists())
            || DB::table('payment_reports')->where('StudentClassID', $c->getKey())->where('status', '<>', 'rejected')->exists()) return 'paid';
        if (StudentClassPricingAmendment::query()->where('student_class_id', $c->getKey())->whereNull('voided_at')->exists()) return 'adopt_source_discounted';
        if (strtolower(trim((string) ($c->rate_unit ?? 'session'))) !== 'session' || (float) $c->Rate <= 0) return 'adopt_requires_session_rate';
        if ((int) $c->Charge !== (int) round((float) $c->Rate * (int) $c->SessionCount)) return 'adopt_source_discounted';
        if ($invoices->count() !== 1 || $inv->Status === 'paid' || (int) $inv->TotalAmount !== (int) $c->Charge || InvoiceItem::where('InvoiceID', $inv->id)->count() !== 1) return 'adopt_source_invoice_ambiguous';
        // The new period only bills its weekly timetable, so an adopted lesson must sit on one of those slots.
        $slots = ContractSessionSchedule::buildSessionsFromWeeklySchedule((int) $c->getKey(), Carbon::parse($oldEnd)->addDay()->toDateString(), $end,
            ContractSessionSchedule::resolveScheduleSlotsForRebuild($c), max(30, (int) ($c->SessionDuration ?? 120)));
        $keys = array_map(fn ($x) => substr((string) $x['SessionDate'], 0, 10) . '|' . substr((string) $x['StartTime'], 0, 5), $slots);
        if ($rows->contains(fn ($r) => !in_array($r['date'] . '|' . $r['start'], $keys, true))) return 'adopt_off_timetable';
        $delta = (int) round((float) $c->Rate * $n);
        if ($n >= (int) $c->SessionCount || $delta >= (int) $c->Charge || $delta >= (int) $inv->TotalAmount || $delta >= (int) InvoiceItem::where('InvoiceID', $inv->id)->value('Amount')) return 'adopt_exceeds_source';
        return null;
    }

    /** Moves the adopted lessons (with their evidence) to the new course and takes their price off the source month. @return list<int> */
    public function adopt(StudentClass $source, StudentClass $target, array $ids, int $actorId, string $end): array
    {
        if ($ids === []) return [];
        $n = count($ids);
        $rows = ClassSession::whereIn('id', $ids)->get(['id', 'SessionDate', 'StartTime'])->map(fn ($r) => ['id' => (int) $r->id, 'date' => Carbon::parse($r->SessionDate)->toDateString(), 'start' => substr((string) $r->StartTime, 0, 5)]);
        $source = StudentClass::query()->where('ID', $source->getKey())->lockForUpdate()->firstOrFail();
        $invoice = Invoice::query()->notVoided()->where('StudentClassID', $source->getKey())->lockForUpdate()->firstOrFail();
        $item = InvoiceItem::where('InvoiceID', $invoice->id)->lockForUpdate()->get();
        $delta = (int) round((float) $source->Rate * $n);
        $oldCharge = (int) $source->Charge;
        if ($item->count() !== 1 || $this->adoptionBlocker($source, $rows, Carbon::parse($source->EndDate)->toDateString(), $end) !== null || $oldCharge - $delta <= 0 || (int) $source->SessionCount - $n <= 0
            || (int) $invoice->TotalAmount - $delta <= 0 || (int) $item[0]->Amount - $delta <= 0) throw new \RuntimeException('monthly_adopt_state_changed');
        foreach ($ids as $id) {
            ClassSession::where('id', $id)->update(['StudentClassID' => $target->getKey()]);
            app(SessionContractRecoveryService::class)->moveEvidence($id, (int) $target->getKey(), false);
            DB::table('session_deduction_ledger')->where('class_session_id', $id)->where('student_class_id', $source->getKey())->update(['student_class_id' => $target->getKey()]);
        }
        $source->forceFill(['Charge' => $oldCharge - $delta, 'SessionCount' => (int) $source->SessionCount - $n])->save();
        $invoice->update(['TotalAmount' => (int) $invoice->TotalAmount - $delta]);
        InvoiceItem::where('InvoiceID', $invoice->id)->decrement('Amount', $delta);
        SessionDeductionService::recomputeCounters((int) $source->getKey());
        SessionDeductionService::recomputeCounters((int) $target->getKey());
        SecurityAuditEvent::append('billing.monthly_renewal_adopt', 'success', ['actor_type' => 'user', 'actor_id' => $actorId, 'subject_type' => 'student_class',
            'subject_id' => $source->getKey()], ['reason_code' => 'outside_lesson_adopted', 'old_charge' => $oldCharge, 'new_charge' => $oldCharge - $delta, 'row_count' => $n], true);
        return $ids;
    }

    public static function chargeFromRate(float $rate, string $rateUnit, int $sessionCount, int $totalHours): int
    {
        if ($rate <= 0) {
            return 0;
        }
        return (int) round($rateUnit === 'hour' ? $rate * max(0, $totalHours) : $rate * max(0, $sessionCount));
    }

    public function findDuplicate(StudentClass $course, string $start, string $end): ?StudentClass
    {
        return StudentClass::query()->where('ID', '<>', $course->getKey())
            ->where('StudentID', $course->getAttribute('StudentID'))
            ->where('SubjectID', $course->getAttribute('SubjectID'))
            ->where('ScheduleMode', 'date')
            ->whereDate('StartDate', $start)
            ->whereDate('EndDate', $end)
            ->where(fn ($q) => $q->whereNull('Stop')->orWhere('Stop', 0))
            ->orderBy('ID')
            ->first();
    }

    /** Sessions, hours and charge BEFORE discount for one renewal period; shared by renewMonthly and the drafts preview. */
    public function previewPeriod(StudentClass $course, string $start, string $end): array
    {
        $rate = (float) ($course->getAttribute('Rate') ?? 0);
        $rateUnit = strtolower(trim((string) ($course->getAttribute('rate_unit') ?? 'session')));
        if (!in_array($rateUnit, ['session', 'hour'], true)) {
            $rateUnit = 'session';
        }
        $dur = max(30, (int) ($course->getAttribute('SessionDuration') ?? 120));
        $slots = ContractSessionSchedule::resolveScheduleSlotsForRebuild($course);
        $built = !empty($slots)
            ? ContractSessionSchedule::buildSessionsFromWeeklySchedule((int) $course->getKey(), $start, $end, $slots, $dur)
            : [];
        $count = count($built);
        if ($count <= 0) {
            $count = max(0, (int) ($course->getAttribute('monthly_sessions') ?? 0));
        }
        $hours = !empty($built)
            ? (int) round(array_reduce($built, function ($carry, $session) {
                $s = substr((string) ($session['StartTime'] ?? ''), 0, 5);
                $e = substr((string) ($session['EndTime'] ?? ''), 0, 5);
                if ($s === '' || $e === '') {
                    return $carry;
                }
                return $carry + max(0, ((int) substr($e, 0, 2)) * 60 + (int) substr($e, 3, 2) - ((int) substr($s, 0, 2)) * 60 - (int) substr($s, 3, 2));
            }, 0) / 60)
            : (int) round(($count * $dur) / 60);
        $charge = self::chargeFromRate($rate, $rateUnit, $count, $hours);
        if ($charge <= 0) {
            $charge = max(0, (int) ($course->getAttribute('Charge') ?? 0));
        }
        return ['rate' => $rate, 'rate_unit' => $rateUnit, 'session_duration' => $dur, 'sessions' => $count, 'hours' => $hours, 'charge' => $charge];
    }
}
