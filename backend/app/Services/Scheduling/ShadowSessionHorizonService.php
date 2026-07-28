<?php

namespace App\Services\Scheduling;

use Carbon\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * ADR-006 Phase 2 — shadow materializer (always read-only).
 * Compares Preview vs Ensure dry-run; never writes ClassSession.
 */
final class ShadowSessionHorizonService
{
    public function __construct(
        private ?PreviewSessionHorizonService $preview = null,
        private ?EnsureSessionHorizonService $ensure = null,
    ) {
        $this->preview = $preview ?? app(PreviewSessionHorizonService::class);
        $this->ensure = $ensure ?? app(EnsureSessionHorizonService::class);
    }

    /**
     * @return array<string,mixed>
     */
    public function run(?int $branchId = null, ?Carbon $today = null, int $limit = 500): array
    {
        $today = ($today ?? Carbon::today('Asia/Taipei'))->copy()->startOfDay();
        $ids = $this->loadCandidateIds($branchId, $limit);

        $classCounts = [];
        $ensureReasons = [];
        $previewUncovered = 0;
        $ensureCandidates = 0;
        $driftCourses = 0;
        $blockedShortage = 0;
        $ensureEligible = 0;
        $samples = [];

        foreach ($ids as $scId) {
            $previewDto = $this->preview->preview($scId, null, $today, $branchId);
            $ensureDto = $this->ensure->ensure(
                $scId, null, $today, $branchId, EnsureSessionHorizonService::MODE_DRY_RUN
            );
            $p = $previewDto['preview'];
            $e = $ensureDto['ensure'];
            $class = (string) ($p['classification'] ?? 'unknown');
            $classCounts[$class] = ($classCounts[$class] ?? 0) + 1;
            $reason = (string) ($e['primary_reason'] ?? 'n/a');
            $ensureReasons[$reason] = ($ensureReasons[$reason] ?? 0) + 1;

            $uncovered = (int) ($p['occurrence_summary']['uncovered_new'] ?? 0);
            $previewUncovered += $uncovered;
            $candN = count($e['candidates'] ?? []);
            $ensureCandidates += $candN;
            if (!empty($p['auto_ensure_eligible'])) {
                $ensureEligible++;
            }
            if ($reason === CommitmentReasonCodes::BLOCK_POOL_SHORTAGE) {
                $blockedShortage++;
            }

            // Drift: Preview covered-new count vs Ensure dry-run candidates (eligible only).
            $coverable = (int) ($p['occurrence_summary']['coverable_new'] ?? 0);
            $drift = false;
            if (!empty($p['auto_ensure_eligible']) && $reason === 'DRY_RUN_OK' && $coverable !== $candN) {
                $drift = true;
                $driftCourses++;
            }

            if ($drift || $reason === CommitmentReasonCodes::BLOCK_POOL_SHORTAGE) {
                if (count($samples) < 25) {
                    $samples[] = [
                        'student_class_id' => $scId,
                        'classification' => $class,
                        'ensure_reason' => $reason,
                        'coverable_new' => $coverable,
                        'ensure_candidates' => $candN,
                        'uncovered_new' => $uncovered,
                        'drift' => $drift,
                    ];
                }
            }
        }

        return [
            'meta' => [
                'report' => 'adr006-phase2-shadow-horizon',
                'rule_version' => CommitmentReasonCodes::RULE_VERSION,
                'as_of' => $today->toDateString(),
                'timezone' => 'Asia/Taipei',
                'branch_id' => $branchId,
                'courses_scanned' => count($ids),
                'read_only' => true,
                'writes' => false,
                'ensure_mode' => EnsureSessionHorizonService::MODE_DRY_RUN,
                'production_safe' => true,
            ],
            'metrics' => [
                'classifications' => $classCounts,
                'ensure_primary_reasons' => $ensureReasons,
                'ensure_eligible_courses' => $ensureEligible,
                'ensure_candidate_slots' => $ensureCandidates,
                'preview_uncovered_slots' => $previewUncovered,
                'blocked_pool_shortage_courses' => $blockedShortage,
                'preview_vs_ensure_drift_courses' => $driftCourses,
            ],
            'samples' => $samples,
        ];
    }

    /** @return list<int> */
    private function loadCandidateIds(?int $branchId, int $limit): array
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
            ->select('sc.ID');
        if ($branchId !== null && $branchId > 0) {
            $q->where('s.CampusID', $branchId);
        }

        return array_map(static fn ($r) => (int) $r->ID, $q->get()->all());
    }
}
