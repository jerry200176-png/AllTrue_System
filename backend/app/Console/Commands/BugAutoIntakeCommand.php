<?php

namespace App\Console\Commands;

use App\Models\BugReport;
use App\Models\BugReportComment;
use App\Models\BugReportStatusLog;
use App\Models\User;
use App\Services\BugReportService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Hourly auto-intake (bug-auto-intake.yml, Founder GO "6A" 2026-10-07).
 * --candidates prints IDs-only JSON of `new` reports; --ack moves one report new → triaged
 * with its GitHub issue link and posts the standard acknowledgement. Idempotent:
 * a report that is no longer `new` is skipped, and the ack is never posted twice.
 */
class BugAutoIntakeCommand extends Command
{
    public const ACK_TEXT = '收到，我們正在查，查到原因會再回覆你。';

    public const INTAKE_NOTE = 'auto-intake; GitHub issue=';

    protected $signature = 'bugs:auto-intake
                            {--candidates : Print new reports as JSON (IDs, campus, severity, page, created_at only)}
                            {--limit=10 : Max reports per --candidates}
                            {--min-age=5 : Minutes a report must exist before intake (lets attachments finish)}
                            {--ack : Triage one report and post the acknowledgement}
                            {--bug-id= : Report ID for --ack}
                            {--issue-url= : GitHub issue URL for --ack (this repository)}';

    protected $description = 'Auto-intake for new in-app reports: list candidates or acknowledge one (idempotent)';

    public function handle(): int
    {
        if ($this->option('candidates')) {
            return $this->list();
        }
        if ($this->option('ack')) {
            return $this->ack();
        }
        $this->error('Pass --candidates or --ack');
        return self::FAILURE;
    }

    private function list(): int
    {
        $limit = max(1, min(50, (int) $this->option('limit')));
        $cutoff = now()->subMinutes(max(0, (int) $this->option('min-age')));
        $rows = BugReport::query()->where('status', 'new')->where('created_at', '<=', $cutoff)
            ->orderBy('id')->limit($limit)
            ->get(['id', 'CampusID', 'severity', 'page_key', 'created_at'])
            ->map(fn (BugReport $b) => [
                'bug_id' => (int) $b->id,
                'campus_id' => (int) $b->CampusID,
                'severity' => (string) $b->severity,
                'page_key' => preg_replace('/[^a-z0-9_-]/i', '', (string) $b->page_key),
                'created_at' => optional($b->created_at)->toIso8601String(),
            ])->values()->all();
        $this->line(json_encode(['candidates' => $rows], JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR));
        return self::SUCCESS;
    }

    private function ack(): int
    {
        $bugId = (int) $this->option('bug-id');
        $issueUrl = (string) $this->option('issue-url');
        // The workflow pins the URL to its own repository before calling this.
        if ($bugId <= 0 || !preg_match('#^https://github\.com/[A-Za-z0-9_.-]+/[A-Za-z0-9_.-]+/issues/[0-9]+$#', $issueUrl)) {
            $this->error('ack needs --bug-id and a GitHub issue URL');
            return self::FAILURE;
        }
        $bug = BugReport::query()->find($bugId);
        if (!$bug) {
            $this->error('bug not found: ' . $bugId);
            return self::FAILURE;
        }
        $actor = (int) (User::query()->where('type', 'S')->orderBy('id')->value('id') ?? 0);
        if ($actor <= 0) {
            $this->error('No type=S actor user');
            return self::FAILURE;
        }
        // Row lock + one transaction: the status change and the ack commit together, and an
        // overlapping run (or a manual Phase-A) waits instead of double-posting.
        $result = DB::transaction(function () use ($bugId, $issueUrl, $actor) {
            $locked = BugReport::query()->whereKey($bugId)->lockForUpdate()->first();
            $from = (string) $locked->getAttribute('status');
            $ackPosted = BugReportComment::query()->where('bug_report_id', $bugId)->where('body', self::ACK_TEXT)->exists();
            if ($from === 'new') {
                // No product disposition here: intake has not classified the report yet, and
                // Phase-A restates a real one later. The issue link lives in the status note.
                $t = BugReportService::changeStatus($bugId, $actor, 'triaged', self::INTAKE_NOTE . $issueUrl);
                if (!$t['ok']) {
                    throw new \RuntimeException(json_encode($t));
                }
            } elseif (!$this->intakeLogged($bugId)) {
                return ['bug_id' => $bugId, 'skipped' => 'status_' . $from];
            }
            // Retry completes a half-done intake; the exact ack text is the idempotency key.
            if (!$ackPosted) {
                BugReportService::addComment($bugId, $actor, self::ACK_TEXT, false);
            }
            return ['bug_id' => $bugId, 'from' => $from, 'ack' => $ackPosted ? 'skipped' : 'posted', 'issue' => $issueUrl];
        });
        $this->line(json_encode($result));
        return self::SUCCESS;
    }

    private function intakeLogged(int $bugId): bool
    {
        return BugReportStatusLog::query()->where('bug_report_id', $bugId)
            ->where('note', 'like', self::INTAKE_NOTE . '%')->exists();
    }
}
