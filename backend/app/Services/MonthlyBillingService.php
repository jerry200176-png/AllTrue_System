<?php

namespace App\Services;

use App\Models\ClassSession;
use App\Models\Invoice;
use App\Models\StudentClass;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;

/**
 * Read-only billing summary for legacy, individual monthly courses.
 *
 * Monthly course rows historically persisted the planned session count in
 * StudentClass.Charge. Once a director added or materialized another session,
 * that value could lag behind the billable sessions in ClassSession. This
 * service keeps the correction in one place without mutating billing data.
 */
class MonthlyBillingService
{
    /** @var list<string> */
    private const BILLABLE_STATUSES = ['attended', 'completed', 'late'];
    private const PLANNED_STATUSES = ['scheduled', 'rescheduled'];

    public function __construct(private StudentClassPricingService $pricing)
    {
    }

    /**
     * @return array{
     *   charge:int,
     *   period_sessions:int,
     *   period_start:string,
     *   period_end:string,
     *   source:string
     * }
     */
    public function summarize(Model $course, ?Carbon $anchor = null): array
    {
        $anchor = ($anchor ?? Carbon::today())->copy();
        return $this->summarizePeriod($course, $anchor->format('Y-m'));
    }

    /**
     * Calculate one explicit billing period. Invoice readers must use the
     * invoice period, not today's month, otherwise a historical invoice can
     * display a different month's session count.
     *
     * @return array{
     *   charge:int,
     *   period_sessions:int,
     *   period_start:string,
     *   period_end:string,
     *   source:string
     * }
     */
    public function summarizePeriod(Model $course, string $billingPeriod): array
    {
        try {
            $anchor = Carbon::createFromFormat('!Y-m', $billingPeriod);
        } catch (\Throwable) {
            $anchor = Carbon::today();
        }

        $periodStart = $anchor->copy()->startOfMonth()->toDateString();
        $periodEnd = $anchor->copy()->endOfMonth()->toDateString();
        $storedCharge = max(0, (int) ($course->getAttribute('Charge') ?? 0));

        // Package members are billed at the package level, not as individual
        // monthly courses. Keep their existing source of truth untouched.
        if ((int) ($course->getAttribute('PackageID') ?? 0) > 0) {
            return [
                'charge' => $storedCharge,
                'period_sessions' => 0,
                'period_start' => $periodStart,
                'period_end' => $periodEnd,
                'source' => 'stored_charge_package_member',
            ];
        }

        $sessions = $this->billableSessionsForPeriod($course, $billingPeriod);

        if ($sessions->isEmpty()) {
            $evidence = (string) $course->getAttribute('ScheduleMode') === 'date'
                ? $this->periodSessionQuery($course, $billingPeriod)->get(['Status']) : collect();
            $knownZero = $evidence->isNotEmpty() && $evidence->every(fn ($row) => in_array($row->Status, ['scheduled', 'cancelled', 'voided', 'leave', 'rescheduled'], true));
            return [
                'charge' => $knownZero ? 0 : $storedCharge,
                'period_sessions' => 0,
                'period_start' => $periodStart,
                'period_end' => $periodEnd,
                'source' => $knownZero ? 'billable_sessions' : 'stored_charge_no_billable_sessions',
            ];
        }

        $pricingRows = $sessions->mapWithKeys(function (ClassSession $session) use ($course): array {
            return [$session->getKey() => $this->pricing->forDate($course, (string) $session->SessionDate)];
        });
        if ($pricingRows->every(fn (array $pricing): bool => $pricing['rate'] <= 0)) {
            return [
                'charge' => $storedCharge,
                'period_sessions' => $sessions->count(),
                'period_start' => $periodStart,
                'period_end' => $periodEnd,
                'source' => 'stored_charge_missing_rate',
            ];
        }

        $rateUnits = $pricingRows->pluck('rate_unit')->unique()->values();
        $rateUnit = $rateUnits->count() === 1 ? (string) $rateUnits->first() : 'session';

        if ($rateUnit === 'session') {
            $charge = (int) $sessions->sum(function (ClassSession $session) use ($pricingRows): int {
                return (int) $pricingRows->get($session->getKey())['rate'];
            });
        } else {
            $charge = (int) round($sessions->sum(function (ClassSession $session) use ($pricingRows): float {
                $pricing = $pricingRows->get($session->getKey());
                return $this->sessionAmount($session, $pricing);
            }));
        }

        return [
            'charge' => max(0, $charge),
            'period_sessions' => $sessions->count(),
            'period_start' => $periodStart,
            'period_end' => $periodEnd,
            'source' => 'billable_sessions',
        ];
    }

