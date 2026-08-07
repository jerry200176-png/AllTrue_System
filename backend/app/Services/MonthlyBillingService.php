<?php

namespace App\Services;

use App\Models\ClassSession;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Model;

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

        $sessions = ClassSession::query()
            ->where('StudentClassID', (int) $course->getKey())
            ->whereBetween('SessionDate', [$periodStart, $periodEnd])
            ->whereIn('Status', self::BILLABLE_STATUSES)
            ->orderBy('SessionDate')
            ->orderBy('StartTime')
            ->get(['SessionDate', 'StartTime', 'EndTime', 'session_charge']);

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
