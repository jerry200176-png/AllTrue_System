<?php

namespace App\Services\Scheduling;

use Carbon\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * RFC_NONSTANDARD_SESSION_DURATION_BILLING Phase 0A — read-only inventory.
 *
 * Every query is a SELECT. Nothing here (nor the wrapping
 * `sessions:report-nonstandard-duration` command) may call save()/update()/insert()/
 * delete(), dispatch a job, or invoke SessionDeductionService/PackageDeductionService.
 * Diagnostic report, never a repair tool. Query count is bounded and independent of
 * how many courses are scanned: every per-course figure comes from a GROUP BY
 * aggregate, never a per-course query.
 *
 * "Standard lesson minutes" is per course. This reads each course's CURRENT
 * `SessionDuration` (the pre-migration field that still doubles as scheduling default
 * and billing standard — exactly the coupling the RFC breaks) and mirrors
 * SessionDeductionService/StudentClass semantics, including the legacy 60-minute
 * fallback, so the report answers "how would today's engine treat this course".
 */
final class NonstandardDurationInventoryReporter
{
    /** Mirrors StudentClass::DEFAULT_SESSION_MINUTES (legacy fallback, not a product rule). */
    private const LEGACY_ENGINE_FALLBACK_MINUTES = 60;

    /** Statuses meaning the session actually took place / is still upcoming. */
    private const HAPPENED_STATUSES = ['completed', 'attended', 'late'];
    private const PLANNED_STATUSES = ['scheduled'];

    /** Ledger sources representing consumption (mirrors SessionDeductionService). */
    private const CONSUMPTION_SOURCES = ['attendance', 'retro_leave', 'status_adjust'];

    /** @return array<string, mixed> */
    public function build(?int $campusId, ?int $studentClassId, int $limit, bool $details): array
    {
        $courses = $this->loadCourses($campusId, $studentClassId, $limit);
        $courseIds = array_map(static fn ($c) => (int) $c->ID, $courses);
        $ledger = $this->loadLedgerAggregates($courseIds);

        return [
            'meta' => [
                'READ_ONLY' => true,
                'generated_at' => Carbon::now('Asia/Taipei')->toIso8601String(),
                'campus_id' => $campusId,
                'student_class_id' => $studentClassId,
                'limit' => $limit,
                'details' => $details,
                'courses_scanned' => count($courses),
            ],
            'b1_contract_schedule_mismatch' => $this->buildDurationMismatch($courses, $details),
            'b2_minute_ledger_adoption' => $this->buildLedgerAdoption($courseIds, $ledger),
        ];
    }

    /** @return list<object> */
    private function loadCourses(?int $campusId, ?int $studentClassId, int $limit): array
    {
        $q = DB::table('StudentClass as sc')
            ->join('Student as s', 's.id', '=', 'sc.StudentID')
            ->whereNotNull('sc.SessionDuration')
            ->where('sc.SessionDuration', '>=', 1)
            ->where(fn ($w) => $w->where('sc.ScheduleMode', 'count')->orWhereNull('sc.ScheduleMode'));

        if ($studentClassId !== null) {
            $q->where('sc.ID', $studentClassId);
        }
        if ($campusId !== null) {
            $q->where('s.CampusID', $campusId);
        }

        return $q->orderBy('sc.ID')
            ->limit(max(1, $limit))
            ->select(['sc.ID', 'sc.SessionDuration', 's.CampusID'])
            ->get()
            ->all();
    }

