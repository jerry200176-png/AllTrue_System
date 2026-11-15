<?php

namespace App\Operations\Strategies;

use App\Models\BugReport;
use App\Models\BugReportComment;
use App\Models\BugReportStatusLog;
use App\Operations\Inapp\InappReportReplyGuard;
use App\Operations\Inapp\InappReportResolveEvidence;
use App\Operations\Inapp\InappStandingPolicy;
use App\Services\BugReportService;
use App\Services\BugReportWritebackEvidence;
use App\Services\BugSafetyScreen;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * ADR-POP-015 (a): move one `new` engineering-required bug to `triaged`, linked to its
 * GitHub issue, with one public receipt reply. Every check is a hard plan error, so a super_admin fallback approval cannot
 * skip one either. execute() rechecks report and review proof under the row lock, then makes the same two service calls as
 * the Phase A workflow (changeStatus with the `bug` disposition, addComment) in one transaction. The reply is the caller's
 * text only: nothing from the report is ever copied into it. Founder 2026-11-15 additionally permits the two logged kinds:
 * those use the existing closeAsLogged service and a fixed receipt, never a shipping claim or a bug-resolution claim.
 */
final class InappReportTriageStrategy
{
    public const OPERATION = 'inapp-report-triage';
    /** Legacy callers keep bug triage; only the two newly approved kinds may be logged. */
    public const DISPOSITION = 'bug';
    private const FROM = 'new';

    public function __construct(private readonly InappReportResolveEvidence $evidence) {}

    public function plan(array $p, array $context = []): array
    {
        $repo = preg_quote((string) config('pop_inapp.github_repo'), '/');
        $errors = [];
        $kind = $this->disposition($p);
        if ($kind === null) $errors[] = 'disposition_invalid';
        foreach (['bug_id', 'campus_id'] as $key) if (!is_int($p[$key] ?? null) || $p[$key] <= 0) $errors[] = $key . '_invalid';
        if (!is_string($p['request_id'] ?? null) || preg_match('/^[a-z0-9][a-z0-9-]{2,63}$/', $p['request_id']) !== 1) $errors[] = 'request_id_invalid';
        $url = $p['github_issue_url'] ?? null;
        $issue = is_string($url) && preg_match('/^https:\/\/github\.com\/' . $repo . '\/issues\/([1-9][0-9]{0,9})$/', $url, $m) === 1 ? (int) $m[1] : null;
        if ($issue === null) $errors[] = 'github_issue_url_invalid';
        if (!is_string($p['public_reply'] ?? null)) $errors[] = 'public_reply_invalid';
        elseif ($issue !== null) array_push($errors, ...InappReportReplyGuard::violations($p['public_reply'], $url, false));
        $proof = $p['writeback_proof'] ?? null;
        // Key order is not significant (a JSON column may store {proof, captured_at}); exactly these two string fields are.
        if (!is_array($proof) || count($proof) !== 2 || !is_string($proof['captured_at'] ?? null) || !is_string($proof['proof'] ?? null)) $errors[] = 'writeback_proof_invalid';
        if ($errors !== []) return $this->result($errors, $p);
        $bugId = $p['bug_id'];

        $bug = BugReport::query()->whereKey($bugId)->first();
        if (!$bug) return $this->result(['report_missing'], $p);
        $status = (string) $bug->getRawOriginal('status');
        if ((int) $bug->getAttribute('CampusID') !== $p['campus_id']) $errors[] = 'report_outside_campus';
        // A report that is already triaged (or further) is refused, never re-answered: one receipt reply per report.
        if ($status !== self::FROM) $errors[] = 'status_not_new';
        $priorKind = BugReportService::latestDispositionWithKind($bugId)['kind'] ?? null;
        if ($priorKind !== null && $priorKind !== $kind) $errors[] = 'classification_change_requires_review';
        if ($kind !== self::DISPOSITION) {
            if ($p['public_reply'] !== InappReportReplyGuard::loggedReceipt($url)) $errors[] = 'logged_receipt_mismatch';
            array_push($errors, ...InappReportReplyGuard::loggedReportViolations($bug, InappStandingPolicy::plannedAttributionUserId($context)));
        }
        // Automation never writes to a report the safety screen flags (its text or the reporter's own comments); a person handles it
        // in the app. A comment that arrives after this plan makes the write-back proof stale, which execute() re-checks under the lock.
        $own = BugReportComment::query()->where('bug_report_id', $bugId)->where('author_user_id', $bug->getAttribute('reporter_user_id'))->pluck('body')->all();
        if (BugSafetyScreen::screenReport($bug->title, $bug->description, $own)['flagged']) $errors[] = 'safety_review_required';
        if ($errors !== []) return $this->result($errors, $p, $status);

        // Remote evidence before the proof check, so no report row is locked while waiting on the network.
        if (!$this->evidence->issueOwnsReport($issue, $bugId, true)) $errors[] = 'issue_source_ref_untrusted';
        // The review proof binds the report, comments, attachments, status logs and evidence as they were read.
        if ($errors === [] && !DB::transaction(fn (): bool => BugReportWritebackEvidence::verify($bug, $proof['captured_at'], $proof['proof']))) {
            $errors[] = 'writeback_proof_stale';
        }

        return $this->result($errors, $p, $status);
    }

