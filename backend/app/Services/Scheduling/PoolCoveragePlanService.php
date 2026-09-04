<?php

namespace App\Services\Scheduling;

use Carbon\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * ADR-006 Phase 3 — pool-level coverage plan for a package (read-only).
 * Builds candidates from PreviewSessionHorizon member courses; no coverage write.
 */
final class PoolCoveragePlanService
{
    public function __construct(
        private ?PreviewSessionHorizonService $preview = null,
        private ?AllocateSessionCoveragePlanner $allocator = null,
    ) {
        $this->preview = $preview ?? app(PreviewSessionHorizonService::class);
        $this->allocator = $allocator ?? new AllocateSessionCoveragePlanner();
    }

    /**
     * @return array<string,mixed>
     */
    public function planForPackage(
        int $packageId,
        ?Carbon $throughDate = null,
        ?Carbon $today = null,
        ?int $requireCampusId = null,
    ): array {
        $today = ($today ?? Carbon::today('Asia/Taipei'))->copy()->startOfDay();
        $pkg = DB::table('course_packages')->where('id', $packageId)->first();
        if ($pkg === null) {
            return [
                'meta' => [
                    'command' => 'AllocateSessionCoverage',
                    'read_only' => true,
                    'writes' => false,
                    'as_of' => $today->toDateString(),
                ],
                'ok' => false,
                'error' => 'package_not_found',
                'package_id' => $packageId,
            ];
        }

        $campusId = (int) ($pkg->campus_id ?? 0);
        if ($requireCampusId !== null && $requireCampusId > 0 && $campusId !== $requireCampusId) {
            return [
                'meta' => [
                    'command' => 'AllocateSessionCoverage',
                    'read_only' => true,
                    'writes' => false,
                    'as_of' => $today->toDateString(),
                ],
                'ok' => false,
                'error' => CommitmentReasonCodes::FAIL_BRANCH_ISOLATION,
                'package_id' => $packageId,
            ];
        }

        $memberIds = DB::table('StudentClass as sc')
            ->join('Student as s', 's.id', '=', 'sc.StudentID')
            ->where('sc.PackageID', $packageId)
            ->where(fn ($w) => $w->where('sc.Stop', 0)->orWhereNull('sc.Stop'))
            ->when($requireCampusId !== null && $requireCampusId > 0, fn ($q) => $q->where('s.CampusID', $requireCampusId))
            ->orderBy('sc.ID')
            ->pluck('sc.ID')
            ->map(static fn ($id) => (int) $id)
            ->all();

        $candidates = [];
        $memberSummaries = [];
        foreach ($memberIds as $scId) {
            $dto = $this->preview->preview($scId, $throughDate, $today, $requireCampusId);
            $p = $dto['preview'];
            $memberSummaries[] = [
                'student_class_id' => $scId,
                'classification' => $p['classification'] ?? null,
                'primary_reason' => $p['primary_reason'] ?? null,
                'auto_ensure_eligible' => (bool) ($p['auto_ensure_eligible'] ?? false),
            ];
            if (($p['classification'] ?? '') !== CommitmentReasonCodes::CLASS_EXPLICIT) {
                continue;
            }
            foreach ($p['occurrences'] ?? [] as $o) {
                if (($o['state'] ?? '') === 'exists') {
                    continue; // already materialized; coverage attach is later write path
                }
                if (($o['state'] ?? '') === 'planned_covered' || ($o['state'] ?? '') === 'planned_uncovered') {
                    $candidates[] = [
                        'student_class_id' => $scId,
                        'date' => $o['date'],
                        'start_hm' => $o['start_hm'],
                        'class_session_id' => null,
                        'current_state' => CoverageStates::NONE,
                    ];
                }
            }
        }

        $plan = $this->allocator->plan(
            $packageId,
            (int) ($pkg->remaining_sessions ?? 0),
            $candidates,
            $campusId > 0 ? $campusId : null,
        );

        return [
            'ok' => true,
            'package_id' => $packageId,
            'member_count' => count($memberIds),
            'members' => $memberSummaries,
            'allocation' => $plan,
            // Never expose "member pool remaining" — only pool-layer projection.
            'pool_projection' => [
                'package_id' => $packageId,
                'horizon_expected_new' => count($candidates),
                'horizon_coverable' => $plan['pool']['held_planned'],
                'horizon_uncovered' => $plan['pool']['uncovered_planned'],
                // Coverage planning reports the renewal gap but does not own
                // the scheduling gate. Future rows remain creatable for every
                // subject in the shared package.
                'ensure_would_block' => false,
                'ensure_block_reason' => null,
                'renewal_warning' => $plan['pool']['uncovered_planned'] > 0,
                'overage_sessions' => $plan['pool']['uncovered_planned'],
            ],
        ];
    }
}
