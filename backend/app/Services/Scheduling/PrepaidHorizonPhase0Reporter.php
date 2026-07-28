<?php

namespace App\Services\Scheduling;

use Carbon\Carbon;
use Illuminate\Support\Facades\DB;

/** ADR-006 Phase 0 — read-only prepaid horizon evidence (never writes). */
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

    /** @return array<string,mixed> */
    public function build(?int $branchId = null, ?Carbon $today = null, int $limit = 5000): array
    {
        $today = ($today ?? Carbon::today('Asia/Taipei'))->copy()->startOfDay();
        $h7 = $today->copy()->addDays(7);
        $h28 = $today->copy()->addDays(28);

        $courses = $this->loadCandidateCourses($branchId, $limit);
        $ids = array_map(static fn ($r) => (int) $r->ID, $courses);
        $recentBy = $this->loadRecentSessions($ids);
        $futureBy = $this->loadFutureSessions($ids, $today, $h28);
        $pkgRem = $this->loadPackageRemaining(array_values(array_unique(array_filter(
            array_map(static fn ($r) => (int) ($r->PackageID ?? 0), $courses)
        ))));

        $buckets = [];
        $reasons = [];
        $classes = [];
        $q1 = ['courses' => 0, 'missing_occurrences_7d' => 0, 'missing_occurrences_28d' => 0];
        $q2 = [
            CommitmentReasonCodes::INFO_FLEXIBLE_NO_COMMITMENT => 0,
            CommitmentReasonCodes::BLOCK_COMMITMENT_INCOMPLETE => 0,
            CommitmentReasonCodes::LEGACY_INFERRED_CANDIDATE => 0,
            CommitmentReasonCodes::BLOCK_COMMITMENT_CONFLICT => 0,
        ];
        $guess = [];
        $fsg = [
            'plan' => ['explicit_commitment' => 0, 'legacy_inferred_candidate' => 0, 'other' => 0],
            'skip_by_reason' => [],
            'plan_course_ids_sample' => [],
        ];
        $poolDemand = [];
        $gapSamples = [];

        foreach ($courses as $row) {
            $scId = (int) $row->ID;
            $c = $this->classifier->classify($row, $recentBy[$scId] ?? [], $today);
            $class = $c['classification'];
            $reason = $c['primary_reason'];
            $bucket = $c['bucket'];
            $classes[$class] = ($classes[$class] ?? 0) + 1;
            $buckets[$bucket] = ($buckets[$bucket] ?? 0) + 1;
            $reasons[$reason] = ($reasons[$reason] ?? 0) + 1;
            if (!empty($c['guess_required'])) {
                $guess[$reason] = ($guess[$reason] ?? 0) + 1;
            }

            $pkgId = (int) ($row->PackageID ?? 0);
            $entitled = (int) ($row->RemainingSessions ?? 0) > 0
                || ($pkgId > 0 && (int) ($pkgRem[$pkgId] ?? 0) > 0);
            if ($entitled && $class !== CommitmentReasonCodes::CLASS_EXPLICIT) {
                $map = [
                    CommitmentReasonCodes::CLASS_FLEXIBLE => CommitmentReasonCodes::INFO_FLEXIBLE_NO_COMMITMENT,
                    CommitmentReasonCodes::CLASS_LEGACY => CommitmentReasonCodes::LEGACY_INFERRED_CANDIDATE,
                    CommitmentReasonCodes::CLASS_CONFLICT => CommitmentReasonCodes::BLOCK_COMMITMENT_CONFLICT,
                    CommitmentReasonCodes::CLASS_INCOMPLETE => CommitmentReasonCodes::BLOCK_COMMITMENT_INCOMPLETE,
                ];
                $k = $map[$class] ?? (isset($q2[$reason]) ? $reason : null);
                if ($k !== null) {
                    $q2[$k]++;
                }
            }

            $futureKeys = [];
            foreach ($futureBy[$scId] ?? [] as $fs) {
                $futureKeys[substr((string) $fs->SessionDate, 0, 10).'|'.substr((string) $fs->StartTime, 0, 5)] = true;
            }

            if ($class === CommitmentReasonCodes::CLASS_EXPLICIT) {
                $cStart = $row->StartDate ? Carbon::parse((string) $row->StartDate) : null;
                $cEnd = $row->EndDate ? Carbon::parse((string) $row->EndDate) : null;
                $occ28 = $this->expander->expand($c['contract_slots'], $today, $h28, $cStart, $cEnd);
                $missing7 = 0;
                $missing28 = 0;
                foreach ($occ28 as $o) {
                    $key = $o['date'].'|'.$o['start_hm'];
                    $absent = !isset($futureKeys[$key]);
                    if ($absent) {
                        $missing28++;
                        if (Carbon::parse($o['date'])->lte($h7)) {
                            $missing7++;
                        }
                    }
                }
                if ($missing7 > 0 || $missing28 > 0) {
                    $q1['courses']++;
                    $q1['missing_occurrences_7d'] += $missing7;
                    $q1['missing_occurrences_28d'] += $missing28;
                    $buckets['explicit_materialization_gap'] = ($buckets['explicit_materialization_gap'] ?? 0) + 1;
                    if (count($gapSamples) < 20) {
                        $gapSamples[] = [
                            'student_class_id' => $scId,
                            'campus_id' => (int) ($row->CampusID ?? 0),
                            'package_id' => $pkgId ?: null,
                            'missing_7d' => $missing7,
                            'missing_28d' => $missing28,
                            'fingerprint' => $c['commitment_fingerprint'],
                        ];
                    }
                } else {
                    $buckets['explicit_healthy'] = ($buckets['explicit_healthy'] ?? 0) + 1;
                }
                if ($pkgId > 0) {
                    $poolDemand[$pkgId] = ($poolDemand[$pkgId] ?? 0) + count($occ28);
                }
            }

            $plan = $this->fsgBridge->planReadOnly($scId, $today);
            if ($plan['status'] === 'plan') {
                $slot = $class === CommitmentReasonCodes::CLASS_EXPLICIT ? 'explicit_commitment'
                    : ($class === CommitmentReasonCodes::CLASS_LEGACY ? 'legacy_inferred_candidate' : 'other');
                $fsg['plan'][$slot]++;
                if (count($fsg['plan_course_ids_sample']) < 15) {
                    $fsg['plan_course_ids_sample'][] = ['student_class_id' => $scId, 'classification' => $class];
                }
            } else {
                $r = $plan['reason'] ?: 'skip';
                $fsg['skip_by_reason'][$r] = ($fsg['skip_by_reason'][$r] ?? 0) + 1;
            }
        }

        $poolShortage = [];
        $poolsBlocked = 0;
        foreach ($poolDemand as $pkgId => $demand) {
            $avail = (int) ($pkgRem[$pkgId] ?? 0);
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
                'manual_backfill_definition' => 'ClassSession created_at in last 30d, Note not #1062, Status not cancelled/voided, created before SessionDate+StartTime',
            ],
            'questions' => [
                'q1_explicit_materialization_gap' => $q1 + ['samples' => $gapSamples],
                'q2_no_explicit_commitment_split' => $q2,
                'q3_shared_pool_coverage_gap' => [
                    'pools_with_shortage' => $poolsBlocked,
                    'pools' => array_slice($poolShortage, 0, 50),
                ],
                'q4_reason_code_distribution' => $reasons,
                'q5_forward_session_generator' => $fsg,
                'q6_guess_required_if_auto' => $guess,
                'q7_manual_backfill' => $this->measureManualBackfills($today, $branchId),
            ],
            'buckets' => $buckets,
            'classifications' => $classes,
            'student_class_adapter_assessment' => $this->assessAdapter($classes, $q1, $guess),
        ];
    }

    /** @return list<object> */
    private function loadCandidateCourses(?int $branchId, int $limit): array
    {
        $q = DB::table('StudentClass as sc')
            ->join('Student as s', 's.id', '=', 'sc.StudentID')
            ->leftJoin('course_packages as cp', 'cp.id', '=', 'sc.PackageID')
            ->where(fn ($w) => $w->where('sc.Stop', 0)->orWhereNull('sc.Stop'))
            ->whereRaw("LOWER(COALESCE(sc.ScheduleMode,'count')) = 'count'")
            ->where(function ($w) {
                $w->where('sc.RemainingSessions', '>', 0)
                    ->orWhere(function ($p) {
                        $p->whereNotNull('sc.PackageID')->where('sc.PackageID', '>', 0)
                            ->where('cp.remaining_sessions', '>', 0)
                            ->where(fn ($e) => $e->where('cp.stop', 0)->orWhereNull('cp.stop'));
                    });
            })
            ->orderBy('sc.ID')
            ->limit(max(1, $limit))
            ->select([
                'sc.ID', 'sc.StudentID', 'sc.TeacherID', 'sc.SubjectID', 'sc.ScheduleMode',
                'sc.RemainingSessions', 'sc.SessionCount', 'sc.SessionDuration',
                'sc.StartDate', 'sc.EndDate', 'sc.Stop', 'sc.PackageID',
                'sc.week', 'sc.time', 'sc.week1', 'sc.time1', 'sc.week2', 'sc.time2',
                'sc.week3', 'sc.time3', 'sc.week4', 'sc.time4', 'sc.week5', 'sc.time5',
                'sc.week6', 'sc.time6', 's.CampusID',
            ]);
        if ($branchId !== null && $branchId > 0) {
            $q->where('s.CampusID', $branchId);
        }

        return $q->get()->all();
    }

    /** @param list<int> $ids @return array<int, list<object>> */
    private function loadRecentSessions(array $ids): array
    {
        if ($ids === []) {
            return [];
        }
        $rows = DB::table('ClassSession')
            ->whereIn('StudentClassID', $ids)
            ->whereRaw("LOWER(Status) NOT IN ('cancelled','voided')")
            ->orderByDesc('SessionDate')->orderByDesc('id')
            ->get(['id', 'StudentClassID', 'SessionDate', 'StartTime', 'EndTime', 'Status']);
        $by = [];
        foreach ($rows as $r) {
            $sc = (int) $r->StudentClassID;
            if (count($by[$sc] ?? []) >= 6) {
                continue;
            }
            $by[$sc][] = $r;
        }

        return $by;
    }

    /** @param list<int> $ids @return array<int, list<object>> */
    private function loadFutureSessions(array $ids, Carbon $today, Carbon $through): array
    {
        if ($ids === []) {
            return [];
        }
        $rows = DB::table('ClassSession')
            ->whereIn('StudentClassID', $ids)
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

    /** @param list<int> $packageIds @return array<int,int> */
    private function loadPackageRemaining(array $packageIds): array
    {
        if ($packageIds === []) {
            return [];
        }
        $out = [];
        foreach (DB::table('course_packages')->whereIn('id', $packageIds)->get(['id', 'remaining_sessions']) as $r) {
            $out[(int) $r->id] = (int) $r->remaining_sessions;
        }

        return $out;
    }

    /** @return array<string,mixed> */
    private function measureManualBackfills(Carbon $today, ?int $branchId): array
    {
        $q = DB::table('ClassSession as cs')
            ->join('StudentClass as sc', 'sc.ID', '=', 'cs.StudentClassID')
            ->join('Student as s', 's.id', '=', 'sc.StudentID')
            ->whereDate('cs.created_at', '>=', $today->copy()->subDays(30)->toDateString())
            ->whereRaw("LOWER(cs.Status) NOT IN ('cancelled','voided')")
            ->where(fn ($w) => $w->whereNull('cs.Note')->orWhere('cs.Note', 'not like', '%#1062%'));
        if ($branchId !== null && $branchId > 0) {
            $q->where('s.CampusID', $branchId);
        }
        $hours = [];
        foreach ($q->get(['cs.SessionDate', 'cs.StartTime', 'cs.created_at']) as $r) {
            if ($r->created_at === null || $r->SessionDate === null || $r->StartTime === null) {
                continue;
            }
            try {
                $created = Carbon::parse((string) $r->created_at, 'Asia/Taipei');
                $classAt = Carbon::parse(
                    substr((string) $r->SessionDate, 0, 10).' '.substr((string) $r->StartTime, 0, 8),
                    'Asia/Taipei'
                );
            } catch (\Throwable) {
                continue;
            }
            if ($created->gte($classAt)) {
                continue;
            }
            $hours[] = round($created->diffInMinutes($classAt) / 60, 2);
        }
        sort($hours);
        $n = count($hours);

        return [
            'window_days' => 30,
            'manual_backfill_count' => $n,
            'median_hours_before_class' => $n === 0 ? null : $hours[(int) floor(($n - 1) / 2)],
            'p90_hours_before_class' => $n === 0 ? null : $hours[(int) floor(($n - 1) * 0.9)],
            'within_24h_of_class' => count(array_filter($hours, static fn ($h) => $h <= 24)),
        ];
    }

    /**
     * @param  array<string,int>  $classes
     * @param  array<string,mixed>  $q1
     * @param  array<string,int>  $guess
     * @return array<string,mixed>
     */
    private function assessAdapter(array $classes, array $q1, array $guess): array
    {
        $e = (int) ($classes[CommitmentReasonCodes::CLASS_EXPLICIT] ?? 0);
        $l = (int) ($classes[CommitmentReasonCodes::CLASS_LEGACY] ?? 0);
        $c = (int) ($classes[CommitmentReasonCodes::CLASS_CONFLICT] ?? 0);
        $i = (int) ($classes[CommitmentReasonCodes::CLASS_INCOMPLETE] ?? 0);
        $f = (int) ($classes[CommitmentReasonCodes::CLASS_FLEXIBLE] ?? 0);
        $t = max(1, $e + $l + $c + $i + $f);
        $newTable = $c > 0 && ($c / $t) > 0.15;

        return [
            'v1_adapter' => 'StudentClass contract fields',
            'permanent_ssot' => false,
            'explicit_share' => round($e / $t, 4),
            'legacy_share' => round($l / $t, 4),
            'conflict_share' => round($c / $t, 4),
            'incomplete_share' => round($i / $t, 4),
            'flexible_share' => round($f / $t, 4),
            'phase1_ensure_pilot_candidate_courses' => (int) ($q1['courses'] ?? 0),
            'recommend_new_schedule_commitments_table' => $newTable,
            'recommend_reason' => $newTable
                ? 'conflict_share_above_15pct — effective-dated commitment may be required (ADR-006 §6.5)'
                : 'v1_adapter_sufficient_for_explicit_subset_pending_phase1',
            'guess_required_codes' => $guess,
        ];
    }
}
