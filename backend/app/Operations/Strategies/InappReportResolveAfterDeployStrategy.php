<?php

namespace App\Operations\Strategies;

use App\Models\BugReport;
use App\Operations\Inapp\InappReportReplyGuard;
use App\Operations\Inapp\InappReportResolveEvidence;
use App\Operations\Inapp\InappStandingPolicy;
use App\Services\BugReportService;
use App\Services\BugReportWritebackEvidence;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * ADR-POP-015 (b): resolve one triaged/in_progress report once its fix is verified in production. Every evidence check is a
 * hard plan error, so a super_admin fallback approval cannot skip one either. execute() rechecks report, review proof and
 * deployed SHA under the row lock, then posts the reply and walks triaged→in_progress→resolved in one transaction.
 */
final class InappReportResolveAfterDeployStrategy
{
    public const OPERATION = 'inapp-report-resolve-after-deploy';
    private const FROM = ['triaged', 'in_progress'];

    public function __construct(private readonly InappReportResolveEvidence $evidence) {}

    public function plan(array $p): array
    {
        $repo = preg_quote((string) config('pop_inapp.github_repo'), '/');
        $errors = [];
        foreach (['bug_id', 'campus_id'] as $key) if (!is_int($p[$key] ?? null) || $p[$key] <= 0) $errors[] = $key . '_invalid';
        foreach (['request_id' => '/^[a-z0-9][a-z0-9-]{2,63}$/', 'fix_commit_sha' => '/^[0-9a-f]{40}$/', 'deploy_run_id' => '/^[1-9][0-9]{0,19}$/',
            'smoke_result_ref' => '/^(?:run:|https:\/\/github\.com\/' . $repo . '\/actions\/runs\/)[1-9][0-9]{0,19}(?:\/job\/[1-9][0-9]{0,19})?$/'] as $key => $pattern) {
            if (!is_string($p[$key] ?? null) || preg_match($pattern, $p[$key]) !== 1) $errors[] = $key . '_invalid';
        }
        $url = $p['github_issue_url'] ?? null;
        $issue = is_string($url) && preg_match('/^https:\/\/github\.com\/' . $repo . '\/issues\/([1-9][0-9]{0,9})$/', $url, $m) === 1 ? (int) $m[1] : null;
        if ($issue === null) $errors[] = 'github_issue_url_invalid';
        if (!is_string($p['public_reply'] ?? null)) $errors[] = 'public_reply_invalid';
        elseif ($issue !== null) array_push($errors, ...InappReportReplyGuard::violations($p['public_reply'], $url, true));
        $proof = $p['writeback_proof'] ?? null;
        if (!is_array($proof) || array_keys($proof) !== ['captured_at', 'proof'] || !is_string($proof['captured_at']) || !is_string($proof['proof'])) $errors[] = 'writeback_proof_invalid';
        if ($errors !== []) return $this->result($errors, $p);
        [$bugId, $fix, $run] = [$p['bug_id'], $p['fix_commit_sha'], $p['deploy_run_id']];
        preg_match('/(?:^run:|\/runs\/)([1-9][0-9]{0,19})(?:\/job\/([1-9][0-9]{0,19}))?/', $p['smoke_result_ref'], $smoke);

        $bug = BugReport::query()->whereKey($bugId)->first();
        if (!$bug) return $this->result(['report_missing'], $p);
        $status = (string) $bug->getRawOriginal('status');
        if ((int) $bug->getAttribute('CampusID') !== $p['campus_id']) $errors[] = 'report_outside_campus';
        if (!in_array($status, self::FROM, true)) $errors[] = 'status_not_resolvable';
        if ($errors !== []) return $this->result($errors, $p, $status);

        // Remote evidence before the proof check, so no report row is locked while waiting on the network.
        if (!$this->evidence->issueOwnsReport($issue, $bugId)) $errors[] = 'issue_source_ref_untrusted';
        $deploy = $this->evidence->successfulDeploy($run);
        $head = $deploy['head'] ?? null;
        if ($deploy === null) $errors[] = 'deploy_run_not_successful';
        elseif (!in_array($head, $this->evidence->deployedShas(), true)) $errors[] = 'deploy_run_not_in_production';
        elseif (!$this->evidence->isAncestor($fix, $head)) $errors[] = 'fix_not_in_deployed_revision';
        if (!$this->evidence->fixLinkedTo($fix, $issue, $bugId)) $errors[] = 'fix_not_linked_to_issue';
        if ($deploy !== null && !$this->evidence->smokePassedAfter($smoke[1], $smoke[2] ?? null, $deploy['completed_at'])) $errors[] = 'smoke_not_passed_after_deploy';
        if (!$this->evidence->healthOk()) $errors[] = 'health_not_ok';
        // The review proof binds the report, comments, attachments, status logs and evidence as they were read.
        if ($errors === [] && !DB::transaction(fn (): bool => BugReportWritebackEvidence::verify($bug, $proof['captured_at'], $proof['proof']))) {
            $errors[] = 'writeback_proof_stale';
        }

        return $this->result($errors, $p, $status, $head);
    }

