<?php

namespace App\Services\Scheduling;

use Carbon\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * ADR-006 Phase 0 — read-only prepaid session horizon evidence report.
 * Never writes. Answers the seven questions in ADR-006 §9.2.
 */
final class PrepaidHorizonPhase0Reporter
{
    public function __construct(
        private ?ScheduleCommitmentClassifier $classifier = null,
        private ?CommitmentOccurrenceExpander $expander = null,
        private ?ForwardSessionGeneratorBridge $fsgBridge = null,
    ) {
        $this->classifier = $classifier ?? new ScheduleCommitmentClassifier();
        $this->expander = $expander ?? new CommitmentOccurrenceExpander();
        $this->fsgBridge = $fsgBridge ?? new ForwardSessionGeneratorBridge();
    }

    /**
     * @return array<string,mixed>
     */
    public function build(?int $branchId = null, ?Carbon $today = null, int $limit = 5000): array
    {
        $today = ($today ?? Carbon::today('Asia/Taipei'))->copy()->startOfDay();
        $horizon7 = $today->copy()->addDays(7);
        $horizon28 = $today->copy()->addDays(28);

        $courses = $this->loadCandidateCourses($branchId, $limit);
        $courseIds = array_map(static fn ($r) => (int) $r->ID, $courses);

        $sessionsByCourse = $this->loadRecentSessions($courseIds);
        $futureSessionsByCourse = $this->loadFutureSessions($courseIds, $today, $horizon28);
        $packageRemaining = $this->loadPackageRemaining(
            array_values(array_unique(array_filter(array_map(
                static fn ($r) => (int) ($r->PackageID ?? 0),
                $courses
            ))))
        );

        $buckets = [];
        $reasonCounts = [];
        $classifications = [];
        $q1 = ['courses' => 0, 'missing_occurrences_7d' => 0, 'missing_occurrences_28d' => 0];
        $q2 = [
            CommitmentReasonCodes::INFO_FLEXIBLE_NO_COMMITMENT => 0,
            CommitmentReasonCodes::BLOCK_COMMITMENT_INCOMPLETE => 0,
            CommitmentReasonCodes::LEGACY_INFERRED_CANDIDATE => 0,
            CommitmentReasonCodes::BLOCK_COMMITMENT_CONFLICT => 0,
        ];
        $guessRequired = [];
        $fsg = [
            'plan' => ['explicit_commitment' => 0, 'legacy_inferred_candidate' => 0, 'other' => 0],
            'skip_by_reason' => [],
            'plan_course_ids_sample' => [],
        ];
        $poolDemand = []; // package_id => expected covered demand in 28d from explicit members
        $explicitGapSamples = [];

        foreach ($courses as $row) {
            $scId = (int) $row->ID;
            $recent = $sessionsByCourse[$scId] ?? [];
            $classified = $this->classifier->classify($row, $recent, $today);
            $class = $classified['classification'];
            $reason = $classified['primary_reason'];
            $bucket = $classified['bucket'];

            $classifications[$class] = ($classifications[$class] ?? 0) + 1;
            $buckets[$bucket] = ($buckets[$bucket] ?? 0) + 1;
            $reasonCounts[$reason] = ($reasonCounts[$reason] ?? 0) + 1;

            if (!empty($classified['guess_required'])) {
                $guessRequired[$reason] = ($guessRequired[$reason] ?? 0) + 1;
            }

            // Q2: positive remaining / pool entitlement without explicit commitment
            $pkgId = (int) ($row->PackageID ?? 0);
            $entitled = (int) ($row->RemainingSessions ?? 0) > 0
                || ($pkgId > 0 && (int) ($packageRemaining[$pkgId] ?? 0) > 0);
            if ($entitled && $class !== CommitmentReasonCodes::CLASS_EXPLICIT) {
                if (isset($q2[$reason])) {
                    $q2[$reason]++;
                } elseif ($class === CommitmentReasonCodes::CLASS_FLEXIBLE) {
                    $q2[CommitmentReasonCodes::INFO_FLEXIBLE_NO_COMMITMENT]++;
                } elseif ($class === CommitmentReasonCodes::CLASS_LEGACY) {
                    $q2[CommitmentReasonCodes::LEGACY_INFERRED_CANDIDATE]++;
                } elseif ($class === CommitmentReasonCodes::CLASS_CONFLICT) {
                    $q2[CommitmentReasonCodes::BLOCK_COMMITMENT_CONFLICT]++;
                } elseif ($class === CommitmentReasonCodes::CLASS_INCOMPLETE) {
                    $q2[CommitmentReasonCodes::BLOCK_COMMITMENT_INCOMPLETE]++;
                }
            }

            $future = $futureSessionsByCourse[$scId] ?? [];
            $futureKeys = [];
            foreach ($future as $fs) {
                $futureKeys[substr((string) $fs->SessionDate, 0, 10) . '|' . substr((string) $fs->StartTime, 0, 5)] = true;
            }

            if ($class === CommitmentReasonCodes::CLASS_EXPLICIT) {
                $contractStart = $row->StartDate ? Carbon::parse((string) $row->StartDate) : null;
                $contractEnd = $row->EndDate ? Carbon::parse((string) $row->EndDate) : null;
                // Inclusive today..through per ADR-006 §8.7
                $occ28 = $this->expander->expand(
                    $classified['contract_slots'],
                    $today,
                    $horizon28,
                    $contractStart,
                    $contractEnd,
                );
                $occ7 = $this->expander->expand(
                    $classified['contract_slots'],
                    $today,
                    $horizon7,
                    $contractStart,
                    $contractEnd,
                );

                $missing7 = 0;
                foreach ($occ7 as $o) {
                    $k = $o['date'] . '|' . $o['start_hm'];
                    if (!isset($futureKeys[$k])) {
                        $missing7++;
                    }
                }
                $missing28 = 0;
                $coveredDemand = 0;
                foreach ($occ28 as $o) {
                    $k = $o['date'] . '|' . $o['start_hm'];
                    if (!isset($futureKeys[$k])) {
                        $missing28++;
                    }
                    $coveredDemand++;
                }

                if ($missing7 > 0 || $missing28 > 0) {
                    $q1['courses']++;
                    $q1['missing_occurrences_7d'] += $missing7;
                    $q1['missing_occurrences_28d'] += $missing28;
                    $buckets['explicit_materialization_gap'] = ($buckets['explicit_materialization_gap'] ?? 0) + 1;
                    if (count($explicitGapSamples) < 20) {
                        $explicitGapSamples[] = [
                            'student_class_id' => $scId,
                            'campus_id' => (int) ($row->CampusID ?? 0),
                            'package_id' => $pkgId ?: null,
                            'missing_7d' => $missing7,
                            'missing_28d' => $missing28,
                            'fingerprint' => $classified['commitment_fingerprint'],
                        ];
                    }
                } else {
                    $buckets['explicit_healthy'] = ($buckets['explicit_healthy'] ?? 0) + 1;
                }

                if ($pkgId > 0) {
                    $poolDemand[$pkgId] = ($poolDemand[$pkgId] ?? 0) + $coveredDemand;
                }
            }

            // FSG bridge (read-only plan)
            $fsgPlan = $this->fsgBridge->planReadOnly($scId, $today);
            if ($fsgPlan['status'] === 'plan') {
                if ($class === CommitmentReasonCodes::CLASS_EXPLICIT) {
                    $fsg['plan']['explicit_commitment']++;
                } elseif ($class === CommitmentReasonCodes::CLASS_LEGACY) {
                    $fsg['plan']['legacy_inferred_candidate']++;
                } else {
                    $fsg['plan']['other']++;
                }
                if (count($fsg['plan_course_ids_sample']) < 15) {
                    $fsg['plan_course_ids_sample'][] = [
                        'student_class_id' => $scId,
                        'classification' => $class,
                    ];
                }
            } else {
                $r = $fsgPlan['reason'] ?: 'skip';
                $fsg['skip_by_reason'][$r] = ($fsg['skip_by_reason'][$r] ?? 0) + 1;
            }
        }

        // Q3 pool shortage
        $poolShortage = [];
        $poolsBlocked = 0;
        foreach ($poolDemand as $pkgId => $demand) {
            $avail = (int) ($packageRemaining[$pkgId] ?? 0);
            $uncovered = max(0, $demand - $avail);
            if ($uncovered > 0) {
                $poolsBlocked++;
                $poolShortage[] = [
                    'package_id' => $pkgId,
                    'demand_occurrences_28d' => $demand,
                    'pool_remaining' => $avail,
                    'uncovered' => $uncovered,
                    'ensure_block' => CommitmentReasonCodes::BLOCK_POOL_SHORTAGE,
                ];
                $buckets['shared_pool_shortage'] = ($buckets['shared_pool_shortage'] ?? 0) + 1;
            }
        }

        $manual = $this->measureManualBackfills($today, $branchId);

        $adapterAssessment = $this->assessStudentClassAdapter($classifications, $q1, $guessRequired);

        return [
            'meta' => [
                'report' => 'adr006-phase0-prepaid-horizon',
                'rule_version' => CommitmentReasonCodes::RULE_VERSION,
                'as_of' => $today->toDateString(),
                'timezone' => 'Asia/Taipei',
                'horizon_days' => [7, 28],
                'branch_id' => $branchId,
                'courses_scanned' => count($courses),
                'read_only' => true,
                'writes' => false,
                'manual_backfill_definition' => 'ClassSession rows with created_at in [today-30d,today], Note not containing "#1062", Status not cancelled/voided, and created_at < SessionDate+StartTime (created before class start)',
            ],
            'questions' => [
                'q1_explicit_materialization_gap' => $q1 + ['samples' => $explicitGapSamples],
                'q2_no_explicit_commitment_split' => $q2,
                'q3_shared_pool_coverage_gap' => [
                    'pools_with_shortage' => $poolsBlocked,
                    'pools' => array_slice($poolShortage, 0, 50),
                ],
                'q4_reason_code_distribution' => $reasonCounts,
                'q5_forward_session_generator' => $fsg,
                'q6_guess_required_if_auto' => $guessRequired,
                'q7_manual_backfill' => $manual,
            ],
            'buckets' => $buckets,
            'classifications' => $classifications,
            'student_class_adapter_assessment' => $adapterAssessment,
        ];
    }

