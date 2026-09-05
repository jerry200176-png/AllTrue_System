<?php

namespace App\Services;

use App\Models\ClassSession;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Model;
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
            return [
                'charge' => $storedCharge,
                'period_sessions' => 0,
                'period_start' => $periodStart,
                'period_end' => $periodEnd,
                'source' => 'stored_charge_no_billable_sessions',
            ];
        }

        $rate = (float) ($course->getAttribute('Rate') ?? 0);
        if ($rate <= 0) {
            return [
                'charge' => $storedCharge,
                'period_sessions' => $sessions->count(),
                'period_start' => $periodStart,
                'period_end' => $periodEnd,
                'source' => 'stored_charge_missing_rate',
            ];
        }

        $rateUnit = strtolower(trim((string) ($course->getAttribute('rate_unit') ?? 'session')));
        if (!in_array($rateUnit, ['session', 'hour'], true)) {
            $rateUnit = 'session';
        }

        if ($rateUnit === 'session') {
            $charge = (int) round($rate * $sessions->count());
        } else {
            $totalHours = 0.0;
            foreach ($sessions as $session) {
                if ($session->session_charge !== null) {
                    $totalHours += (float) $session->session_charge / $rate;
                    continue;
                }

                $start = self::minutes((string) ($session->StartTime ?? ''));
                $end = self::minutes((string) ($session->EndTime ?? ''));
                $totalHours += max(0, $end - $start) / 60.0;
            }
            $charge = (int) round($rate * $totalHours);
        }

        return [
            'charge' => max(0, $charge),
            'period_sessions' => $sessions->count(),
            'period_start' => $periodStart,
            'period_end' => $periodEnd,
            'source' => 'billable_sessions',
        ];
    }

    /** @return Collection<int, ClassSession> */
    public function billableSessionsForPeriod(Model $course, string $billingPeriod): Collection
    {
        try {
            $anchor = Carbon::createFromFormat('!Y-m', $billingPeriod);
        } catch (\Throwable) {
            $anchor = Carbon::today();
        }

        $periodStart = $anchor->copy()->startOfMonth()->toDateString();
        $periodEnd = $anchor->copy()->endOfMonth()->toDateString();

        return ClassSession::query()
            ->where('StudentClassID', (int) $course->getKey())
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
            })
            ->whereIn('Status', self::BILLABLE_STATUSES)
            ->orderBy('SessionDate')
            ->orderBy('StartTime')
            ->orderBy('id')
            ->get(['id', 'SessionDate', 'StartTime', 'EndTime', 'Status', 'session_charge']);
    }

    /** @return list<array{class_session_id:int,date:string,start_time:?string,end_time:?string,subject:string,lesson:int,status:string}> */
    public function billableSessionDetailsForPeriod(Model $course, string $billingPeriod): array
    {
        $subject = method_exists($course, 'displaySubjectName')
            ? (string) $course->displaySubjectName()
            : '課程';

        return $this->billableSessionsForPeriod($course, $billingPeriod)
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
