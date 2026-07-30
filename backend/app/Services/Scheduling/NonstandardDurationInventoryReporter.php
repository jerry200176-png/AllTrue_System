<?php

namespace App\Services\Scheduling;

use Carbon\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * RFC_NONSTANDARD_SESSION_DURATION_BILLING Phase 0A — read-only production/staging
 * inventory. Every method here issues SELECT-only queries. Nothing in this class
 * (or the `sessions:report-nonstandard-duration` command that wraps it) may call
 * save()/update()/insert()/delete(), dispatch a job, or invoke
 * SessionDeductionService/PackageDeductionService. This is a diagnostic report,
 * not a repair tool — see RFC Part B "Safety requirements".
 *
 * The per-session "is this a mismatch" and "is this a makeup" logic deliberately
 * mirrors SessionDeductionService::resolvePartialMakeupMinutes() and
 * StudentClass::perSessionMinutes() exactly (same fallback constant, same makeup
 * matching key) so this report answers "how would the real engine treat this
 * course today", not a second, possibly-diverging definition.
 */
final class NonstandardDurationInventoryReporter
{
    /** Mirrors StudentClass::DEFAULT_SESSION_MINUTES. */
    private const DEFAULT_SESSION_MINUTES = 60;

    /** Only these ClassSession statuses represent something that actually happened. */
    private const HAPPENED_STATUSES = ['completed', 'attended', 'late'];

