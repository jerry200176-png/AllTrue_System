<?php

namespace App\Console\Commands;

use App\Models\BugReport;
use App\Models\BugReportComment;
use App\Models\BugReportEvidence;
use App\Models\BugReportStatusLog;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use RuntimeException;
use Throwable;

/**
 * Record independently verified evidence for historical resolved bugs.
 *
 * This command never changes a bug status or rewrites a status log. A manifest
 * is the approval boundary; the production head, public resolution context,
 * and targeted probe metadata are checked before any insert is attempted.
 */
class BackfillBugResolutionEvidenceCommand extends Command
{
    private const KIND = 'bug_resolution_evidence_backfill';
    private const CONFIRMATION = 'BACKFILL_BUG_RESOLUTION_EVIDENCE';

    protected $signature = 'bugs:backfill-resolution-evidence
                            {manifest : Immutable, committed evidence manifest JSON}
                            {--dry-run : Validate and list inserts without writing}
                            {--apply : Append the validated evidence rows}
                            {--confirmation= : Required confirmation for --apply}
                            {--production-head= : Full SHA currently running in production}
                            {--json : Emit machine-readable result rows}';

    protected $description = 'Backfill independently verified evidence for legacy resolved bugs without changing status history.';

    public function handle(): int
    {
        $apply = (bool) $this->option('apply');
        $dryRun = (bool) $this->option('dry-run');

        if ($apply && $dryRun) {
            return $this->fail('choose exactly one of --dry-run or --apply');
        }
        if ($apply && $this->option('confirmation') !== self::CONFIRMATION) {
            return $this->fail('apply requires the exact confirmation phrase');
        }

        $manifest = $this->readManifest((string) $this->argument('manifest'));
        $productionHead = strtolower(trim((string) $this->option('production-head')));
        if (!preg_match('/^[0-9a-f]{40}$/', $productionHead)) {
            return $this->fail('production head must be a full 40-character git SHA');
        }

        try {
            $this->validateManifestEnvelope($manifest);
            $prepared = [];
            foreach ($manifest['items'] as $item) {
                $prepared[] = $this->prepareItem($item, $manifest, $productionHead);
            }
        } catch (Throwable $exception) {
            return $this->fail($exception->getMessage());
        }

        if (!$apply) {
            $this->emitResults(array_map(static fn (array $item): array => [
                'bug_id' => $item['bug_id'],
                'action' => 'would_record',
                'source_ref' => $item['source_ref'],
            ], $prepared), true);
            return self::SUCCESS;
        }

        $results = [];
        foreach ($prepared as $item) {
            try {
                $results[] = $this->recordItem($item, $manifest, $productionHead);
            } catch (Throwable $exception) {
                $results[] = [
                    'bug_id' => $item['bug_id'],
                    'action' => 'failed',
                    'error' => $exception->getMessage(),
                ];
            }
        }

        $this->emitResults($results, false);
        foreach ($results as $result) {
            if (($result['action'] ?? '') === 'failed') {
                return self::FAILURE;
            }
        }

        return self::SUCCESS;
    }

    /** @return array<string,mixed> */
    private function readManifest(string $path): array
    {
        if ($path === '' || !is_file($path) || !is_readable($path)) {
            throw new RuntimeException('manifest must be a readable JSON file');
        }

        try {
            $manifest = json_decode((string) file_get_contents($path), true, 512, JSON_THROW_ON_ERROR);
        } catch (Throwable $exception) {
            throw new RuntimeException('manifest is not valid JSON', 0, $exception);
        }
        if (!is_array($manifest)) {
            throw new RuntimeException('manifest must decode to an object');
        }

        return $manifest;
    }

    /** @param array<string,mixed> $manifest */
    private function validateManifestEnvelope(array $manifest): void
    {
        if (($manifest['kind'] ?? null) !== self::KIND) {
            throw new RuntimeException('manifest kind mismatch');
        }
        if (!is_string($manifest['manifest_id'] ?? null) || trim($manifest['manifest_id']) === '') {
            throw new RuntimeException('manifest_id is required');
        }
        if (!is_string($manifest['decision_reference'] ?? null) || trim($manifest['decision_reference']) === '') {
            throw new RuntimeException('decision_reference is required');
        }
        if (($manifest['evidence_type'] ?? null) !== BugReportEvidence::TYPE_RESOLUTION_PRODUCTION_VERIFICATION) {
            throw new RuntimeException('manifest evidence_type is not a resolution production verification');
        }
        if (!is_array($manifest['approved_bug_ids'] ?? null) || !is_array($manifest['items'] ?? null)) {
            throw new RuntimeException('approved_bug_ids and items are required arrays');
        }
        if (count($manifest['items']) < 1 || count($manifest['items']) > 100) {
            throw new RuntimeException('manifest must contain between 1 and 100 explicitly approved items');
        }

        $approved = array_map('intval', $manifest['approved_bug_ids']);
        $itemIds = array_map(static fn (array $item): int => (int) ($item['bug_id'] ?? 0), $manifest['items']);
        sort($approved);
        sort($itemIds);
        if (count($approved) !== count(array_unique($approved)) || $approved !== $itemIds) {
            throw new RuntimeException('manifest items must exactly match approved_bug_ids');
        }
    }

