<?php

namespace App\Operations\Inapp;

use App\Models\{BugReport, BugReportComment};
use App\Operations\PopOperationService;
use App\Operations\Strategies\{InappReportFollowupCommentStrategy, InappReportResolveAfterDeployStrategy, InappReportTriageStrategy};
use Illuminate\Support\Facades\DB;
use Throwable;

/** Historical authorship from native successful receipts, never wording, status claims or caller flags. */
final class InappSupportCommentOrigin
{
    /** @param iterable<BugReportComment> $comments @return array<int,true> */
    public static function ids(BugReport $bug, iterable $comments): array
    {
        return array_fill_keys(array_keys(self::receipts($bug, $comments)), true);
    }

    /** Internal validated origin for lifecycle guards; never expose native receipt data in an API response.
     * @param iterable<BugReportComment> $comments @return array<int,array{operation:string,parameters:array,receipt:array}>
     */
    public static function receipts(BugReport $bug, iterable $comments): array
    {
        $models = [];
        foreach ($comments as $comment) {
            if ((int) $comment->bug_report_id === (int) $bug->id)
                $models[(int) $comment->id] = $comment;
        }
        $trusted = [];
        $strategies = [InappReportFollowupCommentStrategy::OPERATION => InappReportFollowupCommentStrategy::class,
            InappReportTriageStrategy::OPERATION => InappReportTriageStrategy::class,
            InappReportResolveAfterDeployStrategy::OPERATION => InappReportResolveAfterDeployStrategy::class];
        // Bounded batch queries, not one provenance query per comment. A crowded or unavailable batch fails closed.
        foreach (array_chunk($models, 100, true) as $chunk) {
            try {
                $rows = DB::table('pop_operation_requests as r')->join('pop_execution_records as e', 'e.operation_id', '=', 'r.id')
                    ->whereIn('r.operation_id', array_keys($strategies))->where('r.parameters->bug_id', (int) $bug->id)
                    ->where('r.parameters->campus_id', (int) $bug->getAttribute('CampusID'))
                    ->where('e.phase', 'execute')->where('e.result', 'succeeded')->whereIn('e.payload->comment_id', array_keys($chunk))
                    ->select(['r.id', 'r.operation_id', 'r.strategy_id', 'r.parameters', 'r.parameters_hash', 'e.payload', 'e.execution_id'])
                    ->limit(2 * count($chunk) + 1)->get();
                if ($rows->count() > 2 * count($chunk)) continue;
                $origins = [];
                foreach ($rows as $row) {
                    $receipt = json_decode((string) $row->payload, true, 512, JSON_THROW_ON_ERROR);
                    $id = $receipt['comment_id'] ?? null;
                    if (is_int($id) && isset($chunk[$id])) $origins[$id][] = [$row, $receipt];
                }
                foreach ($origins as $id => $records) {
                    if (count($records) !== 1) continue;
                    [$row, $receipt] = $records[0];
                    $p = json_decode((string) $row->parameters, true, 512, JSON_THROW_ON_ERROR);
                    if (!is_array($p) || !is_string($p['public_reply'] ?? null)) continue;
                    $comment = $chunk[$id]; $hash = hash('sha256', (string) $comment->body);
                    if ($row->strategy_id !== ($strategies[$row->operation_id] ?? null)
                        || !hash_equals((string) $row->parameters_hash, hash('sha256', json_encode(PopOperationService::canonicalParameters($p), JSON_THROW_ON_ERROR)))
                        || ($p['bug_id'] ?? null) !== (int) $bug->id || ($p['campus_id'] ?? null) !== (int) $bug->getAttribute('CampusID')
                        || !is_string($p['github_issue_url'] ?? null)
                        || preg_match('#^https://github\.com/'.preg_quote((string) config('pop_inapp.github_repo'), '#').'/issues/[1-9][0-9]*$#D', $p['github_issue_url']) !== 1
                        || !hash_equals($hash, hash('sha256', $p['public_reply'])) || ($receipt['ok'] ?? null) !== true
                        || ($receipt['operation_id'] ?? null) !== $row->id || ($receipt['execution_id'] ?? null) !== $row->execution_id
                        || ($receipt['strategy'] ?? null) !== $row->strategy_id || ($receipt['bug_id'] ?? null) !== (int) $bug->id
                        || ($receipt['comment_id'] ?? null) !== (int) $comment->id || ($receipt['author_id'] ?? null) !== (int) $comment->author_user_id) continue;
                    $op = $row->operation_id;
                    if ($op === InappReportFollowupCommentStrategy::OPERATION
                        && (!in_array($p['expected_status'] ?? null, ['triaged', 'in_progress'], true) || ($receipt['status'] ?? null) !== $p['expected_status'])) continue;
                    if ($op === InappReportResolveAfterDeployStrategy::OPERATION && !in_array($receipt['status'] ?? null, ['resolved', 'closed'], true)) continue;
                    if ($op === InappReportTriageStrategy::OPERATION) {
                        $kind = $p['disposition'] ?? InappReportTriageStrategy::DISPOSITION;
                        if (!in_array($kind, ['bug', ...InappStandingPolicy::LOGGED_DISPOSITIONS], true)
                            || ($receipt['from_status'] ?? null) !== 'new' || ($receipt['disposition'] ?? null) !== $kind
                            || ($receipt['status'] ?? null) !== ($kind === 'bug' ? 'triaged' : 'closed')) continue;
                    }
                    // Native bug-triage receipts predate the reply hash; their immutable parameters and exact comment bind the body.
                    if (!($op === InappReportTriageStrategy::OPERATION && ($receipt['disposition'] ?? null) === 'bug')
                        && ($receipt['reply_sha256'] ?? null) !== $hash) continue;
                    $trusted[$id] = ['operation' => $op, 'parameters' => $p, 'receipt' => $receipt];
                }
            } catch (Throwable) { /* Missing/corrupt/late provenance never turns a human reply into support. */ }
        }
        return $trusted;
    }
}
