<?php

namespace App\Operations\Strategies;

use App\Models\BugReport;
use App\Console\Commands\CloseStaleResolvedBugsCommand;
use App\Operations\Inapp\InappStandingPolicy;
use App\Services\BugReportService;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/** ADR-POP-015 (c): 14-day reporter-timeout close; each ID rechecked under its row lock, ineligible IDs skipped, never closed. */
final class InappReportReporterTimeoutStrategy
{
    public const OPERATION = 'inapp-report-reporter-timeout';
    public const DAYS = 14;
    public const MAX_IDS = 20;

    public function plan(array $p): array
    {
        $ids = $p['bug_ids'] ?? null;
        $errors = [];
        if (!is_string($p['request_id'] ?? null) || preg_match('/^[a-z0-9][a-z0-9-]{2,63}$/', $p['request_id']) !== 1) $errors[] = 'request_id_invalid';
        if (!is_array($ids) || !array_is_list($ids) || $ids === [] || count($ids) > self::MAX_IDS
            || count(array_unique($ids)) !== count($ids) || array_filter($ids, fn ($id) => !is_int($id) || $id <= 0) !== []) {
            $errors[] = 'bug_ids_invalid';
            $ids = [];
        }
        $campuses = BugReport::query()->whereKey($ids)->pluck('CampusID')->map(fn ($c) => (int) $c)->all();
        if (count($campuses) !== count($ids)) $errors[] = 'report_missing';
        if (!is_int($p['campus_id'] ?? null) || $campuses === [] || array_diff($campuses, [$p['campus_id']]) !== []) $errors[] = 'report_outside_campus';
        $queue = $this->eligibleIds();
        $eligible = array_values(array_intersect($ids, $queue));
        sort($eligible);
        $skipped = array_values(array_diff($ids, $eligible));
        // Policy-only blockers: a human reviewed batch may still run, closing only what is eligible.
        $blockers = [];
        if ($skipped !== []) $blockers[] = 'ids_not_eligible';
        if (count($queue) > CloseStaleResolvedBugsCommand::AUTO_MAX_PER_RUN) $blockers[] = 'eligible_queue_over_auto_max';

        return ['ok' => $errors === [], 'errors' => $errors, 'eligible_ids' => $eligible, 'skipped_ids' => $skipped,
            'queue_count' => count($queue), 'policy_blockers' => $blockers,
            'digest' => hash('sha256', json_encode([self::OPERATION, $eligible, $skipped], JSON_THROW_ON_ERROR))];
    }

    public function execute(array $plan, array $context): array
    {
        if (!($plan['ok'] ?? false)) throw new RuntimeException('inapp_timeout_plan_not_ready');
        // An unattended (policy) run changes nothing once the whole queue exceeds AUTO_MAX_PER_RUN.
        if (in_array('eligible_queue_over_auto_max', (array) ($plan['policy_blockers'] ?? []), true)
            && InappStandingPolicy::policyApprovers((string) $context['operation_id'])->isNotEmpty()) {
            throw new RuntimeException('inapp_timeout_queue_over_auto_max');
        }
        $request = DB::table('pop_operation_requests')->where('id', (string) $context['operation_id'])->first();
        $p = json_decode((string) $request?->parameters, true, 512, JSON_THROW_ON_ERROR);
        $actor = InappStandingPolicy::attributionUserId($request);
        $closed = [];
        $skipped = [];
        foreach ((array) $p['bug_ids'] as $bugId) {
            try {
                $done = DB::transaction(function () use ($bugId, $actor, $p, $context): bool {
                    // Row lock held across the recheck; closeByReporterTimeout() re-runs the eligibility rule under it.
                    $bug = BugReport::query()->whereKey((int) $bugId)->lockForUpdate()->first();
                    InappStandingPolicy::assertKillSwitchesOpen((string) $context['operation_id']);
                    if (!$bug || (int) $bug->getAttribute('CampusID') !== (int) $p['campus_id'] || $bug->getAttribute('status') !== 'resolved') {
                        return false;
                    }

                    return BugReportService::closeByReporterTimeout((int) $bugId, $actor, false, self::DAYS)['action'] === 'closed';
                });
            } catch (RuntimeException $e) { // a kill switch tripped mid-batch: stop, and record what already closed
                return ['ok' => false, 'errors' => [$e->getMessage()], 'closed_ids' => $closed, 'skipped_ids' => $skipped];
            }
            if ($done) $closed[] = (int) $bugId;
            else $skipped[] = (int) $bugId;
        }

        return ['ok' => true, 'closed_ids' => $closed, 'skipped_ids' => $skipped];
    }

    public function verify(array $plan, array $result): array
    {
        $open = BugReport::query()->whereKey((array) ($result['closed_ids'] ?? []))->where('status', '!=', 'closed')->exists();

        return ['ok' => ($result['ok'] ?? false) === true && !$open, 'errors' => $open ? ['not_closed'] : [], 'checks' => ['closed']];
    }

    public function rollback(array $snapshot, array $context): array
    {
        return ['ok' => false, 'errors' => ['not_reversible']]; // a reporter reply reopens a timeout close
    }

    /** @return list<int> */
    private function eligibleIds(): array
    {
        return array_map('intval', array_column(BugReportService::listEligibleForReporterTimeout(self::DAYS), 'bug_id'));
    }
}
