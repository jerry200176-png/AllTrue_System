<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * PRD-A (2026-04-18) — Attendance status reconciliation backfill.
 *
 * Fixes historical inconsistencies where ClassSession.Status is stale relative
 * to the latest active StudentSignIn.Status (e.g. ClassSession.Status='absent'
 * but an active StudentSignIn.Status='present' exists). Only records within
 * the configurable lookback window are auto-fixed; older records are logged.
 *
 * Autonomous execution contract:
 *   - `--dry-run` always prints the affected-row report.
 *   - When affected < auto_threshold AND all within lookback window,
 *     the command auto-executes the UPDATE without human confirmation.
 *   - Older-than-window rows are written to storage/logs/backfill-report-{date}.csv
 *     and are NOT auto-corrected.
 */
class BackfillSessionStatus extends Command
{
    protected $signature = 'attendance:reconcile-session-status
                            {--dry-run : Preview only, do not update}
                            {--days=7 : Lookback window in days for auto-fix}
                            {--auto-threshold=500 : Max affected rows allowed for auto-execute}
                            {--force : Force execute regardless of thresholds}';

    protected $description = 'Reconcile ClassSession.Status against latest active StudentSignIn.Status (PRD-A backfill).';

    private const SIGNIN_TO_SESSION_STATUS = [
        'present' => 'attended',
        'late'    => 'late',
        'absent'  => 'absent',
        'leave'   => 'leave',
        'excused' => 'leave',
    ];

    public function handle(): int
    {
        $dryRun        = (bool) $this->option('dry-run');
        $lookbackDays  = max(1, (int) $this->option('days'));
        $autoThreshold = max(1, (int) $this->option('auto-threshold'));
        $force         = (bool) $this->option('force');

        $today = Carbon::now()->toDateString();
        $windowStart = Carbon::now()->subDays($lookbackDays)->toDateString();

        if (!DB::getSchemaBuilder()->hasTable('ClassSession') || !DB::getSchemaBuilder()->hasTable('StudentSingIn')) {
            $this->warn('Required tables missing; skipping.');
            return self::SUCCESS;
        }

        // Find (ClassSessionID, latest active sign-in status) pairs.
        // Use MAX(id) per ClassSessionID to pick latest active sign-in
        // when multiple active rows somehow coexist.
        $latestSignIns = DB::table('StudentSingIn as si')
            ->join(DB::raw('(SELECT ClassSessionID, MAX(id) AS max_id FROM `StudentSingIn` WHERE VoidedAt IS NULL AND ClassSessionID IS NOT NULL GROUP BY ClassSessionID) si_latest'), 'si.id', '=', 'si_latest.max_id')
            ->select('si.ClassSessionID', 'si.Status as si_status');

        $mismatched = DB::table('ClassSession as cs')
            ->joinSub($latestSignIns, 'latest_si', function ($join) {
                $join->on('latest_si.ClassSessionID', '=', 'cs.id');
            })
            ->select([
                'cs.id',
                'cs.SessionDate',
                'cs.Status as cs_status',
                'latest_si.si_status',
            ])
            ->get();

        $candidates = [];
        foreach ($mismatched as $row) {
            $siStatus = strtolower((string) ($row->si_status ?? ''));
            $csStatus = strtolower((string) ($row->cs_status ?? ''));
            $mapped = self::SIGNIN_TO_SESSION_STATUS[$siStatus] ?? null;

            if ($mapped === null) {
                continue;
            }
            if ($csStatus === $mapped) {
                continue;
            }
            // Only reconcile the specific bug: cs.Status ∈ {scheduled, absent}
            // while an active sign-in says present/late. Avoid clobbering
            // cancelled/leave/leave_adjusted (which have their own cascade logic).
            if (!in_array($csStatus, ['scheduled', 'absent'], true)) {
                continue;
            }
            // Only attended/late override makes sense for the reported bug.
            // An active absent sign-in with cs.scheduled is already handled
            // (both map to absent). A leave sign-in with cs.scheduled would
            // have gone through the leave cascade path so skip here too.
            if (!in_array($mapped, ['attended', 'late'], true)) {
                continue;
            }

            $candidates[] = [
                'id'         => (int) $row->id,
                'date'       => substr((string) $row->SessionDate, 0, 10),
                'old_status' => $csStatus,
                'new_status' => $mapped,
            ];
        }

        $total = count($candidates);
        $this->info("Found {$total} ClassSession rows whose Status is stale vs the latest active StudentSignIn.");

        $logDir = storage_path('logs');
        if (!is_dir($logDir)) {
            @mkdir($logDir, 0775, true);
        }
        $csvPath = $logDir . '/backfill-report-' . $today . '.csv';
        $fp = fopen($csvPath, 'w');
        fputcsv($fp, ['class_session_id', 'session_date', 'old_status', 'new_status', 'within_window', 'auto_applied']);

        $inWindow = [];
        $outOfWindow = [];
        foreach ($candidates as $c) {
            $within = $c['date'] >= $windowStart && $c['date'] <= $today;
            if ($within) {
                $inWindow[] = $c;
            } else {
                $outOfWindow[] = $c;
            }
        }

        // Auto-apply rule (per PRD-A §13):
        //   - Only records within the lookback window are corrected.
        //   - In-window count must be <= auto_threshold.
        //   - Out-of-window records are logged to CSV but never auto-fixed.
        $shouldAutoApply = !$dryRun && ($force || (count($inWindow) > 0 && count($inWindow) <= $autoThreshold));

        $applied = 0;
        if ($shouldAutoApply) {
            DB::transaction(function () use ($inWindow, &$applied) {
                foreach ($inWindow as $c) {
                    $ok = DB::table('ClassSession')
                        ->where('id', $c['id'])
                        ->where('Status', $c['old_status'])
                        ->update(['Status' => $c['new_status']]);
                    $applied += (int) $ok;
                }
            });
        }

        foreach ($inWindow as $c) {
            fputcsv($fp, [$c['id'], $c['date'], $c['old_status'], $c['new_status'], '1', $shouldAutoApply ? '1' : '0']);
        }
        foreach ($outOfWindow as $c) {
            fputcsv($fp, [$c['id'], $c['date'], $c['old_status'], $c['new_status'], '0', '0']);
        }
        fclose($fp);

        $this->info('Report written: ' . $csvPath);
        $this->info('In-window (≤ ' . $lookbackDays . ' days): ' . count($inWindow));
        $this->info('Out-of-window (older, never auto-fixed): ' . count($outOfWindow));

        if ($dryRun) {
            $this->warn('DRY-RUN: no updates performed.');
        } elseif ($shouldAutoApply) {
            $this->info("Auto-applied updates: {$applied}");
        } else {
            $this->warn('Auto-apply skipped (use --force to override; thresholds: auto-threshold=' . $autoThreshold . ', days=' . $lookbackDays . ').');
        }

        Log::info('attendance.reconcile_session_status', [
            'dry_run'       => $dryRun,
            'days'          => $lookbackDays,
            'in_window'     => count($inWindow),
            'out_of_window' => count($outOfWindow),
            'applied'       => $applied,
            'report'        => $csvPath,
        ]);

        return self::SUCCESS;
    }
}