    /**
     * One GROUP BY aggregate for every scanned course — no per-course queries.
     *
     * Net is in MINUTES, not a count of events: each deduct adds its recorded `minutes`
     * and each reverse subtracts its own, with the course's per-session length used only
     * where `minutes` is NULL (legacy whole-session encoding). Counting events would call
     * a 180-minute deduct reversed by a 120-minute reverse balanced, hiding 60 minutes.
     *
     * @param  list<int>  $courseIds
     * @return array<int, array{net_minutes:int, deduct_rows:int, reverse_rows:int, rows_with_minutes:int, partial_rows:int}>
     */
    private function loadLedgerAggregates(array $courseIds): array
    {
        if ($courseIds === []) {
            return [];
        }

        $perSession = 'CASE WHEN sc.SessionDuration >= 1 THEN sc.SessionDuration ELSE '
            . self::LEGACY_ENGINE_FALLBACK_MINUTES . ' END';
        $sources = "'" . implode("','", self::CONSUMPTION_SOURCES) . "'";

        $rows = DB::table('session_deduction_ledger as l')
            ->join('StudentClass as sc', 'sc.ID', '=', 'l.student_class_id')
            ->whereIn('l.student_class_id', $courseIds)
            ->whereRaw("l.source IN ({$sources})")
            ->groupBy('l.student_class_id')
            ->selectRaw('l.student_class_id as sc_id')
            ->selectRaw(
                "SUM(CASE WHEN l.event_type = 'deduct' THEN COALESCE(l.minutes, {$perSession}) "
                . "WHEN l.event_type = 'reverse' THEN -COALESCE(l.minutes, {$perSession}) "
                . 'ELSE 0 END) as net_minutes'
            )
            ->selectRaw("SUM(CASE WHEN l.event_type = 'deduct' THEN 1 ELSE 0 END) as deduct_rows")
            ->selectRaw("SUM(CASE WHEN l.event_type = 'reverse' THEN 1 ELSE 0 END) as reverse_rows")
            ->selectRaw('SUM(CASE WHEN l.minutes IS NOT NULL THEN 1 ELSE 0 END) as rows_with_minutes')
            ->selectRaw(
                "SUM(CASE WHEN l.minutes IS NOT NULL AND l.minutes <> {$perSession} THEN 1 ELSE 0 END) as partial_rows"
            )
            ->get();

        $out = [];
        foreach ($rows as $r) {
            $out[(int) $r->sc_id] = [
                'net_minutes' => (int) $r->net_minutes,
                'deduct_rows' => (int) $r->deduct_rows,
                'reverse_rows' => (int) $r->reverse_rows,
                'rows_with_minutes' => (int) $r->rows_with_minutes,
                'partial_rows' => (int) $r->partial_rows,
            ];
        }

        return $out;
    }

    /**
     * B1 — sessions whose actual duration differs from their own course's contract,
     * split into ALREADY HAPPENED (impact already in the ledger) vs still PLANNED
     * (impact still avoidable). Makeup sessions (schedules.type='extra') are excluded:
     * the existing engine already prorates those by design.
     *
     * @param  list<object>  $courses
     * @return array<string, mixed>
     */
    private function buildDurationMismatch(array $courses, bool $details): array
    {
        // No special-casing for zero courses: whereIn([]) already matches nothing.
        $courseIds = array_map(static fn ($c) => (int) $c->ID, $courses);
        $makeupKeys = $this->loadMakeupKeys($courseIds);
        $courseById = [];
        foreach ($courses as $c) {
            $courseById[(int) $c->ID] = $c;
        }

        $sessions = DB::table('ClassSession')
            ->whereIn('StudentClassID', $courseIds)
            ->whereIn('Status', array_merge(self::HAPPENED_STATUSES, self::PLANNED_STATUSES))
            ->select(['id', 'StudentClassID', 'SessionDate', 'StartTime', 'EndTime', 'Status'])
            ->get();

        $buckets = ['happened' => $this->emptyBucket(), 'planned' => $this->emptyBucket()];
        $affected = [];
        $sample = [];

        foreach ($sessions as $s) {
            $scId = (int) $s->StudentClassID;
            $course = $courseById[$scId] ?? null;
            if (!$course) {
                continue;
            }

            $key = $scId . '|' . substr((string) $s->SessionDate, 0, 10) . '|' . substr((string) $s->StartTime, 0, 5);
            if (isset($makeupKeys[$key])) {
                continue;
            }

            $contract = $this->contractSessionMinutes($course);
            $actual = $this->durationMinutes($s->StartTime, $s->EndTime);
            if ($actual <= 0 || $actual === $contract) {
                continue;
            }

            $phase = in_array((string) $s->Status, self::HAPPENED_STATUSES, true) ? 'happened' : 'planned';
            $diff = abs($actual - $contract);
            $campusId = (int) ($course->CampusID ?? 0);

            $buckets[$phase]['sessions']++;
            $buckets[$phase]['courses'][$scId] = true;
            $buckets[$phase]['by_campus'][$campusId] = ($buckets[$phase]['by_campus'][$campusId] ?? 0) + 1;
            $label = $this->bucketLabel($diff);
            $buckets[$phase]['duration_diff_minutes_buckets'][$label]
                = ($buckets[$phase]['duration_diff_minutes_buckets'][$label] ?? 0) + 1;
            $affected[$scId] = true;

            if ($details && count($sample) < 50) {
                // Limited identifiers only — never student name/phone/RFID (CI-log safe).
                $sample[] = [
                    'student_class_id' => $scId,
                    'class_session_id' => (int) $s->id,
                    'campus_id' => $campusId,
                    'phase' => $phase,
                    'contract_session_minutes' => $contract,
                    'actual_duration_minutes' => $actual,
                    'diff_minutes' => $diff,
                ];
            }
        }

        $result = [
            'courses_with_session_duration_set' => count($courses),
            'happened' => $this->finalizeBucket($buckets['happened']),
            'planned' => $this->finalizeBucket($buckets['planned']),
            'affected_courses' => count($affected),
            'affected_course_ids' => array_keys($affected),
        ];

        return $details ? $result + ['sample' => $sample] : $result;
    }

