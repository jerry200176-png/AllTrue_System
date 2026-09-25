<?php

namespace App\Console\Commands;

use App\Models\User;
use App\Services\BugReportService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

/**
 * Manual operational capability (not auto-scheduled by default).
 * Owner: Founder / CTO Agent. Cadence: weekly dry-run, then apply.
 * See docs/sop/BUG_REPORTER_TIMEOUT.md
 */
class CloseStaleResolvedBugsCommand extends Command
{
    protected $signature = 'bugs:close-stale-resolved
                            {--days=7 : Calendar days after resolved before timeout}
                            {--dry-run : List eligible bugs without changing status}
                            {--reviewed-ids= : Comma-separated eligible IDs individually reviewed for regression signals (required for apply)}
                            {--actor= : User id for status log (default: first super_admin type S)}';

    protected $description = 'Close resolved in-app bugs after reporter-verify timeout (Evidence Contract). Manual by default.';

    public function handle(): int
    {
        $days = max(1, (int) $this->option('days'));
        $dryRun = (bool) $this->option('dry-run');
        $actorOpt = $this->option('actor');

        if ($actorOpt !== null && $actorOpt !== '') {
            $actorId = (int) $actorOpt;
        } else {
            $raw = User::query()->where('type', 'S')->orderByRaw('id asc')->value('id');
            $actorId = $raw !== null ? (int) $raw : 0;
        }

        if ($actorId <= 0) {
            $this->error('No actor user id (pass --actor= or ensure a type=S user exists)');
            return self::FAILURE;
        }

        $eligible = BugReportService::listEligibleForReporterTimeout($days);
        $eligibleIds = array_map('intval', array_column($eligible, 'bug_id'));
        $reviewedIds = [];
        if (!$dryRun) {
            $rawReviewed = trim((string) $this->option('reviewed-ids'));
            if (!preg_match('/^[0-9]+(?:,[0-9]+)*$/', $rawReviewed)) {
                $this->error('Apply requires --reviewed-ids=ID[,ID] after individual regression-signal review');
                return self::FAILURE;
            }
            $reviewedIds = array_map('intval', explode(',', $rawReviewed));
            if (count($reviewedIds) !== count(array_unique($reviewedIds)) || array_diff($reviewedIds, $eligibleIds)) {
                $this->error('Every reviewed ID must be unique and currently eligible; no status was changed');
                return self::FAILURE;
            }
        }
        $this->info(($dryRun ? '[dry-run] ' : '') . 'eligible=' . count($eligible) . " days={$days} actor={$actorId}");

        $closed = 0;
        $skipped = 0;
        foreach ($eligible as $row) {
            $bugId = (int) $row['bug_id'];
            if (!$dryRun && !in_array($bugId, $reviewedIds, true)) {
                $this->line("bug #{$bugId}: awaiting_individual_review");
                $skipped++;
                continue;
            }
            $result = BugReportService::closeByReporterTimeout($bugId, $actorId, $dryRun, $days);
            $action = $result['action'];
            $this->line("bug #{$bugId}: {$action}");
            if ($result['ok'] && in_array($action, ['closed', 'would_close'], true)) {
                $closed++;
            } else {
                $skipped++;
            }
        }

        Log::info('bugs_close_stale_resolved', [
            'dry_run' => $dryRun,
            'days' => $days,
            'eligible' => count($eligible),
            'acted' => $closed,
            'skipped' => $skipped,
            'actor_user_id' => $actorId,
        ]);

        $this->info("done acted={$closed} skipped={$skipped}");
        return self::SUCCESS;
    }
}