    /**
     * @return list<object>
     */
    private function loadCandidateCourses(?int $branchId, int $limit): array
    {
        $q = DB::table('StudentClass as sc')
            ->join('Student as s', 's.id', '=', 'sc.StudentID')
            ->leftJoin('course_packages as cp', 'cp.id', '=', 'sc.PackageID')
            ->where(function ($w) {
                $w->where('sc.Stop', 0)->orWhereNull('sc.Stop');
            })
            ->whereRaw("LOWER(COALESCE(sc.ScheduleMode,'count')) = 'count'")
            ->where(function ($w) {
                $w->where('sc.RemainingSessions', '>', 0)
                    ->orWhere(function ($p) {
                        $p->whereNotNull('sc.PackageID')
                            ->where('sc.PackageID', '>', 0)
                            ->where('cp.remaining_sessions', '>', 0)
                            ->where(function ($e) {
                                $e->where('cp.stop', 0)->orWhereNull('cp.stop');
                            });
                    });
            })
            ->orderBy('sc.ID')
            ->limit(max(1, $limit))
            ->select([
                'sc.ID',
                'sc.StudentID',
                'sc.TeacherID',
                'sc.SubjectID',
                'sc.ScheduleMode',
                'sc.RemainingSessions',
                'sc.SessionCount',
                'sc.SessionDuration',
                'sc.StartDate',
                'sc.EndDate',
                'sc.Stop',
                'sc.PackageID',
                'sc.week',
                'sc.time',
                'sc.week1',
                'sc.time1',
                'sc.week2',
                'sc.time2',
                'sc.week3',
                'sc.time3',
                'sc.week4',
                'sc.time4',
                'sc.week5',
                'sc.time5',
                'sc.week6',
                'sc.time6',
                's.CampusID',
            ]);

        if ($branchId !== null && $branchId > 0) {
            $q->where('s.CampusID', $branchId);
        }

        return $q->get()->all();
    }

