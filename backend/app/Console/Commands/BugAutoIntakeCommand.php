<?php

namespace App\Console\Commands;

use App\Models\BugReport;
use App\Models\BugReportComment;
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

    protected $signature = 'bugs:auto-intake
                            {--candidates : Print new reports as JSON (IDs, campus, severity, created_at only)}
                            {--limit=50 : Max reports per --candidates (unacknowledged only, so the backlog drains)}
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
            ->whereNotExists(fn ($q) => $q->selectRaw('1')->from('bug_report_comments as c')
                ->whereColumn('c.bug_report_id', 'bug_reports.id')->where('c.body', self::ACK_TEXT))
            ->orderBy('id')->limit($limit)
            ->get(['id', 'CampusID', 'severity', 'created_at'])
            ->map(fn (BugReport $b) => [
                'bug_id' => (int) $b->id,
                'campus_id' => (int) $b->CampusID,
                'severity' => (string) $b->severity,
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
        // Row lock: an overlapping run or a manual Phase-A waits, then re-reads; the exact ack text is
        // the idempotency key.
        $result = DB::transaction(function () use ($bugId, $issueUrl, $actor) {
            $locked = BugReport::query()->whereKey($bugId)->lockForUpdate()->first();
            $status = (string) $locked->getAttribute('status');
            if ($status !== 'new') {
                return ['bug_id' => $bugId, 'skipped' => 'status_' . $status];
            }
            if (BugReportComment::query()->where('bug_report_id', $bugId)->where('body', self::ACK_TEXT)->exists()) {
                return ['bug_id' => $bugId, 'ack' => 'skipped', 'issue' => $issueUrl];
            }
            BugReportService::addComment($bugId, $actor, self::ACK_TEXT, false);
            return ['bug_id' => $bugId, 'status' => $status, 'ack' => 'posted', 'issue' => $issueUrl];
        });
        $this->line(json_encode($result));
        return self::SUCCESS;
    }

}