    public function execute(array $plan, array $context): array
    {
        if (!($plan['ok'] ?? false)) throw new RuntimeException('inapp_triage_plan_not_ready');
        $request = DB::table('pop_operation_requests')->where('id', (string) $context['operation_id'])->first();
        $p = json_decode((string) $request?->parameters, true, 512, JSON_THROW_ON_ERROR);
        $bugId = (int) $p['bug_id'];
        $kind = $this->disposition($p);
        $commentId = null;
        $closeId = null;
        try {
            if ($kind === null || $kind !== ($plan['disposition'] ?? null)) throw new RuntimeException('inapp_triage_disposition_changed');
            $actor = InappStandingPolicy::attributionUserId($request);
            DB::transaction(function () use ($p, $kind, $bugId, $actor, $context, &$commentId, &$closeId): void {
                $bug = BugReport::query()->whereKey($bugId)->lockForUpdate()->first();
                InappStandingPolicy::assertKillSwitchesOpen((string) $context['operation_id']);
                if (!$bug || (int) $bug->getAttribute('CampusID') !== (int) $p['campus_id']) throw new RuntimeException('inapp_triage_report_outside_campus');
                if ((string) $bug->getRawOriginal('status') !== self::FROM) throw new RuntimeException('inapp_triage_status_changed');
                if (!BugReportWritebackEvidence::verify($bug, (string) $p['writeback_proof']['captured_at'], (string) $p['writeback_proof']['proof'])) {
                    throw new RuntimeException('inapp_triage_writeback_proof_stale');
                }
                $priorKind = BugReportService::latestDispositionWithKind($bugId)['kind'] ?? null;
                if ($priorKind !== null && $priorKind !== $kind) throw new RuntimeException('inapp_triage_classification_change_requires_review');
                if ($kind !== self::DISPOSITION) {
                    $errors = InappReportReplyGuard::loggedReportViolations($bug, $actor);
                    if ($p['public_reply'] !== InappReportReplyGuard::loggedReceipt($p['github_issue_url'])) $errors[] = 'logged_receipt_mismatch';
                    if ($errors !== []) throw new RuntimeException('inapp_triage_' . $errors[0]);
                    $result = BugReportService::closeAsLogged($bugId, $actor, $kind, $p['github_issue_url'], $p['public_reply']);
                    if ($result['ok'] !== true || ($result['action'] ?? null) !== 'closed') throw new RuntimeException('inapp_triage_logged_close_rejected');
                    $commentId = BugReportComment::query()->where('bug_report_id', $bugId)->where('is_internal_note', false)->max('id');
                    $closeId = BugReportStatusLog::query()->where('bug_report_id', $bugId)->where('to_status', 'closed')->max('id');

                    return;
                }
                $result = BugReportService::changeStatus($bugId, $actor, 'triaged', 'pop ' . self::OPERATION . ' ' . $context['operation_id'], [
                    'disposition' => self::DISPOSITION,
                    'github_issue_url' => (string) $p['github_issue_url'],
                ]);
                if ($result['ok'] !== true) {
                    throw new RuntimeException('inapp_triage_rejected_' . preg_replace('/[^a-z_]/', '', (string) ($result['code'] ?? 'unknown')));
                }
                $commentId = BugReportService::addComment($bugId, $actor, (string) $p['public_reply'], false)->id;
            });
        } catch (RuntimeException $e) { // nothing was written: the transaction rolled back
            $code = $e->getMessage();

            return ['ok' => false, 'errors' => [str_starts_with($code, 'inapp_') ? $code : 'inapp_triage_failed'], 'bug_id' => $bugId];
        }

        $receipt = ['ok' => true, 'bug_id' => $bugId, 'from_status' => self::FROM, 'status' => $plan['target_status'],
            'disposition' => $kind, 'comment_id' => (int) $commentId, 'author_id' => $actor, 'github_issue' => $plan['github_issue'] ?? null];
        if ($kind !== self::DISPOSITION) $receipt += ['reply_sha256' => hash('sha256', (string) $p['public_reply']), 'logged_close_id' => (int) $closeId];

        return $receipt;
    }