    /** Read-only review includes completed lessons outside the stored contract.
     * These estimates are evidence for review, never an invoice or cash balance.
     */
    public function reviewSessions(Model $course, Collection $sessions): array
    {
        $completed = $sessions->whereIn('Status', self::BILLABLE_STATUSES)->values();
        $details = $completed->map(function (ClassSession $session) use ($course): array {
            $pricing = $this->pricing->forDate($course, (string) $session->SessionDate);
            $validDuration = self::minutes((string) $session->EndTime) > self::minutes((string) $session->StartTime);
            $known = $pricing['rate'] > 0 && ($pricing['rate_unit'] === 'session'
                || ($pricing['source'] === 'student_class_rate' && $session->session_charge !== null) || $validDuration);
            return ['session_id' => (int) $session->getKey(), 'date' => substr((string) $session->SessionDate, 0, 10),
                'rate' => $pricing['rate'], 'rate_unit' => $pricing['rate_unit'],
                'pricing_source' => $pricing['source'], 'amendment_id' => $pricing['amendment_id'],
                'estimated_charge' => $known ? $this->sessionAmount($session, $pricing) : null];
        });
        return ['completed_sessions' => $completed->count(),
            'estimated_charge' => $details->contains(fn ($row) => $row['estimated_charge'] === null) ? null : max(0, (int) round($details->sum('estimated_charge'))),
            'sessions' => $details->all()];
    }

    private function sessionAmount(ClassSession $session, array $pricing): float
    {
        if ($pricing['rate_unit'] === 'session') return (float) $pricing['rate'];
        if ($pricing['source'] === 'student_class_rate' && $session->session_charge !== null) {
            return (float) $session->session_charge;
        }
        $start = self::minutes((string) ($session->StartTime ?? ''));
        $end = self::minutes((string) ($session->EndTime ?? ''));
        return (max(0, $end - $start) / 60.0) * (float) $pricing['rate'];
    }

    /** @return Collection<int, ClassSession> */
    public function billableSessionsForPeriod(Model $course, string $billingPeriod): Collection
    {
        return $this->periodSessionQuery($course, $billingPeriod)
            ->whereIn('Status', self::BILLABLE_STATUSES)
            ->orderBy('SessionDate')->orderBy('StartTime')->orderBy('id')
            ->get(['id', 'SessionDate', 'StartTime', 'EndTime', 'Status', 'session_charge']);
    }

    private function periodSessionQuery(
        Model $course,
        string $billingPeriod,
        ?string $serviceStart = null,
        ?string $serviceEnd = null,
    ): Builder {
        try {
            $anchor = Carbon::createFromFormat('!Y-m', $billingPeriod);
        } catch (\Throwable) {
            $anchor = Carbon::today();
        }

        // A prepaid next-period invoice is labelled with the month its service
        // starts in (e.g. 8/30–9/28 → 2026-08); its own service range, when
        // known, is the window instead of the calendar month (#3445).
        $hasServiceRange = $serviceStart !== null && $serviceEnd !== null;
        $periodStart = $hasServiceRange
            ? Carbon::parse($serviceStart)->toDateString()
            : $anchor->copy()->startOfMonth()->toDateString();
        $periodEnd = $hasServiceRange
            ? Carbon::parse($serviceEnd)->toDateString()
            : $anchor->copy()->endOfMonth()->toDateString();

        $query = ClassSession::query();
        $query->where('StudentClassID', (int) $course->getKey())
            ->whereBetween('SessionDate', [$periodStart, $periodEnd])
            ->where(function ($query) use ($course, $periodStart, $periodEnd) {
                // Monthly/date-mode courses are bounded by their contract
                // interval even when legacy ClassSession rows exist outside it.
                // Count-based courses retain their purchased-session behavior.
                if (strtolower((string) ($course->getAttribute('ScheduleMode') ?? 'count')) !== 'date') {
                    return;
                }

                $courseStart = $course->getAttribute('StartDate')
                    ? Carbon::parse($course->getAttribute('StartDate'))->toDateString()
                    : $periodStart;
                $courseEnd = $course->getAttribute('EndDate')
                    ? Carbon::parse($course->getAttribute('EndDate'))->toDateString()
                    : $periodEnd;
                $query->whereBetween('SessionDate', [
                    max($periodStart, $courseStart),
                    min($periodEnd, $courseEnd),
                ]);
            });
        return $query;
    }