    public function execute(array $plan, array $context): array
    {
        if (!($plan['ok'] ?? false)) throw new RuntimeException('inapp_resolve_plan_not_ready');
        $request = DB::table('pop_operation_requests')->where('id', (string) $context['operation_id'])->first();
        $p = json_decode((string) $request?->parameters, true, 512, JSON_THROW_ON_ERROR);
        $bugId = (int) $p['bug_id'];
        try {
            $actor = InappStandingPolicy::attributionUserId($request);
            $from = DB::transaction(function () use ($p, $plan, $bugId, $actor, $context): string {
                $bug = BugReport::query()->whereKey($bugId)->lockForUpdate()->first();
                InappStandingPolicy::assertKillSwitchesOpen((string) $context['operation_id']);
                if (!$bug || (int) $bug->getAttribute('CampusID') !== (int) $p['campus_id']) throw new RuntimeException('inapp_resolve_report_outside_campus');
                $from = (string) $bug->getRawOriginal('status');
                if ($from !== ($plan['from_status'] ?? null) || !in_array($from, self::FROM, true)) throw new RuntimeException('inapp_resolve_status_changed');
                if (!BugReportWritebackEvidence::verify($bug, (string) $p['writeback_proof']['captured_at'], (string) $p['writeback_proof']['proof'])) {
                    throw new RuntimeException('inapp_resolve_writeback_proof_stale');
                }
                if (!in_array($plan['deployed_sha'] ?? null, $this->evidence->deployedShas(), true)) throw new RuntimeException('inapp_resolve_deployment_changed');
                $note = 'pop ' . self::OPERATION . ' ' . $context['operation_id'];
                if ($from === 'triaged') $this->step(BugReportService::changeStatus($bugId, $actor, 'in_progress', $note));
                // Posted unconditionally: the resolve check reads the latest public reply, so an older copy never counts.
                BugReportService::addComment($bugId, $actor, (string) $p['public_reply'], false);
                $this->step(BugReportService::changeStatus($bugId, $actor, 'resolved', $note, [
                    'production_revision' => $plan['deployed_sha'],
                    'deploy_run_id' => (string) $p['deploy_run_id'],
                    'require_both_outcomes_reply' => true,
                ]));

                return $from;
            });
        } catch (RuntimeException $e) { // nothing was written: the transaction rolled back
            $code = $e->getMessage();

            return ['ok' => false, 'errors' => [str_starts_with($code, 'inapp_') ? $code : 'inapp_resolve_failed'], 'bug_id' => $bugId];
        }

        return ['ok' => true, 'bug_id' => $bugId, 'from_status' => $from, 'status' => 'resolved', 'production_revision' => $plan['deployed_sha'],
            'deploy_run_id' => (string) $p['deploy_run_id'], 'smoke_result_ref' => (string) $p['smoke_result_ref']];
    }

    public function verify(array $plan, array $result): array
    {
        $resolved = ($result['ok'] ?? false) === true
            && BugReport::query()->whereKey((int) ($result['bug_id'] ?? 0))->value('status') === 'resolved';

        return ['ok' => $resolved, 'errors' => $resolved ? [] : ['not_resolved'], 'checks' => ['resolved']];
    }

    public function rollback(array $snapshot, array $context): array
    {
        return ['ok' => false, 'errors' => ['not_reversible']]; // a sent reply stays; a mistaken resolve is reopened by a new status change
    }

    /** @param array{ok?:bool, code?:string} $result */
    private function step(array $result): void
    {
        if (($result['ok'] ?? false) !== true) {
            throw new RuntimeException('inapp_resolve_rejected_' . preg_replace('/[^a-z_]/', '', (string) ($result['code'] ?? 'unknown')));
        }
    }

    /** IDs and hashes only: the plan is stored in the execution record. */
    private function result(array $errors, array $p, ?string $status = null, ?string $deployedSha = null): array
    {
        $errors = array_values(array_unique($errors));
        $reply = $p['public_reply'] ?? null;

        return ['ok' => $errors === [], 'errors' => $errors, 'bug_id' => is_int($p['bug_id'] ?? null) ? $p['bug_id'] : null,
            'from_status' => $status, 'deployed_sha' => $deployedSha, 'policy_blockers' => [],
            'digest' => hash('sha256', json_encode([self::OPERATION, $p['bug_id'] ?? null, $p['campus_id'] ?? null, $status, $deployedSha,
                $p['fix_commit_sha'] ?? null, $p['deploy_run_id'] ?? null, $p['smoke_result_ref'] ?? null, $p['github_issue_url'] ?? null,
                is_string($reply) ? hash('sha256', $reply) : null, is_array($p['writeback_proof'] ?? null) ? ($p['writeback_proof']['captured_at'] ?? null) : null,
                $errors], JSON_THROW_ON_ERROR))];
    }
}