    public function verify(array $plan, array $result): array
    {
        $bugId = (int) ($result['bug_id'] ?? 0);
        $linked = BugReportService::latestDispositionWithKind($bugId);
        $reply = BugReportComment::query()->whereKey((int) ($result['comment_id'] ?? 0))->where('bug_report_id', $bugId)
            ->where('is_internal_note', false)->where('author_user_id', (int) ($result['author_id'] ?? 0))->first();
        $triaged = ($result['ok'] ?? false) === true && in_array($plan['target_status'] ?? null, ['triaged', 'closed'], true)
            && BugReport::query()->whereKey($bugId)->value('status') === $plan['target_status']
            && ($linked['kind'] ?? null) === ($plan['disposition'] ?? null)
            && $reply !== null && hash('sha256', (string) $reply->body) === ($plan['reply_sha256'] ?? null)
            && is_int($result['github_issue'] ?? null)
            && str_ends_with((string) ($linked['github_issue_url'] ?? ''), '/issues/' . $result['github_issue']);

        return ['ok' => $triaged, 'errors' => $triaged ? [] : ['writeback_not_verified'], 'checks' => ['target_status', 'issue_linked', 'public_reply']];
    }

    public function rollback(array $snapshot, array $context): array
    {
        return ['ok' => false, 'errors' => ['not_reversible']]; // a sent reply stays; a mistaken triage is corrected by a new status change
    }

    /** IDs and hashes only: the plan is stored in the execution record. */
    private function result(array $errors, array $p, ?string $status = null): array
    {
        $errors = array_values(array_unique($errors));
        $reply = $p['public_reply'] ?? null;
        $url = $p['github_issue_url'] ?? null;
        $kind = $this->disposition($p);
        $target = $kind === null ? null : ($kind === self::DISPOSITION ? 'triaged' : 'closed');

        return ['ok' => $errors === [], 'errors' => $errors, 'bug_id' => is_int($p['bug_id'] ?? null) ? $p['bug_id'] : null,
            'from_status' => $status, 'disposition' => $kind, 'target_status' => $target,
            'reply_sha256' => is_string($reply) ? hash('sha256', $reply) : null, 'policy_blockers' => [],
            'github_issue' => is_string($url) && preg_match('/\/issues\/([1-9][0-9]{0,9})$/', $url, $m) === 1 ? (int) $m[1] : null,
            'digest' => hash('sha256', json_encode([self::OPERATION, $p['bug_id'] ?? null, $p['campus_id'] ?? null, $status, $kind, $target, $url,
                is_string($reply) ? hash('sha256', $reply) : null, is_array($p['writeback_proof'] ?? null) ? ($p['writeback_proof']['captured_at'] ?? null) : null,
                $errors], JSON_THROW_ON_ERROR))];
    }

    private function disposition(array $p): ?string
    {
        $kind = array_key_exists('disposition', $p) ? $p['disposition'] : self::DISPOSITION;

        return is_string($kind) && in_array($kind, [self::DISPOSITION, ...InappStandingPolicy::LOGGED_DISPOSITIONS], true) ? $kind : null;
    }
}
