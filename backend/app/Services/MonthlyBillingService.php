<?php

namespace App\Services;

use App\Models\ClassSession;
use App\Models\CoursePackage;
use App\Models\Invoice;
use App\Models\StudentClass;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

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
    public const BILLABLE_STATUSES = ['attended', 'completed', 'late'];
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
        return $this->summarizePeriod($course, $this->defaultPeriodFor($course, $anchor));
    }

    /**
     * The billing month to price a monthly contract by when no open invoice names
     * one: the anchor date (default today) clamped into the contract's own
     * StartDate..EndDate. An ended September contract stays September instead of
     * being priced as the current month (in-app #377/#378).
     */
    public function defaultPeriodFor(Model $course, \DateTimeInterface|string|null $anchor = null): string
    {
        $date = Carbon::parse($anchor ?? Carbon::today())->startOfDay();
        $start = $course->getAttribute('StartDate');
        $end = $course->getAttribute('EndDate');
        if ($end && $date->gt(Carbon::parse($end))) {
            $date = Carbon::parse($end);
        }
        if ($start && $date->lt(Carbon::parse($start))) {
            $date = Carbon::parse($start);
        }

        return $date->format('Y-m');
    }

    /**
     * in-app #432 (Founder 2026-10-28, option A): the month a payment with no open bill collects for. A monthly contract
     * that spans calendar months is billed month by month, oldest unbilled month first; otherwise today's rule.
     * Shared by 登記收款 (directorRecord), confirm, the tuition slip, the pay link and the ledger's drawer form.
     *
     * @param  Collection<int, Invoice>|null  $invoices  this contract's non-void bills with items, when already loaded
     */
    public function collectionPeriodFor(Model $course, \DateTimeInterface|string|null $anchor = null, ?Collection $invoices = null): string
    {
        return $this->unbilledPeriodFor($course, $invoices) ?? $this->defaultPeriodFor($course, $anchor);
    }

    /**
     * #4765: dates of countable lessons (attended/completed/late) that lie outside a monthly contract's StartDate..EndDate,
     * sorted. Usage already recorded fixes the period it belongs to, so billing (which bounds a month by the contract) cannot
     * see these. Only date-mode, non-package contracts with both dates; $start/$end let a caller test proposed dates.
     *
     * @return list<string>
     */
    public function taughtLessonsOutsideContract(Model $course, ?string $start = null, ?string $end = null): array
    {
        $start ??= $course->getAttribute('StartDate');
        $end ??= $course->getAttribute('EndDate');
        if ((string) $course->getAttribute('ScheduleMode') !== 'date' || (int) ($course->getAttribute('PackageID') ?? 0) > 0 || !$start || !$end) {
            return [];
        }
        $start = Carbon::parse($start)->toDateString();
        $end = Carbon::parse($end)->toDateString();

        return $this->countableLessonDates($course)->filter(fn (string $d) => $d < $start || $d > $end)->unique()->sort()->values()->all();
    }

    /**
     * #4765: the outside dates only when no countable lesson lies inside the contract dates: then billing prices the
     * contract's month from the stored Charge. A makeup taught after the end beside lessons inside stays collectable.
     * A lesson a live bill's own item range already covers (BillingPeriod::datesCoveredByBills) is settled, so it neither blocks nor counts.
     *
     * @return list<string>
     */
    public function lessonsOnlyOutsideContract(Model $course): array
    {
        // #5702: a pre-start lesson belongs to the first month (billed with it, or blocked as #5703), so it is not "outside" here.
        $outside = array_values(array_diff($this->taughtLessonsOutsideContract($course), $this->preStartLessons($course)[1]));
        $covered = \App\Services\Billing\BillingPeriod::datesCoveredByBills($course, $outside);
        $open = array_values(array_diff($outside, $covered));
        $rest = $this->countableLessonDates($course)->reject(fn (string $d) => in_array($d, $covered, true));

        return $open !== [] && $rest->every(fn (string $d) => in_array($d, $open, true)) ? $open : [];
    }

    /** @return \Illuminate\Support\Collection<int, string> dates of the course's countable lessons (one per lesson) */
    private function countableLessonDates(Model $course): \Illuminate\Support\Collection
    {
        $id = (int) $course->getKey();
        $preloaded = $this->preloadedSessions[$id] ?? null;
        $dates = $preloaded !== null
            ? $preloaded->filter(fn (ClassSession $s) => in_array(strtolower(rtrim((string) $s->Status)), self::BILLABLE_STATUSES, true))->pluck('SessionDate')
            : ClassSession::query()->where('StudentClassID', $id)->whereIn('Status', self::BILLABLE_STATUSES)->pluck('SessionDate');

        return $dates->map(fn ($d) => substr((string) $d, 0, 10))->values();
    }

    /**
     * #5702 (Founder 2026-11-28, option A): the first day a monthly contract's first month (the one holding StartDate) bills
     * from. A countable lesson taught before StartDate is billed with that month, so the month before never sees it. Not
     * pulled in: a lesson another live bill already covers (e.g. moved in from the previous term, #5198), and any lesson
     * while the first month's bill has a fixed amount that does not hold it (paid, partly paid, priced, dated or shared):
     * that lesson is then the #5703 shape, never a second bill. Count mode, package members and tutoring keep StartDate.
     */
    public function billingStart(Model $course): ?string
    {
        return $this->preStartLessons($course)[0];
    }

    /** @return array{0: ?string, 1: list<string>} billingStart(), and the pre-start dates that belong to the first month (no other bill covers them) */
    private function preStartLessons(Model $course): array
    {
        $start = $course->getAttribute('StartDate') ? Carbon::parse($course->getAttribute('StartDate'))->toDateString() : null;
        if (!$start || (string) $course->getAttribute('ScheduleMode') !== 'date' || (int) ($course->getAttribute('PackageID') ?? 0) > 0
            || strtolower(trim((string) $course->getAttribute('ClassType'))) === 'tutoring') {
            return [$start, []];
        }
        $before = $this->countableLessonDates($course)->filter(fn (string $d) => $d < $start)->unique()->sort()->values()->all();
        if ($before === []) {
            return [$start, []];
        }
        $id = (int) $course->getKey();
        if (isset($this->preloadedSessions[$id], $this->preStartMemo[$id])) {
            return $this->preStartMemo[$id];
        }
        $held = [];
        $open = true;
        $firstBills = Invoice::query()->notVoided()->with(['items', 'payments'])->where('StudentClassID', $id)->where('billing_period', substr($start, 0, 7))->get();
        foreach ($firstBills as $bill) {
            // Held: the lessons its first payment priced (MonthlyBillFreeze evidence). Open: priced now from its month's lessons
            // (today's repricing): no frozen evidence, no money (stored or Payment rows), no dated line, no other course's line.
            $listed = collect($bill->billing_snapshot['sessions'] ?? [])->pluck('date')->map(fn ($d) => substr((string) $d, 0, 10))->all();
            $held = [...$held, ...array_intersect($before, $listed)];
            $open = $open && empty($bill->billing_snapshot) && (string) ($bill->Status ?? 'unpaid') === 'unpaid' && (int) $bill->PaidAmount === 0 && $bill->payments->isEmpty()
                && $this->serviceRangeForCourse($bill, $id)[0] === null && $bill->items->every(fn ($i) => (int) ($i->StudentClassID ?? $id) === $id);
        }
        $free = array_values(array_diff($before, \App\Services\Billing\BillingPeriod::datesCoveredByBills($course, $before, $firstBills->modelKeys())));

        // One-month limit (Founder-delegated, 2026-11-28): pull only from the 1st of the month before StartDate's month; an older
        // uncovered lesson pulls none of them (one rule, no partial range) and stays for month_partly_billed.
        $limit = Carbon::parse($start)->startOfMonth()->subMonthNoOverflow()->toDateString();
        $pulled = [...$held, ...($open && ($free === [] || $free[0] >= $limit) ? $free : [])];
        $from = min([$start, ...$pulled]);
        // The windows bill the whole from..StartDate range: a lesson in it neither held nor pulled (another bill covers it, #5198
        // moved lesson) would be charged twice, so keep StartDate and let month_partly_billed hold the pre-start lessons.
        if (array_diff(array_filter($before, fn (string $d) => $d >= $from), $pulled) !== []) {
            $from = $start;
        }
        $result = [$from, $free];
        if (isset($this->preloadedSessions[$id])) {
            $this->preStartMemo[$id] = $result;
        }

        return $result;
    }

    /**
     * The oldest calendar month inside a monthly contract whose countable lessons (attended/completed/late) no non-void
     * bill of the contract covers, or null. A bill covers its item service range (serviceRangeForCourse), else its
     * billing_period month. Null (today's rule) whenever coverage is unclear, so no lesson can be billed twice: a bill
     * without a readable period, a month partly covered, or a month that already carries a bill under another range.
     *
     * @param  Collection<int, Invoice>|null  $invoices  this contract's non-void bills with items, when already loaded
     */
    public function unbilledPeriodFor(Model $course, ?Collection $invoices = null): ?string
    {
        $month = $this->oldestUncoveredMonth($course, $invoices);

        return $month && $month['clean'] ? $month['billing_period'] : null;
    }

    /**
     * #5703 (Founder 2026-11-28, option A): the oldest month that still has countable lessons no bill covers, when that month
     * is ALREADY partly covered by, or labelled on, a bill. No second bill is opened for it, so 登記收款 has nothing to collect against; the caller refuses
     * with this plain reason instead of "已收齊". Null when there is no such month or coverage is unreadable.
     *
     * @param  Collection<int, Invoice>|null  $invoices  this contract's non-void bills with items, when already loaded
     * @return array{code:string,message:string,billing_period:string,lesson_dates:list<string>}|null
     */
    public function partlyBilledMonth(Model $course, ?Collection $invoices = null): ?array
    {
        $month = $this->oldestUncoveredMonth($course, $invoices);
        if (!$month || $month['clean']) {
            return null;
        }
        $dates = implode('、', array_map(fn (string $d) => Carbon::parse($d)->format('n/j'), $month['lesson_dates']));
        $start = substr((string) $course->getAttribute('StartDate'), 0, 10);
        // 更正帳單 (BillCorrection::gap) only adds lessons after a line's PeriodEnd, never before the contract start.
        $message = min($month['lesson_dates']) < $start
            ? '%s 是合約開始日前上的課，還沒算進任何帳單，系統不能自動補進 %d 月的帳單，所以這裡不能登記，請回報。沒有登記任何款項。'
            : '%s 的課還沒算進任何帳單，但 %d 月已有其他堂算在原本的帳單裡，同一個月不會再開第二張，所以這裡不能登記。請把這幾堂補進原帳單：學生帳務有「更正帳單」的分校在那裡更正，沒有的請回報。沒有登記任何款項。';

        return ['code' => 'month_partly_billed', 'billing_period' => $month['billing_period'], 'lesson_dates' => $month['lesson_dates'],
            'message' => sprintf($message, $dates, (int) substr($month['billing_period'], 5, 2))];
    }

    /**
     * @param  Collection<int, Invoice>|null  $invoices
     * @return array{billing_period:string,lesson_dates:list<string>,clean:bool}|null  clean = no bill touches that month
     */
    private function oldestUncoveredMonth(Model $course, ?Collection $invoices = null): ?array
    {
        $id = (int) $course->getKey();
        $start = $course->getAttribute('StartDate') ? Carbon::parse($course->getAttribute('StartDate'))->toDateString() : null;
        $end = $course->getAttribute('EndDate') ? Carbon::parse($course->getAttribute('EndDate'))->toDateString() : null;
        if ((string) $course->getAttribute('ScheduleMode') !== 'date' || !$start || !$end) {
            return null;
        }
        $invoices ??= Invoice::query()->where('StudentClassID', $id)
            ->where(fn ($q) => $q->whereNull('Status')->orWhere('Status', '!=', 'void'))->with('items')->get();
        [$billFrom, $preStart] = $this->preStartLessons($course); // #5702: these pre-start lessons belong to StartDate's month
        $ranges = [];
        foreach ($invoices as $invoice) {
            $period = (string) $invoice->billing_period;
            if (!preg_match('/^\d{4}-(0[1-9]|1[0-2])$/', $period)) {
                return null;
            }
            $month = Carbon::createFromFormat('!Y-m', $period);
            [$from, $to] = $this->serviceRangeForCourse($invoice, $id);
            $labelFrom = $month->copy()->startOfMonth()->toDateString();
            $ranges[] = [$period, $from ?? ($period === substr($start, 0, 7) ? min($labelFrom, $billFrom) : $labelFrom), $to ?? $month->copy()->endOfMonth()->toDateString()];
        }
        $preloaded = $this->preloadedSessions[$id] ?? null;
        $dates = ($preloaded !== null
            ? $preloaded->filter(fn (ClassSession $s) => in_array(strtolower(rtrim((string) $s->Status)), self::BILLABLE_STATUSES, true))->pluck('SessionDate')
            : ClassSession::query()->where('StudentClassID', $id)->whereIn('Status', self::BILLABLE_STATUSES)->pluck('SessionDate'))
            ->map(fn ($date) => substr((string) $date, 0, 10))->filter(fn (string $date) => ($date >= $start || in_array($date, $preStart, true)) && $date <= $end)->sort();
        foreach ($dates->groupBy(fn (string $date) => substr(max($date, $start), 0, 7))->sortKeys() as $month => $monthDates) {
            $open = $monthDates->reject(fn (string $date) => collect($ranges)->contains(fn ($r) => $date >= $r[1] && $date <= $r[2]))->values();
            if ($open->isEmpty()) {
                continue;
            }

            return ['billing_period' => (string) $month, 'lesson_dates' => $open->all(),
                'clean' => $open->count() === $monthDates->count() && !collect($ranges)->contains(fn ($r) => $r[0] === $month)
                    && !$open->contains(fn (string $date) => $date < $billFrom)]; // a pre-start lesson the first month cannot take
        }

        return null;
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
        if (strtolower((string) ($course->getAttribute('ScheduleMode') ?? 'count')) === 'date'
            && ($floor = $this->firstMonthFloor($course, $periodStart, $periodEnd)) !== null) {
            $periodStart = min($periodStart, $floor); // #5702: the first month is dated from its earliest pre-start lesson
        }
        $storedCharge = max(0, (int) ($course->getAttribute('Charge') ?? 0));

        // A count package is one shared obligation billed at the package level. A monthly ('date') package member is
        // billed per member by its own lessons and rate (#3892, RFC_SHARED_PACKAGE_COLLECTION Q3/D4; in-app #478).
        if ((int) ($course->getAttribute('PackageID') ?? 0) > 0
            && strtolower((string) ($course->getAttribute('ScheduleMode') ?? 'count')) !== 'date') {
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
                ? ($this->preloadedPeriodSessions($course, $billingPeriod) ?? $this->periodSessionQuery($course, $billingPeriod)->get(['Status'])) : collect();
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
            $known = self::priceKnown($session, $pricing);
            return ['session_id' => (int) $session->getKey(), 'date' => substr((string) $session->SessionDate, 0, 10),
                'rate' => $pricing['rate'], 'rate_unit' => $pricing['rate_unit'],
                'pricing_source' => $pricing['source'], 'amendment_id' => $pricing['amendment_id'],
                'estimated_charge' => $known ? $this->sessionAmount($session, $pricing) : null];
        });
        return ['completed_sessions' => $completed->count(),
            'estimated_charge' => $details->contains(fn ($row) => $row['estimated_charge'] === null) ? null : max(0, (int) round($details->sum('estimated_charge'))),
            'sessions' => $details->all()];
    }

    /**
     * in-app #518 (display only): what the lessons still to be taught in [start,end] (scheduled or rescheduled) are worth,
     * priced exactly like reviewSessions() (forDate + sessionAmount). Null when any of them has no known price, so the
     * caller cannot claim a match it did not verify.
     */
    public function remainingScheduledCharge(Model $course, string $start, string $end): ?int
    {
        $remaining = ClassSession::query()->where('StudentClassID', (int) $course->getKey())
            ->whereIn('Status', ['scheduled', 'rescheduled'])
            ->whereBetween('SessionDate', [$start, $end])
            ->get(['id', 'StudentClassID', 'SessionDate', 'StartTime', 'EndTime', 'Status', 'session_charge']);
        $total = 0.0;
        foreach ($remaining as $session) {
            $pricing = $this->pricing->forDate($course, (string) $session->SessionDate);
            if (!self::priceKnown($session, $pricing)) return null;
            $total += $this->sessionAmount($session, $pricing);
        }

        return max(0, (int) round($total));
    }

    /**
     * 學收 of one course for one month (月對帳表, Jerry 2026-11-18): the lessons a bill charges (BILLABLE_STATUSES:
     * 到班/遲到/補課; not 值班, 試聽, 輔導), each priced as reviewSessions() prices it. A 輔導 course owes nothing (#325);
     * a monthly package member is priced at the package's rate and unit unless a price amendment of its own is in force
     * that day (as forDate() prices it for the bill); a monthly contract counts only lessons inside
     * its dates (#2451), its StartDate month also the pre-start lessons its first bill pulls in (#5702); a course priced by its total (Rate 0, no amendment) at Charge ÷ SessionCount, rounded once.
     *
     * @param  Collection<int, ClassSession>  $sessions  the month's lessons, Status = the status the calendar shows
     * @return array{lessons: int, amount: int|null}  amount null = a lesson has no known price
     */
    public function monthTuition(StudentClass $course, Collection $sessions, string $period): array
    {
        if (strtolower(trim((string) $course->ClassType)) === 'tutoring') {
            return ['lessons' => 0, 'amount' => 0];
        }
        $dated = (string) $course->ScheduleMode === 'date';
        [$from, $to] = [substr((string) $course->StartDate, 0, 10), substr((string) $course->EndDate, 0, 10)];
        // #5702: pre-start lessons the first-month bill charges are that month's 學收, never their own calendar month's.
        if ($dated && $from !== '' && substr($from, 0, 7) === $period && ($billFrom = $this->billingStart($course) ?? $from) < $from) {
            $sessions = $billFrom >= "{$period}-01" ? $sessions : $sessions->concat(ClassSession::query()->where('StudentClassID', $course->ID)
                ->whereIn('Status', self::BILLABLE_STATUSES)->where('SessionDate', '>=', $billFrom)->where('SessionDate', '<', "{$period}-01")->get());
            $from = $billFrom;
        }
        $lessons = $sessions->whereIn('Status', self::BILLABLE_STATUSES)->filter(fn (ClassSession $s) => !$dated
            || (($from === '' || substr((string) $s->SessionDate, 0, 10) >= $from) && ($to === '' || substr((string) $s->SessionDate, 0, 10) <= $to)))->values();
        $n = $lessons->count();
        $package = $course->coursePackage;
        $packagePricing = $package !== null && $package->billing_mode === CoursePackage::BILLING_MODE_MONTHLY && !$package->stop
            ? ['rate' => (float) $package->rate, 'rate_unit' => strtolower(trim((string) $package->rate_unit)) === 'hour' ? 'hour' : 'session', 'source' => 'package']
            : null;
        $total = 0.0;
        foreach ($lessons as $s) {
            $pricing = $this->pricing->forDate($course, (string) $s->SessionDate);
            if ($packagePricing !== null && $pricing['source'] !== 'pricing_amendment') {
                $pricing = $packagePricing;
            }
            if (!self::priceKnown($s, $pricing)) {
                $total = null;
                break;
            }
            $total += $this->sessionAmount($s, $pricing);
        }
        $byTotal = $total === null && $packagePricing === null && (float) ($course->Rate ?? 0) <= 0 && (int) ($course->SessionCount ?? 0) > 0
            && $course->pricingAmendments()->whereNull('voided_at')->doesntExist();
        if ($byTotal) {
            $total = $n * (float) ($course->Charge ?? 0) / (int) $course->SessionCount;
        }

        return ['lessons' => $n, 'amount' => $total === null ? null : max(0, (int) round($total))];
    }

    /** A lesson's price is known: a per-lesson price, or the lesson's own stored charge, or a real length for an hourly one. */
    private static function priceKnown(ClassSession $session, array $pricing): bool
    {
        return $pricing['rate'] > 0 && ($pricing['rate_unit'] === 'session'
            || ($pricing['source'] === 'student_class_rate' && $session->session_charge !== null)
            || self::minutes((string) $session->EndTime) > self::minutes((string) $session->StartTime));
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
        if (($rows = $this->preloadedPeriodSessions($course, $billingPeriod)) !== null) {
            return $rows
                ->filter(fn (ClassSession $session) => in_array(strtolower(rtrim((string) $session->Status)), self::BILLABLE_STATUSES, true))
                ->sortBy(fn (ClassSession $session) => [(string) $session->SessionDate, (string) $session->StartTime, (int) $session->getKey()])
                ->values();
        }

        return $this->periodSessionQuery($course, $billingPeriod)
            ->whereIn('Status', self::BILLABLE_STATUSES)
            ->orderBy('SessionDate')->orderBy('StartTime')->orderBy('id')
            ->get(['id', 'SessionDate', 'StartTime', 'EndTime', 'Status', 'session_charge']);
    }

    /** @var array<int, Collection<int, ClassSession>>|null course id => all its sessions, set only while a caller batches many invoices. */
    private ?array $preloadedSessions = null;

    /** @var array<int, array{0: ?string, 1: list<string>}> preStartLessons() per course, kept only for the same read-only preload window. */
    private array $preStartMemo = [];

    /** One query for every listed course's sessions; summarizePeriod then filters in memory. Pair with forgetPreloadedSessions(). */
    public function preloadSessions(array $courseIds): void
    {
        $ids = array_values(array_unique(array_map('intval', $courseIds)));
        $grouped = $ids === [] ? collect() : ClassSession::query()->whereIn('StudentClassID', $ids)
            ->get(['id', 'StudentClassID', 'SessionDate', 'StartTime', 'EndTime', 'Status', 'session_charge'])
            ->groupBy(fn (ClassSession $session) => (int) $session->StudentClassID);
        $this->preloadedSessions = [];
        $this->preStartMemo = [];
        foreach ($ids as $id) {
            $this->preloadedSessions[$id] = $grouped->get($id, collect());
        }
    }

    public function forgetPreloadedSessions(): void
    {
        $this->preloadedSessions = null;
        $this->preStartMemo = [];
    }

    /** Same rows as periodSessionQuery() (month window, date-mode contract bounds), or null when this course was not preloaded. */
    private function preloadedPeriodSessions(Model $course, string $billingPeriod): ?Collection
    {
        $all = $this->preloadedSessions[(int) $course->getKey()] ?? null;
        if ($all === null) {
            return null;
        }
        try {
            $anchor = Carbon::createFromFormat('!Y-m', $billingPeriod);
        } catch (\Throwable) {
            $anchor = Carbon::today();
        }
        $from = $anchor->copy()->startOfMonth()->toDateString();
        $to = $anchor->copy()->endOfMonth()->toDateString();
        if (strtolower((string) ($course->getAttribute('ScheduleMode') ?? 'count')) === 'date') {
            $floor = $this->firstMonthFloor($course, $from, $to);
            [$from, $to] = self::boundByContract($course, $floor !== null ? min($from, $floor) : $from, $to, $floor);
        }

        return $all->filter(fn (ClassSession $session) => substr((string) $session->SessionDate, 0, 10) >= $from && substr((string) $session->SessionDate, 0, 10) <= $to)->values();
    }

    /** @return \Illuminate\Database\Eloquent\Builder<\App\Models\ClassSession> */
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

        // A cycle invoice is labelled with the month of its first lesson (in-app #514,
        // e.g. 8/30–9/28 with September lessons → 2026-09); its own service range, when
        // known, is the window instead of the calendar month (#3445).
        $hasServiceRange = $serviceStart !== null && $serviceEnd !== null;
        $periodStart = $hasServiceRange
            ? Carbon::parse($serviceStart)->toDateString()
            : $anchor->copy()->startOfMonth()->toDateString();
        $periodEnd = $hasServiceRange
            ? Carbon::parse($serviceEnd)->toDateString()
            : $anchor->copy()->endOfMonth()->toDateString();

        $floor = strtolower((string) ($course->getAttribute('ScheduleMode') ?? 'count')) === 'date' ? $this->firstMonthFloor($course, $periodStart, $periodEnd) : null;
        if ($floor !== null && !$hasServiceRange) {
            $periodStart = min($periodStart, $floor); // #5702: the first month opens at its earliest pre-start lesson; a bill's own service range stays its window
        }

        $query = ClassSession::query();
        $query->where('StudentClassID', (int) $course->getKey())
            ->whereBetween('SessionDate', [$periodStart, $periodEnd])
            ->where(function ($query) use ($course, $periodStart, $periodEnd, $floor) {
                // Monthly/date-mode courses are bounded by their contract
                // interval even when legacy ClassSession rows exist outside it.
                // Count-based courses retain their purchased-session behavior.
                if (strtolower((string) ($course->getAttribute('ScheduleMode') ?? 'count')) !== 'date') {
                    return;
                }

                $query->whereBetween('SessionDate', self::boundByContract($course, $periodStart, $periodEnd, $floor));
            });
        return $query;
    }

    /** #5702: billingStart() for a window that holds StartDate (the first contract month), else null; the month before stays empty. */
    private function firstMonthFloor(Model $course, string $from, string $to): ?string
    {
        $start = $course->getAttribute('StartDate') ? Carbon::parse($course->getAttribute('StartDate'))->toDateString() : null;

        return $start !== null && $from <= $start && $to >= $start ? $this->billingStart($course) : null;
    }

    /**
     * A date-mode contract bounds the lessons it bills to its StartDate (or $billingStart, #5702)..EndDate; other contracts keep the window as given.
     *
     * @return array{0: string, 1: string} Y-m-d from, to
     */
    public static function boundByContract(Model $course, string $from, string $to, ?string $billingStart = null): array
    {
        if (strtolower((string) ($course->getAttribute('ScheduleMode') ?? 'count')) !== 'date') {
            return [$from, $to];
        }
        $courseStart = $billingStart ?? ($course->getAttribute('StartDate') ? Carbon::parse($course->getAttribute('StartDate'))->toDateString() : $from);
        $courseEnd = $course->getAttribute('EndDate') ? Carbon::parse($course->getAttribute('EndDate'))->toDateString() : $to;

        return [max($from, $courseStart), min($to, $courseEnd)];
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

    /**
 * @return Collection<int, ClassSession>
 * @param Builder<ClassSession> $query
 */
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