    /**
     * @param array<string,mixed> $item
     * @param array<string,mixed> $manifest
     * @return array<string,mixed>
     */
    private function prepareItem(array $item, array $manifest, string $productionHead): array
    {
        $bugId = (int) ($item['bug_id'] ?? 0);
        if ($bugId <= 0) {
            throw new RuntimeException('bug_id must be a positive integer');
        }
        $evidenceType = (string) ($item['evidence_type'] ?? $manifest['evidence_type']);
        if ($evidenceType !== BugReportEvidence::TYPE_RESOLUTION_PRODUCTION_VERIFICATION) {
            throw new RuntimeException("bug #{$bugId}: evidence_type mismatch");
        }

        $revision = strtolower(trim((string) ($item['production_revision'] ?? '')));
        if ($revision !== $productionHead) {
            throw new RuntimeException("bug #{$bugId}: production_revision does not match the observed production head");
        }

        $sourceRef = trim((string) ($item['source_ref'] ?? ''));
        if ($sourceRef === '' || strlen($sourceRef) > 255) {
            throw new RuntimeException("bug #{$bugId}: source_ref is required and must be at most 255 bytes");
        }

        $verifiedBy = (int) ($item['verified_by'] ?? 0);
        if ($verifiedBy <= 0 || !User::query()->where('id', $verifiedBy)->where('type', 'S')->exists()) {
            throw new RuntimeException("bug #{$bugId}: verified_by must identify an existing super_admin");
        }

        $verifiedAt = $this->parseVerifiedAt($item['verified_at'] ?? null, $bugId);
        $deployRunId = isset($item['deploy_run_id']) && $item['deploy_run_id'] !== null
            ? trim((string) $item['deploy_run_id'])
            : null;
        if ($deployRunId === '') {
            $deployRunId = null;
        }
        if ($deployRunId !== null && strlen($deployRunId) > 64) {
            throw new RuntimeException("bug #{$bugId}: deploy_run_id is too long");
        }

        $metadata = $item['metadata'] ?? null;
        if (!is_array($metadata)) {
            throw new RuntimeException("bug #{$bugId}: metadata with targeted probe evidence is required");
        }
        $this->validateProbeMetadata($metadata, $bugId);
        if (($metadata['manifest_id'] ?? $manifest['manifest_id']) !== $manifest['manifest_id']) {
            throw new RuntimeException("bug #{$bugId}: metadata manifest_id mismatch");
        }
        $metadata['manifest_id'] = $manifest['manifest_id'];

        $bug = BugReport::query()->find($bugId);
        if (!$bug) {
            throw new RuntimeException("bug #{$bugId}: bug not found");
        }
        $context = $this->assertResolutionContext($bug, $bugId, $metadata);

        return [
            'bug_id' => $bugId,
            'evidence_type' => $evidenceType,
            'production_revision' => $revision,
            'deploy_run_id' => $deployRunId,
            'source_ref' => $sourceRef,
            'verified_by' => $verifiedBy,
            'verified_at' => $verifiedAt,
            'metadata' => $metadata,
            'resolve_log_id' => (int) $context['resolve_log']->id,
        ];
    }

    /** @param array<string,mixed> $metadata */
    private function validateProbeMetadata(array $metadata, int $bugId): void
    {
        $probe = $metadata['probe'] ?? null;
        if (!is_array($probe)
            || ($probe['type'] ?? null) !== 'targeted_current_production'
            || ($probe['verdict'] ?? null) !== 'verified'
            || ($probe['health_only'] ?? true) !== false
            || trim((string) ($probe['observed'] ?? '')) === ''
            || trim((string) ($probe['evidence_ref'] ?? '')) === '') {
            throw new RuntimeException("bug #{$bugId}: metadata must contain a non-health-only verified targeted production probe");
        }

        $commentId = (int) ($metadata['public_resolution_comment_id'] ?? 0);
        if ($commentId <= 0) {
            throw new RuntimeException("bug #{$bugId}: public_resolution_comment_id is required");
        }
    }

