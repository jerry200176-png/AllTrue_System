<?php

namespace App\Operations\Strategies;

use App\Models\{BugReport, BugReportComment};
use App\Operations\Inapp\{InappReportReplyGuard, InappReportResolveEvidence, InappStandingPolicy};
use App\Services\{BugReportService, BugReportWritebackEvidence, BugSafetyScreen};
use Illuminate\Support\Facades\DB;
use RuntimeException;

/** Founder 2026-11-17: a substantive support answer, including a reporter's follow-up, without a status change. */
final class InappReportFollowupCommentStrategy
{
    public const OPERATION = 'inapp-report-followup-comment';
    private const OPEN = ['triaged', 'in_progress'];

    public function __construct(private readonly InappReportResolveEvidence $evidence) {}

    public function plan(array $p, array $context = []): array
    {
        $errors = [];
        foreach (['bug_id', 'campus_id'] as $key) if (!is_int($p[$key] ?? null) || $p[$key] < 1) $errors[] = $key.'_invalid';
        if (!is_string($p['request_id'] ?? null) || preg_match('/^[a-z0-9][a-z0-9-]{2,63}$/', $p['request_id']) !== 1) $errors[] = 'request_id_invalid';
        if (!in_array($p['expected_status'] ?? null, self::OPEN, true)) $errors[] = 'expected_status_invalid';
        $url = $p['github_issue_url'] ?? null;
        $repo = preg_quote((string) config('pop_inapp.github_repo'), '/');
        $issue = is_string($url) && preg_match('/^https:\/\/github\.com\/'.$repo.'\/issues\/([1-9][0-9]{0,9})$/', $url, $m) === 1 ? (int) $m[1] : null;
        if ($issue === null) $errors[] = 'github_issue_url_invalid';
        if (!is_string($p['public_reply'] ?? null)) $errors[] = 'public_reply_invalid';
        elseif ($issue !== null) array_push($errors, ...InappReportReplyGuard::violations($p['public_reply'], $url, false));
        $proof = $p['writeback_proof'] ?? null;
        if (!is_array($proof) || count($proof) !== 2 || !is_string($proof['captured_at'] ?? null) || !is_string($proof['proof'] ?? null)) $errors[] = 'writeback_proof_invalid';
        $author = InappStandingPolicy::plannedAttributionUserId($context);
        if ($author === null) $errors[] = 'reply_author_unverified';
        if ($errors !== []) return $this->result($errors, $p, $author);
        $bug = BugReport::find($p['bug_id']);
        if (!$bug) return $this->result(['report_missing'], $p, $author);
        $errors = $this->localErrors($bug, $p, $author);
        if ($errors === [] && !$this->evidence->issueOwnsReport($issue, (int) $bug->id, true)) $errors[] = 'issue_source_ref_untrusted';
        // The network check precedes this transaction; every source child is then read under the existing proof locks.
        if ($errors === [] && !DB::transaction(fn () => BugReportWritebackEvidence::verify($bug, $proof['captured_at'], $proof['proof'])))
            $errors[] = 'writeback_proof_stale';
        return $this->result($errors, $p, $author);
    }

