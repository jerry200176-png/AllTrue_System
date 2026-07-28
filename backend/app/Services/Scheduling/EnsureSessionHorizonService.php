<?php

namespace App\Services\Scheduling;

use App\Helpers\FeatureFlag;
use App\Services\ClassSessionMaterializationService;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * ADR-006 Phase 1B — EnsureSessionHorizon.
 *
 * Default: dry-run / feature-flag OFF. Never activates in production without
 * Founder GO (hard-blocked). Writes only via ClassSessionMaterializationService::upsertSlot.
 */
final class EnsureSessionHorizonService
{
    public const FEATURE_KEY = 'ensure-session-horizon';

    public const MODE_DRY_RUN = 'dry_run';
    public const MODE_EXECUTE = 'execute';

    public function __construct(
        private ?PreviewSessionHorizonService $preview = null,
        private ?ClassSessionMaterializationService $materializer = null,
    ) {
        $this->preview = $preview ?? app(PreviewSessionHorizonService::class);
        $this->materializer = $materializer ?? app(ClassSessionMaterializationService::class);
    }

    /**
     * @return array<string,mixed>
     */
    public function ensure(
        int $studentClassId,
        ?Carbon $throughDate = null,
        ?Carbon $today = null,
        ?int $requireCampusId = null,
        string $mode = self::MODE_DRY_RUN,
    ): array {
        $mode = $mode === self::MODE_EXECUTE ? self::MODE_EXECUTE : self::MODE_DRY_RUN;
        $previewDto = $this->preview->preview($studentClassId, $throughDate, $today, $requireCampusId);
        $p = $previewDto['preview'];

        $result = [
            'meta' => [
                'command' => 'EnsureSessionHorizon',
                'rule_version' => CommitmentReasonCodes::RULE_VERSION,
                'mode' => $mode,
                'feature_flag' => self::FEATURE_KEY,
                'feature_enabled' => false,
                'read_only' => $mode !== self::MODE_EXECUTE,
                'writes' => false,
                'as_of' => $previewDto['meta']['as_of'],
                'through_date' => $previewDto['meta']['through_date'],
                'timezone' => 'Asia/Taipei',
            ],
            'preview' => $p,
            'ensure' => [
                'ok' => false,
                'primary_reason' => null,
                'candidates' => [],
                'created_session_ids' => [],
                'created_count' => 0,
                'skipped_count' => 0,
                'blocked' => true,
            ],
        ];

        if (empty($p['ok'])) {
            $result['ensure']['primary_reason'] = $p['primary_reason'] ?? 'NOT_FOUND';

            return $result;
        }

        $campusId = isset($p['campus_id']) ? (int) $p['campus_id'] : null;
        $flagOn = FeatureFlag::enabled(self::FEATURE_KEY, $campusId);
        $result['meta']['feature_enabled'] = $flagOn;

        if ($p['classification'] !== CommitmentReasonCodes::CLASS_EXPLICIT
            || empty($p['auto_ensure_eligible'])) {
            $result['ensure']['primary_reason'] = $p['primary_reason']
                ?? CommitmentReasonCodes::BLOCK_COMMITMENT_INCOMPLETE;
            $result['ensure']['blocked'] = true;

            return $result;
        }

        // Shared-pool ES: whole-command no-write (Founder).
        if (!empty($p['pool_projection']['ensure_would_block'])) {
            $result['ensure']['primary_reason'] = CommitmentReasonCodes::BLOCK_POOL_SHORTAGE;
            $result['ensure']['blocked'] = true;

            return $result;
        }

        // Standalone entitlement shortage: also block whole command (symmetric fail-closed).
        if (!empty($p['standalone_entitlement'])
            && (int) ($p['standalone_entitlement']['horizon_uncovered'] ?? 0) > 0) {
            $result['ensure']['primary_reason'] = CommitmentReasonCodes::BLOCK_POOL_SHORTAGE;
            $result['ensure']['blocked'] = true;
            $result['ensure']['detail'] = 'standalone_entitlement_shortage';

            return $result;
        }

        $candidates = [];
        foreach ($p['occurrences'] ?? [] as $o) {
            if (($o['reason_code'] ?? '') === CommitmentReasonCodes::OK_PLAN
                && ($o['state'] ?? '') === 'planned_covered') {
                $candidates[] = $o;
            }
        }
        $result['ensure']['candidates'] = $candidates;

        if ($candidates === []) {
            $result['ensure']['ok'] = true;
            $result['ensure']['blocked'] = false;
            $result['ensure']['primary_reason'] = CommitmentReasonCodes::OK_EXISTS;
            $result['meta']['read_only'] = true;

            return $result;
        }

        if ($mode !== self::MODE_EXECUTE) {
            $result['ensure']['ok'] = true;
            $result['ensure']['blocked'] = false;
            $result['ensure']['primary_reason'] = 'DRY_RUN_OK';
            $result['ensure']['would_create'] = count($candidates);
            $result['meta']['read_only'] = true;

            return $result;
        }

        // Execute path gates (fail closed).
        if (!$flagOn) {
            $result['ensure']['primary_reason'] = 'FEATURE_FLAG_OFF';

            return $result;
        }
        if (app()->environment('production')) {
            $result['ensure']['primary_reason'] = 'PRODUCTION_EXECUTE_REQUIRES_GO';

            return $result;
        }

        $fingerprint = (string) ($p['commitment_fingerprint'] ?? '');
        $note = 'adr006-ensure fp='.substr($fingerprint, 0, 32);
        $studentId = $this->loadStudentId($studentClassId);
        $createdIds = [];
        $skipped = 0;

        try {
            DB::transaction(function () use (
                $candidates, $studentClassId, $studentId, $note, &$createdIds, &$skipped
            ) {
                foreach ($candidates as $o) {
                    if ($this->hasCrossScConflict($studentId, $o['date'], $o['start_hm'], $studentClassId)) {
                        Log::warning('ensure_horizon_cross_sc_conflict', [
                            'student_class_id' => $studentClassId,
                            'date' => $o['date'],
                            'start_hm' => $o['start_hm'],
                        ]);
                        $skipped++;
                        continue;
                    }
                    $end = $o['end_hm'] ?? $this->defaultEnd($o['start_hm']);
                    $res = $this->materializer->upsertSlot([
                        'StudentClassID' => $studentClassId,
                        'SessionDate' => $o['date'],
                        'StartTime' => $o['start_hm'],
                        'EndTime' => $end,
                        'Status' => 'scheduled',
                        'Note' => $note,
                    ]);
                    if ($res['created']) {
                        $createdIds[] = (int) $res['session']->id;
                    } else {
                        $skipped++;
                    }
                }
            });
        } catch (\Throwable $e) {
            Log::error('ensure_horizon_failed', [
                'student_class_id' => $studentClassId,
                'error' => $e->getMessage(),
            ]);
            $result['ensure']['primary_reason'] = 'ENSURE_FAILED';
            $result['ensure']['error'] = $e->getMessage();

            return $result;
        }

        $result['meta']['read_only'] = false;
        $result['meta']['writes'] = $createdIds !== [];
        $result['ensure']['ok'] = true;
        $result['ensure']['blocked'] = false;
        $result['ensure']['primary_reason'] = CommitmentReasonCodes::OK_PLAN;
        $result['ensure']['created_session_ids'] = $createdIds;
        $result['ensure']['created_count'] = count($createdIds);
        $result['ensure']['skipped_count'] = $skipped;
        $result['ensure']['provenance_note'] = $note;

        return $result;
    }

    private function loadStudentId(int $studentClassId): int
    {
        return (int) (DB::table('StudentClass')->where('ID', $studentClassId)->value('StudentID') ?? 0);
    }

    private function hasCrossScConflict(int $studentId, string $date, string $startHm, int $selfScId): bool
    {
        if ($studentId <= 0) {
            return false;
        }

        return DB::table('ClassSession as cs')
            ->join('StudentClass as sc', 'sc.ID', '=', 'cs.StudentClassID')
            ->where('sc.StudentID', $studentId)
            ->where('cs.StudentClassID', '!=', $selfScId)
            ->whereDate('cs.SessionDate', $date)
            ->whereRaw('SUBSTRING(cs.StartTime, 1, 5) = ?', [substr($startHm, 0, 5)])
            ->whereRaw("LOWER(cs.Status) NOT IN ('cancelled','voided')")
            ->exists();
    }

    private function defaultEnd(string $startHm): string
    {
        try {
            return Carbon::createFromFormat('H:i', substr($startHm, 0, 5))
                ->addHours(2)->format('H:i:s');
        } catch (\Throwable) {
            return '18:00:00';
        }
    }
}