    /** @return list<array{class_session_id:int,date:string,start_time:?string,end_time:?string,subject:string,lesson:int,status:string}> */
    public function billableSessionDetailsForPeriod(Model $course, string $billingPeriod): array
    {
        return $this->sessionDetails($course, $this->billableSessionsForPeriod($course, $billingPeriod));
    }

    /**
     * Display only (amounts and billing snapshots never use this). Prefers the
     * billed lessons so the list matches the amount (for a paid/partly paid
     * invoice, $includeUpcoming: held + upcoming lessons in its service
     * range); with nothing to list yet it
     * lists the month's planned lessons, and for a prepaid next-period invoice
     * (month window empty) the lessons in its own service range (#3445).
     *
     * @return list<array{class_session_id:int,date:string,start_time:?string,end_time:?string,subject:string,lesson:int,status:string}>
     */
    public function slipSessionDetailsForPeriod(
        Model $course,
        string $billingPeriod,
        \DateTimeInterface|string|null $serviceStart = null,
        \DateTimeInterface|string|null $serviceEnd = null,
        bool $includeUpcoming = false,
    ): array {
        $sessions = $this->billableSessionsForPeriod($course, $billingPeriod);
        if ($includeUpcoming) {
            // A paid/partly paid amount is fixed and covers its whole service
            // period: list held lessons plus those still to come in that range
            // (the item range when known, else the billing month).
            $rangeStart = $serviceStart !== null && $serviceEnd !== null ? (string) $serviceStart : null;
            $rangeEnd = $rangeStart !== null ? (string) $serviceEnd : null;
            $held = $this->periodSessionQuery($course, $billingPeriod, $rangeStart, $rangeEnd)
                ->whereIn('Status', self::BILLABLE_STATUSES)
                ->get(['id', 'SessionDate', 'StartTime', 'EndTime', 'Status', 'session_charge']);
            $upcomingQuery = $this->periodSessionQuery($course, $billingPeriod, $rangeStart, $rangeEnd);
            $upcomingQuery->whereDate('SessionDate', '>=', Carbon::today()->toDateString());
            $sessions = $held->concat($this->plannedSessions($upcomingQuery))
                ->sortBy(fn (ClassSession $session) => sprintf('%s %s %010d', substr((string) $session->SessionDate, 0, 10), (string) $session->StartTime, (int) $session->getKey()))
                ->values();
            if ($rangeStart !== null) {
                // The item range is authoritative: never widen to the whole month.
                // Nothing held or upcoming (e.g. attendance not recorded yet):
                // list every planned lesson inside that range.
                if ($sessions->isEmpty()) {
                    $sessions = $this->plannedSessions($this->periodSessionQuery($course, $billingPeriod, $rangeStart, $rangeEnd));
                }

                return $this->sessionDetails($course, $sessions);
            }
        }
        if ($sessions->isEmpty()) {
            $sessions = $this->plannedSessions($this->periodSessionQuery($course, $billingPeriod));
        }
        if ($sessions->isEmpty() && $serviceStart !== null && $serviceEnd !== null) {
            // The service range is the whole prepaid period: held and still-to-happen lessons.
            $sessions = $this->periodSessionQuery($course, $billingPeriod, (string) $serviceStart, (string) $serviceEnd)
                ->whereIn('Status', [...self::BILLABLE_STATUSES, ...self::PLANNED_STATUSES])
                ->orderBy('SessionDate')->orderBy('StartTime')->orderBy('id')
                ->get(['id', 'SessionDate', 'StartTime', 'EndTime', 'Status', 'session_charge']);
        }

        return $this->sessionDetails($course, $sessions);
    }

