<?php

namespace App\Operations\Inapp;

use App\Models\BugReport;
use App\Models\BugReportComment;
use App\Models\BugReportEvidence;
use App\Models\BugReportStatusLog;
use App\Operations\Strategies\InappReportFollowupCommentStrategy;
use App\Services\BugReportService;

/** Reuse an existing substantive reply without creating a second public comment. */
final class InappReopenedSuggestion
{
    /** @param array{id:int,kind:string} $close @return array{reopen_id:int,reply_id:int}|null */
    public static function answeredCycle(BugReport $bug, array $close, string $issueUrl, string $reply): ?array
    {
        if ($bug->getRawOriginal('status') !== 'triaged') return null;
        $id = (int) $bug->getKey();
        $reporter = (int) $bug->reporter_user_id;
        $logs = BugReportStatusLog::query()->where('bug_report_id', $id)->latest('id')->limit(2)->get();
        [$reopen, $previous] = [$logs->get(0), $logs->get(1)];
        if (!$reopen || !$previous || (int) $previous->id !== $close['id'] || $reopen->from_status !== 'closed'
            || $reopen->to_status !== 'triaged' || $reopen->note !== 'reopened_by_reporter_reply'
            || (int) $reopen->changed_by !== $reporter) return null;
        // A delivered earlier cycle could have been reopened because the problem still exists.
        $prefix = 'logged_ship:' . $close['id'] . ':';
        if (BugReportEvidence::query()->where('bug_report_id', $id)->pluck('source_ref')
            ->contains(static fn ($ref) => is_string($ref) && str_starts_with($ref, $prefix))) return null;

        $comments = BugReportComment::query()->where('bug_report_id', $id)->where('is_internal_note', false)->latest('id')->limit(2)->get();
        [$answer, $question] = [$comments->get(0), $comments->get(1)];
        if (!$answer || !$question || $answer->body !== $reply || (int) $question->author_user_id !== $reporter
            || !$previous->created_at || !$reopen->created_at
            || $question->created_at < $previous->created_at || $question->created_at > $reopen->created_at
            || $answer->created_at < $reopen->created_at
            || preg_match(BugReportService::AWAITING_REPORTER_QUESTION_PATTERN, $reply) !== 0) return null;
        $support = InappSupportCommentOrigin::receipts($bug, $comments);
        $receipt = $support[(int) $answer->id] ?? null;
        if (isset($support[(int) $question->id]) || NativeInappCommentOrigin::ids($bug, [$question]) !== []
            || ($receipt['operation'] ?? null) !== InappReportFollowupCommentStrategy::OPERATION
            || ($receipt['parameters']['github_issue_url'] ?? null) !== $issueUrl
            || ($receipt['receipt']['status'] ?? null) !== 'triaged') return null;

        return ['reopen_id' => (int) $reopen->id, 'reply_id' => (int) $answer->id];
    }
}