    /** `courses` is a set while tallying, collapsed to a count by finalizeBucket(). */
    private function emptyBucket(): array
    {
        return ['sessions' => 0, 'courses' => [], 'by_campus' => [], 'duration_diff_minutes_buckets' => []];
    }

    /** @param array<string, mixed> $bucket
     * @return array<string, mixed> */
    private function finalizeBucket(array $bucket): array
    {
        $bucket['courses'] = count($bucket['courses']);

        return $bucket;
    }

    /** @param list<int> $courseIds
     * @return array<string, true> keyed "scId|Y-m-d|HH:MM" */
    private function loadMakeupKeys(array $courseIds): array
    {
        $rows = DB::table('schedules')
            ->whereIn('student_course_id', $courseIds)
            ->where('type', 'extra')
            ->select(['student_course_id', 'schedule_date', 'start_time'])
            ->get();

        $keys = [];
        foreach ($rows as $r) {
            $keys[$r->student_course_id . '|' . substr((string) $r->schedule_date, 0, 10)
                . '|' . substr((string) $r->start_time, 0, 5)] = true;
        }

        return $keys;
    }

    /**
     * B2 — how far the existing minute-aware ledger encoding has actually been used.
     *
     * @param  list<int>  $courseIds
     * @param  array<int, array<string,int>>  $aggregates
     * @return array<string, mixed>
     */
    private function buildLedgerAdoption(array $courseIds, array $aggregates): array
    {
        $bySource = [];
        $byEventType = [];
        if ($courseIds !== []) {
            $bySource = DB::table('session_deduction_ledger')
                ->whereIn('student_class_id', $courseIds)
                ->select('source', DB::raw('COUNT(*) as c'))
                ->groupBy('source')
                ->pluck('c', 'source')
                ->map(static fn ($v) => (int) $v)
                ->all();

            $byEventType = DB::table('session_deduction_ledger')
                ->whereIn('student_class_id', $courseIds)
                ->select('event_type', DB::raw('COUNT(*) as c'))
                ->groupBy('event_type')
                ->pluck('c', 'event_type')
                ->map(static fn ($v) => (int) $v)
                ->all();
        }

        $rowsWithMinutes = 0;
        $coursesWithMinutes = 0;
        $coursesPartial = 0;
        $netZero = 0;
        $netNonZero = 0;

        foreach ($aggregates as $agg) {
            $rowsWithMinutes += $agg['rows_with_minutes'];
            if ($agg['rows_with_minutes'] > 0) {
                $coursesWithMinutes++;
            }
            if ($agg['partial_rows'] > 0) {
                $coursesPartial++;
            }
            if ($agg['reverse_rows'] > 0) {
                if ($agg['net_minutes'] === 0) {
                    $netZero++;
                } else {
                    $netNonZero++;
                }
            }
        }

        return [
            'ledger_rows_with_minutes_set' => $rowsWithMinutes,
            'courses_with_minutes_set' => $coursesWithMinutes,
            'courses_with_minutes_ne_per_session' => $coursesPartial,
            'by_source' => $bySource,
            'by_event_type' => $byEventType,
            'courses_with_reverse_net_minutes_zero' => $netZero,
            'courses_with_reverse_net_minutes_nonzero' => $netNonZero,
        ];
    }

    /** Mirrors StudentClass::perSessionMinutes(), legacy fallback included. */
    private function contractSessionMinutes(object $course): int
    {
        $dur = (int) ($course->SessionDuration ?? 0);

        return $dur >= 1 ? $dur : self::LEGACY_ENGINE_FALLBACK_MINUTES;
    }

    /** Mirrors SessionDeductionService::durationMinutes() (cross-midnight safe). */
    private function durationMinutes(mixed $start, mixed $end): int
    {
        try {
            $s = Carbon::createFromFormat('H:i', substr((string) $start, 0, 5));
            $e = Carbon::createFromFormat('H:i', substr((string) $end, 0, 5));
            $d = $s->diffInMinutes($e, false);
            if ($d <= 0) {
                $d += 24 * 60;
            }

            return (int) $d;
        } catch (\Throwable $e) {
            return 0;
        }
    }

    private function bucketLabel(int $diffMinutes): string
    {
        return match (true) {
            $diffMinutes <= 15 => '1-15',
            $diffMinutes <= 30 => '16-30',
            $diffMinutes <= 60 => '31-60',
            $diffMinutes <= 120 => '61-120',
            default => '121+',
        };
    }
}