    /**
     * @return array<string, mixed>
     */
    public function build(?int $campusId, ?int $studentClassId, int $limit, bool $details): array
    {
        $courseIds = $this->loadScopedCourseIds($campusId, $studentClassId, $limit);
        $courses = $this->loadCourses($courseIds);
        $campusByStudentId = $this->loadCampusByStudentId(array_values(array_unique(
            array_map(static fn ($c) => (int) $c->StudentID, $courses)
        )));

        $b1 = $this->buildContractScheduleMismatch($courses, $campusByStudentId, $details);
        $b2 = $this->buildMinuteLedgerAdoption($courseIds);
        $b3 = $this->buildExistingOverage($courses, $details);
        $b4 = $this->buildRemainingMinutesConsistency($courses, $b3['per_course']);
        $b5 = $this->buildCoursePackageExposure($courses, $b1['affected_course_ids'], $b2['courses_with_minutes_set_ids']);
        $b6 = $this->buildMutableDefaultRisk();

        unset($b3['per_course'], $b1['affected_course_ids'], $b2['courses_with_minutes_set_ids']);

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
            'b1_contract_schedule_mismatch' => $b1,
            'b2_minute_ledger_adoption' => $b2,
            'b3_existing_overage' => $b3,
            'b4_remaining_minutes_consistency' => $b4,
            'b5_course_package_exposure' => $b5,
            'b6_mutable_default_risk' => $b6,
        ];
    }

    /** @return list<int> */
    private function loadScopedCourseIds(?int $campusId, ?int $studentClassId, int $limit): array
    {
        $q = DB::table('StudentClass as sc')
            ->whereNotNull('sc.SessionDuration')
            ->where('sc.SessionDuration', '>=', 1)
            ->where(function ($w) {
                $w->where('sc.ScheduleMode', 'count')->orWhereNull('sc.ScheduleMode');
            });

        if ($studentClassId !== null) {
            $q->where('sc.ID', $studentClassId);
        }
        if ($campusId !== null) {
            $q->join('Student as s', 's.id', '=', 'sc.StudentID')
                ->where('s.CampusID', $campusId);
        }

        return $q->orderBy('sc.ID')->limit(max(1, $limit))->pluck('sc.ID')->map(fn ($v) => (int) $v)->all();
    }

    /** @param list<int> $courseIds
     * @return list<object> */
    private function loadCourses(array $courseIds): array
    {
        if ($courseIds === []) {
            return [];
        }

        return DB::table('StudentClass')
            ->whereIn('ID', $courseIds)
            ->select([
                'ID', 'StudentID', 'SessionCount', 'SessionDuration', 'RemainingSessions',
                'UsedSessions', 'RemainingMinutes', 'PurchasedMinutes', 'PackageID',
            ])
            ->get()
            ->all();
    }

    /** @param list<int> $studentIds
     * @return array<int,int> studentId => campusId */
    private function loadCampusByStudentId(array $studentIds): array
    {
        if ($studentIds === []) {
            return [];
        }

        return DB::table('Student')
            ->whereIn('id', $studentIds)
            ->pluck('CampusID', 'id')
            ->map(fn ($v) => (int) $v)
            ->all();
    }

    /**
     * @param  list<object>  $courses
     * @param  array<int,int>  $campusByStudentId
     * @return array<string,mixed>
     */
    private function buildContractScheduleMismatch(array $courses, array $campusByStudentId, bool $details): array
    {
        $courseIds = array_map(static fn ($c) => (int) $c->ID, $courses);
        $makeupKeys = $this->loadMakeupKeys($courseIds);

        $mismatchedSessions = 0;
        $affectedCourseIds = [];
        $byCampus = [];
        $buckets = [];
        $sample = [];

        if ($courseIds !== []) {
            $sessions = DB::table('ClassSession')
                ->whereIn('StudentClassID', $courseIds)
                ->whereIn('Status', self::HAPPENED_STATUSES)
                ->select(['StudentClassID', 'SessionDate', 'StartTime', 'EndTime', 'id'])
                ->get();

            $courseById = [];
            foreach ($courses as $c) {
                $courseById[(int) $c->ID] = $c;
            }

            foreach ($sessions as $s) {
                $scId = (int) $s->StudentClassID;
                $course = $courseById[$scId] ?? null;
                if (!$course) {
                    continue;
                }
                $perSession = $this->perSessionMinutes($course);
                $key = $scId . '|' . $s->SessionDate . '|' . substr((string) $s->StartTime, 0, 5);
                if (isset($makeupKeys[$key])) {
                    continue; // excluded per RFC: makeup (schedules.type='extra') is not a "normal" mismatch
                }
                $actual = $this->durationMinutes($s->StartTime, $s->EndTime);
                if ($actual === $perSession) {
                    continue;
                }

                $mismatchedSessions++;
                $affectedCourseIds[$scId] = true;
                $studentId = (int) $course->StudentID;
                $campusId = $campusByStudentId[$studentId] ?? 0;
                $byCampus[$campusId] = ($byCampus[$campusId] ?? 0) + 1;

                $diff = abs($actual - $perSession);
                $bucketKey = $this->bucketLabel($diff);
                $buckets[$bucketKey] = ($buckets[$bucketKey] ?? 0) + 1;

                if ($details && count($sample) < 50) {
                    // Limited identifiers only (StudentClassID/ClassSessionID/CampusID) — never
                    // student name/phone/RFID etc, per "不得輸出不必要的個資至 CI log".
                    $sample[] = [
                        'student_class_id' => $scId,
                        'class_session_id' => (int) $s->id,
                        'campus_id' => $campusId,
                        'session_duration_minutes' => $perSession,
                        'actual_duration_minutes' => $actual,
                        'diff_minutes' => $diff,
                    ];
                }
            }
        }

        $result = [
            'courses_with_session_duration_set' => count($courses),
            'mismatched_normal_sessions' => $mismatchedSessions,
            'affected_courses' => count($affectedCourseIds),
            'by_campus' => $byCampus,
            'duration_diff_minutes_buckets' => $buckets,
            'affected_course_ids' => array_keys($affectedCourseIds),
        ];
        if ($details) {
            $result['sample'] = $sample;
        }

        return $result;
    }

    /** @param list<int> $courseIds
     * @return array<string,true> "studentClassId|date|HH:MM" => true, for schedules.type='extra' rows */
    private function loadMakeupKeys(array $courseIds): array
    {
        if ($courseIds === []) {
            return [];
        }

        $rows = DB::table('schedules')
            ->whereIn('student_course_id', $courseIds)
            ->where('type', 'extra')
            ->select(['student_course_id', 'schedule_date', 'start_time'])
            ->get();

        $keys = [];
        foreach ($rows as $r) {
            $date = substr((string) $r->schedule_date, 0, 10);
            $time = substr((string) $r->start_time, 0, 5);
            $keys[$r->student_course_id . '|' . $date . '|' . $time] = true;
        }

        return $keys;
    }

    /** @param list<int> $courseIds
     * @return array<string,mixed> */
    private function buildMinuteLedgerAdoption(array $courseIds): array
    {
        if ($courseIds === []) {
            return [
                'ledger_rows_with_minutes_set' => 0,
                'courses_with_minutes_set' => 0,
                'courses_with_minutes_ne_per_session' => 0,
                'by_source' => [],
                'by_event_type' => [],
                'courses_with_reverse_net_zero' => 0,
                'courses_with_reverse_net_nonzero' => 0,
                'courses_with_minutes_set_ids' => [],
            ];
        }

        $ledgerRowsWithMinutes = DB::table('session_deduction_ledger')
            ->whereIn('student_class_id', $courseIds)
            ->whereNotNull('minutes')
            ->count();

        $coursesWithMinutesSet = DB::table('session_deduction_ledger')
            ->whereIn('student_class_id', $courseIds)
            ->whereNotNull('minutes')
            ->distinct()
            ->pluck('student_class_id')
            ->map(fn ($v) => (int) $v)
            ->all();

        $perSessionCase = 'CASE WHEN sc.SessionDuration >= 1 THEN sc.SessionDuration ELSE '
            . self::DEFAULT_SESSION_MINUTES . ' END';
        $coursesWithPartial = DB::table('session_deduction_ledger as l')
            ->join('StudentClass as sc', 'sc.ID', '=', 'l.student_class_id')
            ->whereIn('l.student_class_id', $courseIds)
            ->whereNotNull('l.minutes')
            ->whereRaw("l.minutes <> {$perSessionCase}")
            ->distinct()
            ->pluck('l.student_class_id')
            ->count();

        $bySource = DB::table('session_deduction_ledger')
            ->whereIn('student_class_id', $courseIds)
            ->select('source', DB::raw('COUNT(*) as c'))
            ->groupBy('source')
            ->pluck('c', 'source')
            ->map(fn ($v) => (int) $v)
            ->all();

        $byEventType = DB::table('session_deduction_ledger')
            ->whereIn('student_class_id', $courseIds)
            ->select('event_type', DB::raw('COUNT(*) as c'))
            ->groupBy('event_type')
            ->pluck('c', 'event_type')
            ->map(fn ($v) => (int) $v)
            ->all();

        $netRows = DB::table('session_deduction_ledger')
            ->whereIn('student_class_id', $courseIds)
            ->where('event_type', 'reverse')
            ->select('student_class_id')
            ->distinct()
            ->pluck('student_class_id');

        $reverseNetZero = 0;
        $reverseNetNonZero = 0;
        foreach ($netRows as $scId) {
            $net = (int) (DB::table('session_deduction_ledger')
                ->where('student_class_id', $scId)
                ->selectRaw("SUM(CASE WHEN event_type='deduct' THEN 1 WHEN event_type='reverse' THEN -1 ELSE 0 END) as net")
                ->value('net') ?? 0);
            if ($net === 0) {
                $reverseNetZero++;
            } else {
                $reverseNetNonZero++;
            }
        }

        return [
            'ledger_rows_with_minutes_set' => $ledgerRowsWithMinutes,
            'courses_with_minutes_set' => count($coursesWithMinutesSet),
            'courses_with_minutes_ne_per_session' => $coursesWithPartial,
            'by_source' => $bySource,
            'by_event_type' => $byEventType,
            'courses_with_reverse_net_zero' => $reverseNetZero,
            'courses_with_reverse_net_nonzero' => $reverseNetNonZero,
            'courses_with_minutes_set_ids' => $coursesWithMinutesSet,
        ];
    }

    /**
     * @param  list<object>  $courses
     * @return array<string,mixed> (includes internal 'per_course' key consumed by
     *                              buildRemainingMinutesConsistency, stripped before output)
     */
    private function buildExistingOverage(array $courses, bool $details): array
    {
        $perCourse = [];
        $affected = 0;
        $totalUncovered = 0;
        $largest = [];

        foreach ($courses as $c) {
            $scId = (int) $c->ID;
            $perSession = $this->perSessionMinutes($c);
            $purchasedMinutes = max(0, (int) ($c->SessionCount ?? 0)) * $perSession;

            $net = (int) (DB::table('session_deduction_ledger')
                ->where('student_class_id', $scId)
                ->selectRaw(
                    "SUM(CASE WHEN event_type = 'deduct' THEN COALESCE(minutes, {$perSession}) "
                    . "ELSE -COALESCE(minutes, {$perSession}) END) as net"
                )
                ->value('net') ?? 0);

            $uncovered = max(0, $net - $purchasedMinutes);
            $perCourse[$scId] = [
                'net_deducted_minutes' => $net,
                'purchased_minutes' => $purchasedMinutes,
                'uncovered_minutes' => $uncovered,
            ];

            if ($uncovered > 0) {
                $affected++;
                $totalUncovered += $uncovered;
                $largest[] = ['student_class_id' => $scId, 'uncovered_minutes' => $uncovered];
            }
        }

        usort($largest, static fn ($a, $b) => $b['uncovered_minutes'] <=> $a['uncovered_minutes']);

        $result = [
            'affected_course_count' => $affected,
            'total_uncovered_minutes' => $totalUncovered,
            'per_course' => $perCourse,
        ];
        if ($details) {
            $result['largest_uncovered_cases'] = array_slice($largest, 0, 20);
        }

        return $result;
    }

    /**
     * @param  list<object>  $courses
     * @param  array<int,array{net_deducted_minutes:int,purchased_minutes:int,uncovered_minutes:int}>  $perCourseOverage
     * @return array<string,mixed>
     */
    private function buildRemainingMinutesConsistency(array $courses, array $perCourseOverage): array
    {
        $checked = 0;
        $mismatch = 0;
        $fractional = 0;
        $cappedAtZeroWithHiddenOverage = 0;

        foreach ($courses as $c) {
            $scId = (int) $c->ID;
            $perSession = $this->perSessionMinutes($c);
            $persisted = $c->RemainingMinutes;
            if ($persisted === null) {
                continue;
            }
            $checked++;
            $persisted = (int) $persisted;

            $overage = $perCourseOverage[$scId] ?? null;
            if ($overage) {
                $ledgerDerived = max(0, min(
                    $overage['purchased_minutes'],
                    $overage['purchased_minutes'] - $overage['net_deducted_minutes']
                ));
                if ($ledgerDerived !== $persisted) {
                    $mismatch++;
                }
                if ($persisted === 0 && $overage['uncovered_minutes'] > 0) {
                    $cappedAtZeroWithHiddenOverage++;
                }
            }

            if ($perSession >= 1 && ($persisted % $perSession !== 0)) {
                $fractional++;
            }
        }

        return [
            'courses_checked' => $checked,
            'persisted_vs_ledger_derived_mismatch_count' => $mismatch,
            'fractional_balance_count' => $fractional,
            'capped_at_zero_with_hidden_overage_count' => $cappedAtZeroWithHiddenOverage,
        ];
    }

    /**
     * @param  list<object>  $courses
     * @param  list<int>  $mismatchCourseIds
     * @param  list<int>  $partialMinuteCourseIds
     */
    private function buildCoursePackageExposure(array $courses, array $mismatchCourseIds, array $partialMinuteCourseIds): array
    {
        $flagged = array_values(array_unique(array_merge($mismatchCourseIds, $partialMinuteCourseIds)));
        if ($flagged === []) {
            return [
                'nonstandard_or_partial_courses_in_package' => 0,
                'packages_with_drift_risk' => 0,
            ];
        }

        $courseById = [];
        foreach ($courses as $c) {
            $courseById[(int) $c->ID] = $c;
        }

        $inPackageCount = 0;
        $packageIds = [];
        foreach ($flagged as $scId) {
            $course = $courseById[$scId] ?? null;
            $pkgId = (int) ($course->PackageID ?? 0);
            if ($pkgId > 0) {
                $inPackageCount++;
                $packageIds[$pkgId] = true;
            }
        }

        return [
            // This only reports exposure — it never reads/writes package_session_ledger
            // or CoursePackage rows (D4: actual-duration courses are excluded from
            // CoursePackage in v1; this metric exists purely to size that constraint's
            // real-world impact against TD-059).
            'nonstandard_or_partial_courses_in_package' => $inPackageCount,
            'packages_with_drift_risk' => count($packageIds),
        ];
    }

    /** @return array<string,mixed> */
    private function buildMutableDefaultRisk(): array
    {
        // Checked as of this RFC's investigation: App\Models\Campus::$fillable (no
        // duration/lesson-length field), config/*.php (no session/lesson-minutes
        // setting), StudentClass (SessionDuration is per-course, not a system/branch
        // default). No settings table or config key represents a branch- or
        // system-level "standard lesson minutes" today. This is a code-inspection
        // finding, not a query result — re-verify if the schema changes.
        return [
            'authoritative_branch_or_system_setting_found' => false,
            'note' => 'No branch/system-level standard-lesson-minutes setting exists today '
                . '(checked: App\\Models\\Campus fillable, config/*.php, StudentClass schema). '
                . 'SessionDuration is set per-course only. If Founder decision D1 introduces a '
                . 'branch/system default (RFC §10 A1), its owner and resolution path must be '
                . 're-checked against this finding before Phase 1 implementation.',
            'checked_locations' => [
                'app/Models/Campus.php ($fillable)',
                'backend/config/*.php',
                'StudentClass migrations (SessionDuration is per-course)',
            ],
        ];
    }

    private function perSessionMinutes(object $course): int
    {
        $dur = (int) ($course->SessionDuration ?? 0);

        return $dur >= 1 ? $dur : self::DEFAULT_SESSION_MINUTES;
    }

    /** Mirrors SessionDeductionService::durationMinutes() (StartTime/EndTime -> minutes, cross-midnight safe). */
    private function durationMinutes($start, $end): int
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
        if ($diffMinutes <= 15) {
            return '1-15';
        }
        if ($diffMinutes <= 30) {
            return '16-30';
        }
        if ($diffMinutes <= 60) {
            return '31-60';
        }
        if ($diffMinutes <= 120) {
            return '61-120';
        }

        return '121+';
    }
}