    /**
     * @param  list<int>  $courseIds
     * @return array<int, list<object>>
     */
    private function loadRecentSessions(array $courseIds): array
    {
        if ($courseIds === []) {
            return [];
        }
        $rows = DB::table('ClassSession')
            ->whereIn('StudentClassID', $courseIds)
            ->whereRaw("LOWER(Status) NOT IN ('cancelled','voided')")
            ->orderByDesc('SessionDate')
            ->orderByDesc('id')
            ->get(['id', 'StudentClassID', 'SessionDate', 'StartTime', 'EndTime', 'Status']);

        $by = [];
        foreach ($rows as $r) {
            $sc = (int) $r->StudentClassID;
            if (!isset($by[$sc])) {
                $by[$sc] = [];
            }
            if (count($by[$sc]) >= 6) {
                continue;
            }
            $by[$sc][] = $r;
        }

        return $by;
    }

    /**
     * @param  list<int>  $courseIds
     * @return array<int, list<object>>
     */
    private function loadFutureSessions(array $courseIds, Carbon $today, Carbon $through): array
    {
        if ($courseIds === []) {
            return [];
        }
        $rows = DB::table('ClassSession')
            ->whereIn('StudentClassID', $courseIds)
            ->whereDate('SessionDate', '>=', $today->toDateString())
            ->whereDate('SessionDate', '<=', $through->toDateString())
            ->whereRaw("LOWER(Status) NOT IN ('cancelled','voided')")
            ->get(['StudentClassID', 'SessionDate', 'StartTime', 'Status']);

        $by = [];
        foreach ($rows as $r) {
            $by[(int) $r->StudentClassID][] = $r;
        }

        return $by;
    }

