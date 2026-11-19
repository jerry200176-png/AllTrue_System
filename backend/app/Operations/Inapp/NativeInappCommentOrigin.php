<?php

namespace App\Operations\Inapp;

use App\Models\{BugReport, BugReportComment, BugReportEvidence, BugReportStatusLog};
use App\Operations\PopOperationService;
use Carbon\Carbon;
use Illuminate\Support\Facades\{DB, Log};
use LogicException;
use Throwable;

/** Internal, co-committed authorship for native producers which do not have a POP execution receipt. */
final class NativeInappCommentOrigin
{
    public static function recordAck(BugReport $bug, BugReportComment $comment): void
    {
        self::record($bug, $comment, 'auto_intake_ack', []);
    }

    public static function recordTimeout(BugReport $bug, BugReportComment $comment, string $queue, ?int $days, string $note): void
    {
        $log = BugReportStatusLog::query()->where('bug_report_id', $bug->getKey())
            ->where('changed_by', $comment->getAttribute('author_user_id'))->where('to_status', 'closed')
            ->where('note', $note)->latest('id')->lockForUpdate()->firstOrFail();
        self::record($bug, $comment, 'reporter_timeout', [
            'queue' => $queue, 'window_days' => $days, 'status_log_id' => (int) $log->getKey(),
            'status_log_sha256' => self::logHash($log),
        ]);
    }

    private static function record(BugReport $bug, BugReportComment $comment, string $producer, array $context): void
    {
        if (DB::transactionLevel() < 1 || !$comment->wasRecentlyCreated
            || (int) $comment->getAttribute('bug_report_id') !== (int) $bug->getKey()
            || (bool) $comment->getAttribute('is_internal_note')) {
            throw new LogicException('Native comment proof must share its public comment transaction');
        }
        $metadata = ['version' => 1, 'producer' => $producer, 'bug_id' => (int) $bug->getKey(),
            'campus_id' => (int) $bug->getAttribute('CampusID'), 'comment_id' => (int) $comment->getKey(),
            'author_id' => (int) $comment->getAttribute('author_user_id'),
            'body_sha256' => hash('sha256', (string) $comment->getAttribute('body')), 'context' => $context];
        BugReportEvidence::query()->create([
            'bug_report_id' => (int) $bug->getKey(), 'evidence_type' => BugReportEvidence::TYPE_NATIVE_SUPPORT_COMMENT_ORIGIN,
            'source_ref' => self::reference((int) $comment->getKey(), $metadata),
            'verified_by' => (int) $comment->getAttribute('author_user_id'), 'verified_at' => $comment->getAttribute('created_at'),
            'metadata' => $metadata,
            'created_at' => Carbon::now(),
        ]);
    }