    public function execute(array $plan, array $context): array
    {
        $request = DB::table('pop_operation_requests')->where('id', (string) ($context['operation_id'] ?? ''))->first();
        if (!$request || $request->operation_id !== self::OPERATION || $request->strategy_id !== self::class)
            throw new RuntimeException('inapp_followup_request_untrusted');
        $p = json_decode((string) $request->parameters, true, 512, JSON_THROW_ON_ERROR);
        $fresh = $this->plan($p, $context);
        if (($plan['ok'] ?? false) !== true || !$fresh['ok'] || !hash_equals((string) ($plan['digest'] ?? ''), $fresh['digest']))
            return ['ok' => false, 'errors' => ['inapp_followup_plan_changed']];
        $actor = InappStandingPolicy::attributionUserId($request);
        try {
            $id = DB::transaction(function () use ($p, $actor, $context): int {
                InappStandingPolicy::assertKillSwitchesOpen((string) $context['operation_id']);
                $bug = BugReport::whereKey($p['bug_id'])->lockForUpdate()->first();
                if (!$bug || $this->localErrors($bug, $p, $actor) !== []) throw new RuntimeException('inapp_followup_source_changed');
                if (!BugReportWritebackEvidence::verify($bug, $p['writeback_proof']['captured_at'], $p['writeback_proof']['proof']))
                    throw new RuntimeException('inapp_followup_writeback_proof_stale');
                return (int) BugReportService::addComment((int) $bug->id, $actor, $p['public_reply'], false)->id;
            });
        } catch (RuntimeException $e) {
            return ['ok' => false, 'errors' => [str_starts_with($e->getMessage(), 'inapp_') ? $e->getMessage() : 'inapp_followup_failed']];
        }
        return ['ok' => true, 'bug_id' => $p['bug_id'], 'campus_id' => $p['campus_id'], 'status' => $p['expected_status'],
            'comment_id' => $id, 'author_id' => $actor, 'reply_sha256' => hash('sha256', $p['public_reply'])];
    }

    public function verify(array $plan, array $result): array
    {
        $reply = BugReportComment::whereKey((int) ($result['comment_id'] ?? 0))->where('bug_report_id', $plan['bug_id'])
            ->where('author_user_id', (int) ($result['author_id'] ?? 0))->where('is_internal_note', false)->first();
        $ok = ($result['ok'] ?? false) === true && $reply !== null && $result['author_id'] === $plan['author_id']
            && $result['status'] === $plan['expected_status'] && BugReport::whereKey($plan['bug_id'])->value('status') === $plan['expected_status']
            && hash_equals($plan['reply_sha256'], hash('sha256', (string) $reply->body));
        return ['ok' => $ok, 'errors' => $ok ? [] : ['writeback_not_verified'], 'checks' => ['same_open_status', 'public_reply', 'support_author']];
    }

    public function rollback(array $snapshot, array $context): array { return ['ok' => false, 'errors' => ['not_reversible']]; }

    private function localErrors(BugReport $bug, array $p, int $author): array
    {
        $errors = [];
        if ((int) $bug->getAttribute('CampusID') !== $p['campus_id']) $errors[] = 'report_outside_campus';
        $status = (string) $bug->getRawOriginal('status');
        if (!in_array($status, self::OPEN, true)) $errors[] = 'status_not_open';
        if ($status !== $p['expected_status']) $errors[] = 'status_mismatch';
        if ((int) $bug->reporter_user_id === $author) $errors[] = 'reply_would_be_reporters_own';
        if (BugReportComment::where('bug_report_id', $bug->id)->where('is_internal_note', false)->where('body', $p['public_reply'])->exists())
            $errors[] = 'reply_already_posted';
        $own = BugReportComment::where('bug_report_id', $bug->id)->where('author_user_id', $bug->reporter_user_id)->pluck('body')->all();
        if (BugSafetyScreen::screenReport($bug->title, $bug->description, $own)['flagged']) $errors[] = 'safety_review_required';
        return $errors;
    }

    private function result(array $errors, array $p, ?int $author): array
    {
        $hash = is_string($p['public_reply'] ?? null) ? hash('sha256', $p['public_reply']) : null;
        return ['ok' => $errors === [], 'errors' => array_values(array_unique($errors)), 'policy_blockers' => [],
            'bug_id' => $p['bug_id'] ?? null, 'expected_status' => $p['expected_status'] ?? null, 'author_id' => $author,
            'reply_sha256' => $hash, 'reply_text' => $p['public_reply'] ?? null,
            'digest' => hash('sha256', json_encode([self::OPERATION, $p['bug_id'] ?? null, $p['campus_id'] ?? null,
                $p['expected_status'] ?? null, $author, $p['github_issue_url'] ?? null, $hash, $p['writeback_proof'] ?? null], JSON_THROW_ON_ERROR))];
    }
}