    /**
     * The lessons an invoice covers: what its payment slip lists. Monthly
     * course: the billing period's lessons (plus upcoming ones once the amount
     * is fixed); otherwise every non-cancelled lesson of its courses in the
     * projected period.
     *
     * @param  array<string,mixed>  $projection  InvoiceAmountReconciliationService::resolve()
     * @return list<array<string,mixed>>
     */
    public function invoiceCoveredSessions(Invoice $invoice, array $projection): array
    {
        $studentClassIds = $invoice->loadMissing('items')->getRelationValue('items')
            ->pluck('StudentClassID')
            ->map(fn ($id) => (int) $id)
            ->filter(fn ($id) => $id > 0)
            ->unique()
            ->values()
            ->all();
        if ((int) ($invoice->StudentClassID ?? 0) > 0) {
            $studentClassIds[] = (int) $invoice->StudentClassID;
            $studentClassIds = array_values(array_unique($studentClassIds));
        }
        $monthlyCourse = $invoice->getRelationValue('studentClass');
        if ($monthlyCourse instanceof StudentClass && $monthlyCourse->getAttribute('ScheduleMode') === 'date' && $projection['billing_period']) {
            [$serviceStart, $serviceEnd] = $this->serviceRangeForCourse($invoice, (int) $monthlyCourse->getKey());

            return $this->slipSessionDetailsForPeriod(
                $monthlyCourse,
                $projection['billing_period'],
                $serviceStart,
                $serviceEnd,
                // A fixed amount (never repriced from held lessons: paid, partly
                // paid, or a cross-month cycle) covers upcoming lessons too.
                // Void/cancelled invoices keep the billed-only list.
                includeUpcoming: !$projection['repriceable']
                    && !in_array((string) ($invoice->Status ?? ''), ['void', 'cancelled'], true),
            );
        }

        return ClassSession::sessionsForPaymentSlip(
            $studentClassIds,
            $projection['period_start'],
            $projection['period_end']
        );
    }

    /**
     * This course's service range on the invoice: the span of its linked
     * items with both bounds (a MonthlySplit invoice has several), else the
     * invoice's only item.
     *
     * @return array{0:?string,1:?string}
     */
    public function serviceRangeForCourse(Invoice $invoice, int $courseId): array
    {
        $items = $invoice->loadMissing('items')->getRelationValue('items');
        $bounded = $items->filter(fn ($item) => $item->PeriodStart && $item->PeriodEnd);
        $linked = $bounded->filter(fn ($item) => (int) ($item->StudentClassID ?? 0) === $courseId);
        $source = $linked->isNotEmpty() ? $linked : ($items->count() === 1 ? $bounded : collect());
        if ($source->isEmpty()) {
            return [null, null];
        }

        return [
            $source->map(fn ($item) => Carbon::parse($item->PeriodStart)->toDateString())->min(),
            $source->map(fn ($item) => Carbon::parse($item->PeriodEnd)->toDateString())->max(),
        ];
    }

    /** @return Collection<int, ClassSession> */
    private function plannedSessions(Builder $query): Collection
    {
        // Still-to-happen lessons only (same set as receipts' upcoming list);
        // leave/excused/absent outcomes are not lessons the charge covers.
        return $query->whereIn('Status', self::PLANNED_STATUSES)
            ->orderBy('SessionDate')->orderBy('StartTime')->orderBy('id')
            ->get(['id', 'SessionDate', 'StartTime', 'EndTime', 'Status', 'session_charge']);
    }

    /** @param Collection<int, ClassSession> $sessions */
    private function sessionDetails(Model $course, Collection $sessions): array
    {
        $subject = method_exists($course, 'displaySubjectName')
            ? (string) $course->displaySubjectName()
            : '課程';

        return $sessions
            ->values()
            ->map(function (ClassSession $session, int $index) use ($subject): array {
                return [
                    'class_session_id' => (int) $session->getKey(),
                    'date' => Carbon::parse((string) $session->SessionDate)->toDateString(),
                    'start_time' => $session->StartTime ? substr((string) $session->StartTime, 0, 5) : null,
                    'end_time' => $session->EndTime ? substr((string) $session->EndTime, 0, 5) : null,
                    'subject' => $subject ?: '課程',
                    'lesson' => $index + 1,
                    'status' => (string) ($session->Status ?? ''),
                ];
            })
            ->all();
    }

    private static function minutes(string $time): int
    {
        $hm = substr($time, 0, 5);
        if (!preg_match('/^\d{2}:\d{2}$/', $hm)) {
            return 0;
        }

        [$hours, $minutes] = array_map('intval', explode(':', $hm));

        return ($hours * 60) + $minutes;
    }
}
