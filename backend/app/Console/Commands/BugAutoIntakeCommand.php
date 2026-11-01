<?php

namespace App\Console\Commands;

use App\Models\BugReport;
use App\Models\BugReportComment;
use App\Models\User;
use App\Services\BugFamily;
use App\Services\BugReportService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Hourly auto-intake (bug-auto-intake.yml, Founder GO "6A" 2026-10-07).
 * --candidates prints IDs-only JSON of `new` reports, plus `reopened`: reports whose latest status change is the
 * reporter's 「問題仍存在」 (resolved → in_progress), so the workflow reopens their closed issue (#4721); --ack moves one report new → triaged
 * with its GitHub issue link and posts the standard acknowledgement. Idempotent:
 * a report that is no longer `new` is skipped, and the ack is never posted twice.
 */
class BugAutoIntakeCommand extends Command
{
    public const ACK_TEXT = '收到，我們正在查，查到原因會再回覆你。';

    public const REOPEN_WINDOW_DAYS = 14;

    public const REOPEN_CAP = 200;

    protected $signature = 'bugs:auto-intake
                            {--candidates : Print new reports as JSON (IDs, campus, severity, created_at, family only)}
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
        $actor = self::actorId();
        $cutoff = now()->subMinutes(max(0, (int) $this->option('min-age')));
        $rows = BugReport::query()->where('status', 'new')->where('created_at', '<=', $cutoff)
            ->whereNotExists(fn ($q) => $q->selectRaw('1')->from('bug_report_comments as c')
                ->whereColumn('c.bug_report_id', 'bug_reports.id')->where('c.body', self::ACK_TEXT)
                ->where('c.author_user_id', $actor))
            ->orderBy('id')->limit($limit)
            ->get(['id', 'CampusID', 'severity', 'created_at', 'page_key', 'title', 'description'])
            ->map(fn (BugReport $b) => [
                'bug_id' => (int) $b->id,
                'campus_id' => (int) $b->CampusID,
                'severity' => (string) $b->severity,
                'created_at' => optional($b->created_at)->toIso8601String(),
                // Family name only (config/bug_families.php); the text it was derived from never leaves production.
                'family' => BugFamily::classify($b->page_key, $b->title . ' ' . $b->description),
            ])->values()->all();
        $latest = DB::table('bug_report_status_logs')->selectRaw('MAX(id) AS id')->groupBy('bug_report_id');
        $reopened = DB::table('bug_report_status_logs as l')->joinSub($latest, 'm', 'm.id', '=', 'l.id')
            ->join('bug_reports as b', 'b.id', '=', 'l.bug_report_id')
            ->where('b.status', 'in_progress')->where('l.from_status', 'resolved')->where('l.to_status', 'in_progress')
            ->whereColumn('l.changed_by', 'b.reporter_user_id')
            // A reopen stays listed until the report changes again, so a fixed page would starve later ones (Codex on
            // #4739): read every reopen of the last 14 days (the hourly run sees each one ~336 times), capped at 200;
            // the workflow warns at the cap.
            ->where('l.created_at', '>=', now()->subDays(self::REOPEN_WINDOW_DAYS))
            ->orderBy('l.id')->limit(self::REOPEN_CAP)->get(['l.bug_report_id', 'l.id', 'l.created_at'])
            ->map(fn ($r) => ['bug_id' => (int) $r->bug_report_id, 'log_id' => (int) $r->id,
                'at' => \Illuminate\Support\Carbon::parse($r->created_at)->toIso8601String()])->values()->all();
        $this->line(json_encode(['candidates' => $rows, 'reopened' => $reopened], JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR));
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
        $actor = self::actorId();
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
            // Only the automation's own ack counts (a reporter quoting the text must not suppress intake).
            if (BugReportComment::query()->where('bug_report_id', $bugId)->where('body', self::ACK_TEXT)
                ->where('author_user_id', $actor)->exists()) {
                return ['bug_id' => $bugId, 'ack' => 'skipped', 'issue' => $issueUrl];
            }
            BugReportService::addComment($bugId, $actor, self::ACK_TEXT, false);
            return ['bug_id' => $bugId, 'status' => $status, 'ack' => 'posted', 'issue' => $issueUrl];
        });
        $this->line(json_encode($result));
        return self::SUCCESS;
    }


    /** The automation sender: the first super admin (type S). Also the ack marker's author. */
    private static function actorId(): int
    {
        return (int) (User::query()->where('type', 'S')->orderBy('id')->value('id') ?? 0);
    }
}
