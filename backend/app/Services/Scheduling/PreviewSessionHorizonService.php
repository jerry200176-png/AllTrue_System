<?php

namespace App\Services\Scheduling;

use Carbon\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * ADR-006 Phase 1A — PreviewSessionHorizon (read-only dry-run DTO).
 * Never creates ClassSession; never mutates entitlement / coverage / ledger.
 */
final class PreviewSessionHorizonService
{
    public const DEFAULT_HORIZON_DAYS = 28;

    public function __construct(
        private ?ScheduleCommitmentClassifier $classifier = null,
        private ?CommitmentOccurrenceExpander $expander = null,
    ) {
        $this->classifier = $classifier ?? new ScheduleCommitmentClassifier();
        $this->expander = $expander ?? new CommitmentOccurrenceExpander();
    }

    /**
     * @return array<string,mixed>
     */
    public function preview(
        int $studentClassId,
        ?Carbon $throughDate = null,
        ?Carbon $today = null,
        ?int $requireCampusId = null,
    ): array {
        $today = ($today ?? Carbon::today('Asia/Taipei'))->copy()->startOfDay();
        $through = ($throughDate ?? $today->copy()->addDays(self::DEFAULT_HORIZON_DAYS))
            ->copy()->startOfDay();

        $course = $this->loadCourse($studentClassId);
        if ($course === null) {
            return $this->emptyResult($studentClassId, $today, $through, [
                'ok' => false,
                'primary_reason' => 'NOT_FOUND',
                'classification' => null,
                'occurrences' => [],
                'pool_projection' => null,
                'auto_ensure_eligible' => false,
                'error' => 'student_class_not_found',
            ]);
        }

        $campusId = (int) ($course->CampusID ?? 0);
        if ($requireCampusId !== null && $requireCampusId > 0 && $campusId !== $requireCampusId) {
            return $this->emptyResult($studentClassId, $today, $through, [
                'ok' => false,
                'primary_reason' => CommitmentReasonCodes::FAIL_BRANCH_ISOLATION,
                'classification' => null,
                'campus_id' => $campusId,
                'occurrences' => [],
                'pool_projection' => null,
                'auto_ensure_eligible' => false,
                'error' => 'branch_isolation',
            ]);
        }

        $recent = $this->loadRecentSessions($studentClassId);
        $classified = $this->classifier->classify($course, $recent, $today);
        $class = $classified['classification'];
        $reason = $classified['primary_reason'];

        $base = [
            'ok' => true,
            'student_class_id' => $studentClassId,
            'campus_id' => $campusId > 0 ? $campusId : null,
            'package_id' => $classified['package_id'],
            'classification' => $class,
            'primary_reason' => $reason,
            'bucket' => $classified['bucket'],
            'guess_required' => (bool) ($classified['guess_required'] ?? false),
            'auto_ensure_eligible' => (bool) ($classified['auto_ensure_eligible'] ?? false),
            'commitment_snapshot' => $classified['commitment_snapshot'],
            'commitment_fingerprint' => $classified['commitment_fingerprint'],
            'contract_slots' => $classified['contract_slots'],
            'history_cadence' => $classified['history_cadence'],
            'suggested_cadence' => null,
            'occurrences' => [],
            'pool_projection' => null,
            'detail' => $classified['detail'] ?? null,
        ];

        if ($class === CommitmentReasonCodes::CLASS_LEGACY) {
            $base['suggested_cadence'] = $classified['history_cadence'];
            $base['auto_ensure_eligible'] = false;

            return $this->wrap($today, $through, $base);
        }

        if ($class !== CommitmentReasonCodes::CLASS_EXPLICIT) {
            $base['auto_ensure_eligible'] = false;

            return $this->wrap($today, $through, $base);
        }

        $cStart = $course->StartDate ? Carbon::parse((string) $course->StartDate) : null;
        $cEnd = $course->EndDate ? Carbon::parse((string) $course->EndDate) : null;
        $planned = $this->expander->expand($classified['contract_slots'], $today, $through, $cStart, $cEnd);
        $existingKeys = $this->loadExistingKeys($studentClassId, $today, $through);

        $pkgId = (int) ($course->PackageID ?? 0);
        $poolRemaining = $pkgId > 0 ? $this->loadPackageRemaining($pkgId) : null;
        // Member course entitlement cap (standalone count course): RemainingSessions.
        // Shared pool: do NOT expose remaining on member; use pool_projection only.
        $memberRemaining = (int) ($course->RemainingSessions ?? 0);

        $coverBudget = $pkgId > 0
            ? (int) ($poolRemaining ?? 0)
            : max(0, $memberRemaining);

        $occurrences = [];
        $expectedNew = 0;
        $coveredNew = 0;
        $uncoveredNew = 0;

        foreach ($planned as $o) {
            $key = $o['date'].'|'.$o['start_hm'];
            if (isset($existingKeys[$key])) {
                $occurrences[] = [
                    'date' => $o['date'],
                    'start_hm' => $o['start_hm'],
                    'end_hm' => $o['end_hm'],
                    'iso_weekday' => $o['iso_weekday'],
                    'state' => 'exists',
                    'reason_code' => CommitmentReasonCodes::OK_EXISTS,
                    'covered' => true,
                ];
                continue;
            }

            $expectedNew++;
            if ($coverBudget > 0) {
                $coverBudget--;
                $coveredNew++;
                $occurrences[] = [
                    'date' => $o['date'],
                    'start_hm' => $o['start_hm'],
                    'end_hm' => $o['end_hm'],
                    'iso_weekday' => $o['iso_weekday'],
                    'state' => 'planned_covered',
                    'reason_code' => CommitmentReasonCodes::OK_PLAN,
                    'covered' => true,
                ];
            } else {
                $uncoveredNew++;
                $occurrences[] = [
                    'date' => $o['date'],
                    'start_hm' => $o['start_hm'],
                    'end_hm' => $o['end_hm'],
                    'iso_weekday' => $o['iso_weekday'],
                    'state' => 'planned_uncovered',
                    'reason_code' => CommitmentReasonCodes::OK_PREVIEW_UNCOVERED,
                    'covered' => false,
                ];
            }
        }

        $base['occurrences'] = $occurrences;
        $base['occurrence_summary'] = [
            'planned_total' => count($planned),
            'already_exists' => count($planned) - $expectedNew,
            'expected_new' => $expectedNew,
            'coverable_new' => $coveredNew,
            'uncovered_new' => $uncoveredNew,
        ];

        if ($pkgId > 0) {
            // Pool-layer projection only — never "member pool remaining".
            $base['pool_projection'] = [
                'package_id' => $pkgId,
                'horizon_expected_new' => $expectedNew,
                'horizon_coverable' => $coveredNew,
                'horizon_uncovered' => $uncoveredNew,
                // A shared package may be planned beyond its current ledger
                // balance. Keep the uncovered count as a renewal signal, but
                // never turn future planning into a write-time capacity gate.
                'ensure_would_block' => false,
                'ensure_block_reason' => null,
                'renewal_warning' => $uncoveredNew > 0,
                'overage_sessions' => $uncoveredNew,
            ];
        } else {
            $base['pool_projection'] = null;
            $base['standalone_entitlement'] = [
                'horizon_expected_new' => $expectedNew,
                'horizon_coverable' => $coveredNew,
                'horizon_uncovered' => $uncoveredNew,
            ];
        }

        return $this->wrap($today, $through, $base);
    }