    private function parseVerifiedAt(mixed $value, int $bugId): Carbon
    {
        if (!is_string($value) || trim($value) === '') {
            throw new RuntimeException("bug #{$bugId}: verified_at is required");
        }
        try {
            $verifiedAt = Carbon::parse($value);
        } catch (Throwable $exception) {
            throw new RuntimeException("bug #{$bugId}: verified_at is invalid", 0, $exception);
        }
        if ($verifiedAt->isFuture()) {
            throw new RuntimeException("bug #{$bugId}: verified_at cannot be in the future");
        }

        return $verifiedAt;
    }

    /** @param array<string,mixed> $metadata */
    private function assertResolutionContext(BugReport $bug, int $bugId, array $metadata): array
    {
        if ((string) $bug->status !== 'resolved') {
            throw new RuntimeException("bug #{$bugId}: status is not resolved");
        }
        $resolveLog = BugReportStatusLog::query()
            ->where('bug_report_id', $bugId)
            ->where('to_status', 'resolved')
            ->orderByDesc('id')
            ->first();
        if (!$resolveLog || !$resolveLog->created_at) {
            throw new RuntimeException("bug #{$bugId}: latest resolved status log is missing");
        }

        $commentId = (int) $metadata['public_resolution_comment_id'];
        $comment = BugReportComment::query()
            ->where('id', $commentId)
            ->where('bug_report_id', $bugId)
            ->where('is_internal_note', false)
            ->where('created_at', '>=', $resolveLog->created_at)
            ->first();
        if (!$comment) {
            throw new RuntimeException("bug #{$bugId}: public resolution context is missing or predates the latest resolve");
        }

        return ['resolve_log' => $resolveLog, 'comment' => $comment];
    }

    /** @param array<string,mixed> $item @param array<string,mixed> $manifest */
    private function recordItem(array $item, array $manifest, string $productionHead): array
    {
        return DB::transaction(function () use ($item, $manifest, $productionHead): array {
            $bug = BugReport::query()->whereKey($item['bug_id'])->lockForUpdate()->first();
            if (!$bug) {
                throw new RuntimeException("bug #{$item['bug_id']}: bug not found during apply");
            }
            $this->assertResolutionContext($bug, (int) $item['bug_id'], $item['metadata']);
            if ((string) $item['production_revision'] !== $productionHead) {
                throw new RuntimeException("bug #{$item['bug_id']}: production head changed during apply");
            }

            $existing = BugReportEvidence::query()
                ->where('bug_report_id', $item['bug_id'])
                ->where('evidence_type', $item['evidence_type'])
                ->where('source_ref', $item['source_ref'])
                ->first();
            if ($existing) {
                if ((string) $existing->production_revision !== (string) $item['production_revision']
                    || (int) $existing->verified_by !== (int) $item['verified_by']) {
                    throw new RuntimeException("bug #{$item['bug_id']}: idempotency key already exists with different evidence");
                }
                return [
                    'bug_id' => $item['bug_id'],
                    'action' => 'already_recorded',
                    'evidence_id' => $existing->id,
                ];
            }

            $evidence = BugReportEvidence::create([
                'bug_report_id' => $item['bug_id'],
                'evidence_type' => $item['evidence_type'],
                'production_revision' => $item['production_revision'],
                'deploy_run_id' => $item['deploy_run_id'],
                'source_ref' => $item['source_ref'],
                'verified_by' => $item['verified_by'],
                'verified_at' => $item['verified_at'],
                'metadata' => $item['metadata'],
                'created_at' => Carbon::now(),
            ]);

            return [
                'bug_id' => $item['bug_id'],
                'action' => 'recorded',
                'evidence_id' => $evidence->id,
            ];
        });
    }

    /** @param list<array<string,mixed>> $results */
    private function emitResults(array $results, bool $dryRun): void
    {
        if ($this->option('json')) {
            $this->line(json_encode([
                'mode' => $dryRun ? 'dry-run' : 'apply',
                'count' => count($results),
                'results' => $results,
            ], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));
            return;
        }

        $this->info(($dryRun ? '[dry-run] ' : '') . 'items=' . count($results));
        foreach ($results as $result) {
            $suffix = isset($result['evidence_id']) ? ' evidence_id=' . $result['evidence_id'] : '';
            $this->line('bug #' . $result['bug_id'] . ': ' . $result['action'] . $suffix);
        }
    }

    private function fail(string $message): int
    {
        $this->error($message);
        return self::FAILURE;
    }
}