    /** @param iterable<BugReportComment> $comments @return array<int,true> */
    public static function ids(BugReport $bug, iterable $comments): array
    {
        $models = [];
        foreach ($comments as $comment) {
            if ((int) $comment->getAttribute('bug_report_id') === (int) $bug->getKey()) $models[(int) $comment->getKey()] = $comment;
        }
        $trusted = [];
        // One bounded native read can serve several POP batches; stay below SQLite's 999-bind floor.
        foreach (array_chunk($models, 500, true) as $chunk) {
            try {
                $rows = BugReportEvidence::query()->where('bug_report_id', $bug->getKey())
                    ->where('evidence_type', BugReportEvidence::TYPE_NATIVE_SUPPORT_COMMENT_ORIGIN)
                    ->whereIn('metadata->comment_id', array_keys($chunk))->limit(2 * count($chunk) + 1)->get();
                if ($rows->count() > 2 * count($chunk)) continue;
                if ($rows->isEmpty()) continue;
                $grouped = []; $logIds = [];
                foreach ($rows as $row) {
                    $m = $row->getAttribute('metadata'); $id = is_array($m) ? ($m['comment_id'] ?? null) : null;
                    if (!is_int($id) || !isset($chunk[$id])) continue;
                    $grouped[$id][] = [$row, $m];
                }
                foreach ($grouped as $records) {
                    if (count($records) === 1 && is_int($records[0][1]['context']['status_log_id'] ?? null))
                        $logIds[] = $records[0][1]['context']['status_log_id'];
                }
                $logs = $logIds === [] ? collect() : BugReportStatusLog::query()->where('bug_report_id', $bug->getKey())
                    ->whereIn('id', array_unique($logIds))->get()->keyBy('id');
                foreach ($grouped as $id => $records) {
                    if (count($records) !== 1) continue;
                    [$row, $m] = $records[0]; $comment = $chunk[$id];
                    $at = $row->getAttribute('verified_at'); $commentAt = $comment->getAttribute('created_at');
                    if (($m['version'] ?? null) !== 1 || ($m['bug_id'] ?? null) !== (int) $bug->getKey()
                        || ($m['campus_id'] ?? null) !== (int) $bug->getAttribute('CampusID')
                        || ($m['author_id'] ?? null) !== (int) $comment->getAttribute('author_user_id')
                        || (int) $row->getAttribute('verified_by') !== $m['author_id']
                        || !is_array($m['context'] ?? null) || $row->getAttribute('source_ref') !== self::reference($id, $m)
                        || $row->getAttribute('production_revision') !== null || $row->getAttribute('deploy_run_id') !== null
                        || !($at instanceof Carbon) || !($commentAt instanceof Carbon) || !$at->equalTo($commentAt)
                        || !is_string($m['body_sha256'] ?? null)
                        || !hash_equals(hash('sha256', (string) $comment->getAttribute('body')), $m['body_sha256'])) continue;
                    $context = $m['context'];
                    if (($m['producer'] ?? null) === 'auto_intake_ack' && $context === []) { $trusted[$id] = true; continue; }
                    if (($m['producer'] ?? null) !== 'reporter_timeout') continue;
                    $queue = $context['queue'] ?? null; $days = $context['window_days'] ?? null;
                    if (!in_array($queue, ['awaiting_reporter', 'resolved', 'legacy_resolved'], true)
                        || ($queue === 'legacy_resolved' ? $days !== null : (!is_int($days) || $days < 7))) continue;
                    $log = $logs->get($context['status_log_id'] ?? 0);
                    if (!($log instanceof BugReportStatusLog) || (int) $log->getAttribute('changed_by') !== $m['author_id']
                        || $log->getAttribute('from_status') !== ($queue === 'awaiting_reporter' ? 'triaged' : 'resolved')
                        || $log->getAttribute('to_status') !== 'closed' || !is_string($context['status_log_sha256'] ?? null)
                        || !hash_equals(self::logHash($log), $context['status_log_sha256'])) continue;
                    $trusted[$id] = true;
                }
            } catch (Throwable $error) {
                // Keep failure observable without logging comment content, metadata or raw exception messages.
                Log::warning('Native in-app origin proof could not be read', [
                    'bug_report_id' => (int) $bug->getKey(), 'exception_class' => $error::class,
                ]);
            }
        }
        return $trusted;
    }

    private static function logHash(BugReportStatusLog $log): string
    {
        // Hash the stored representation so a reader's display timezone cannot rewrite historical origin.
        return hash('sha256', json_encode([(int) $log->getKey(), (int) $log->getAttribute('bug_report_id'),
            (int) $log->getAttribute('changed_by'), $log->getAttribute('from_status'), $log->getAttribute('to_status'),
            $log->getAttribute('note'), $log->getRawOriginal('created_at')], JSON_THROW_ON_ERROR));
    }

    /** Consistency binding, not a signature or an approval; reuse the existing canonical parameter encoding. */
    private static function reference(int $commentId, array $metadata): string
    {
        return 'native_support_comment:' . $commentId . ':'
            . hash('sha256', json_encode(PopOperationService::canonicalParameters($metadata), JSON_THROW_ON_ERROR));
    }
}