    /**
     * @param  array<string,mixed>  $payload
     * @return array<string,mixed>
     */
    private function wrap(Carbon $today, Carbon $through, array $payload): array
    {
        return [
            'meta' => [
                'command' => 'PreviewSessionHorizon',
                'rule_version' => CommitmentReasonCodes::RULE_VERSION,
                'as_of' => $today->toDateString(),
                'through_date' => $through->toDateString(),
                'timezone' => 'Asia/Taipei',
                'default_horizon_days' => self::DEFAULT_HORIZON_DAYS,
                'read_only' => true,
                'writes' => false,
                'will_create_sessions' => false,
                'will_deduct' => false,
            ],
            'preview' => $payload,
        ];
    }

    /**
     * @param  array<string,mixed>  $payload
     * @return array<string,mixed>
     */
    private function emptyResult(int $studentClassId, Carbon $today, Carbon $through, array $payload): array
    {
        $payload['student_class_id'] = $studentClassId;

        return $this->wrap($today, $through, $payload);
    }

    private function loadCourse(int $studentClassId): ?object
    {
        return DB::table('StudentClass as sc')
            ->join('Student as s', 's.id', '=', 'sc.StudentID')
            ->where('sc.ID', $studentClassId)
            ->select([
                'sc.ID', 'sc.StudentID', 'sc.TeacherID', 'sc.SubjectID', 'sc.ScheduleMode',
                'sc.RemainingSessions', 'sc.SessionCount', 'sc.SessionDuration',
                'sc.StartDate', 'sc.EndDate', 'sc.Stop', 'sc.PackageID',
                'sc.week', 'sc.time', 'sc.week1', 'sc.time1', 'sc.week2', 'sc.time2',
                'sc.week3', 'sc.time3', 'sc.week4', 'sc.time4', 'sc.week5', 'sc.time5',
                'sc.week6', 'sc.time6', 's.CampusID',
            ])
            ->first();
    }

    /** @return list<object> */
    private function loadRecentSessions(int $studentClassId): array
    {
        return DB::table('ClassSession')
            ->where('StudentClassID', $studentClassId)
            ->whereRaw("LOWER(Status) NOT IN ('cancelled','voided')")
            ->orderByDesc('SessionDate')->orderByDesc('id')
            ->limit(6)
            ->get(['id', 'StudentClassID', 'SessionDate', 'StartTime', 'EndTime', 'Status'])
            ->all();
    }

    /** @return array<string,true> */
    private function loadExistingKeys(int $studentClassId, Carbon $today, Carbon $through): array
    {
        $rows = DB::table('ClassSession')
            ->where('StudentClassID', $studentClassId)
            ->whereDate('SessionDate', '>=', $today->toDateString())
            ->whereDate('SessionDate', '<=', $through->toDateString())
            ->whereRaw("LOWER(Status) NOT IN ('cancelled','voided')")
            ->get(['SessionDate', 'StartTime']);
        $keys = [];
        foreach ($rows as $r) {
            $keys[substr((string) $r->SessionDate, 0, 10).'|'.substr((string) $r->StartTime, 0, 5)] = true;
        }

        return $keys;
    }

    private function loadPackageRemaining(int $packageId): int
    {
        $v = DB::table('course_packages')->where('id', $packageId)->value('remaining_sessions');

        return (int) ($v ?? 0);
    }
}