    /**
     * @param  list<int>  $packageIds
     * @return array<int,int>
     */
    private function loadPackageRemaining(array $packageIds): array
    {
        if ($packageIds === []) {
            return [];
        }
        $rows = DB::table('course_packages')
            ->whereIn('id', $packageIds)
            ->get(['id', 'remaining_sessions']);
        $out = [];
        foreach ($rows as $r) {
            $out[(int) $r->id] = (int) $r->remaining_sessions;
        }

        return $out;
    }

    /**
     * @return array<string,mixed>
     */
    private function measureManualBackfills(Carbon $today, ?int $branchId): array
    {
        $since = $today->copy()->subDays(30)->toDateString();
        $q = DB::table('ClassSession as cs')
            ->join('StudentClass as sc', 'sc.ID', '=', 'cs.StudentClassID')
            ->join('Student as s', 's.id', '=', 'sc.StudentID')
            ->whereDate('cs.created_at', '>=', $since)
            ->whereRaw("LOWER(cs.Status) NOT IN ('cancelled','voided')")
            ->where(function ($w) {
                $w->whereNull('cs.Note')
                    ->orWhere('cs.Note', 'not like', '%#1062%');
            });
        if ($branchId !== null && $branchId > 0) {
            $q->where('s.CampusID', $branchId);
        }

        $rows = $q->get([
            'cs.id',
            'cs.StudentClassID',
            'cs.SessionDate',
            'cs.StartTime',
            'cs.created_at',
            's.CampusID',
        ]);

        $manual = 0;
        $hoursBeforeClass = [];
        foreach ($rows as $r) {
            if ($r->created_at === null || $r->SessionDate === null || $r->StartTime === null) {
                continue;
            }
            try {
                $created = Carbon::parse((string) $r->created_at, 'Asia/Taipei');
                $classAt = Carbon::parse(
                    substr((string) $r->SessionDate, 0, 10) . ' ' . substr((string) $r->StartTime, 0, 8),
                    'Asia/Taipei'
                );
            } catch (\Throwable) {
                continue;
            }
            if ($created->gte($classAt)) {
                continue; // not "before class"
            }
            $manual++;
            $hoursBeforeClass[] = round($created->diffInMinutes($classAt) / 60, 2);
        }

        sort($hoursBeforeClass);
        $n = count($hoursBeforeClass);
        $median = $n === 0 ? null : $hoursBeforeClass[(int) floor(($n - 1) / 2)];
        $p90 = $n === 0 ? null : $hoursBeforeClass[(int) floor(($n - 1) * 0.9)];

        return [
            'window_days' => 30,
            'manual_backfill_count' => $manual,
            'median_hours_before_class' => $median,
            'p90_hours_before_class' => $p90,
            'within_24h_of_class' => count(array_filter($hoursBeforeClass, static fn ($h) => $h <= 24)),
        ];
    }

    /**
     * @param  array<string,int>  $classifications
     * @param  array<string,mixed>  $q1
     * @param  array<string,int>  $guessRequired
     * @return array<string,mixed>
     */
    private function assessStudentClassAdapter(array $classifications, array $q1, array $guessRequired): array
    {
        $explicit = (int) ($classifications[CommitmentReasonCodes::CLASS_EXPLICIT] ?? 0);
        $legacy = (int) ($classifications[CommitmentReasonCodes::CLASS_LEGACY] ?? 0);
        $conflict = (int) ($classifications[CommitmentReasonCodes::CLASS_CONFLICT] ?? 0);
        $incomplete = (int) ($classifications[CommitmentReasonCodes::CLASS_INCOMPLETE] ?? 0);
        $flexible = (int) ($classifications[CommitmentReasonCodes::CLASS_FLEXIBLE] ?? 0);
        $total = max(1, $explicit + $legacy + $conflict + $incomplete + $flexible);

        $triggersNewTable = $conflict > 0 && ($conflict / $total) > 0.15;

        return [
            'v1_adapter' => 'StudentClass contract fields',
            'permanent_ssot' => false,
            'explicit_share' => round($explicit / $total, 4),
            'legacy_share' => round($legacy / $total, 4),
            'conflict_share' => round($conflict / $total, 4),
            'incomplete_share' => round($incomplete / $total, 4),
            'flexible_share' => round($flexible / $total, 4),
            'phase1_ensure_pilot_candidate_courses' => (int) ($q1['courses'] ?? 0),
            'recommend_new_schedule_commitments_table' => $triggersNewTable,
            'recommend_reason' => $triggersNewTable
                ? 'conflict_share_above_15pct — effective-dated commitment may be required (ADR-006 §6.5)'
                : 'v1_adapter_sufficient_for_explicit_subset_pending_phase1',
            'guess_required_codes' => $guessRequired,
        ];
    }
}
