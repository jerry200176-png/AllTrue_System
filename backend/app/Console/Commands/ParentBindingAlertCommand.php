<?php

namespace App\Console\Commands;

use App\Services\ParentBinding\BindingService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

/**
 * W31 P3-2: Automated binding health check + alerting.
 *
 * php artisan parent-binding:alert
 *
 * Checks binding failure rate, orphan count, and cross-campus conflicts.
 * Logs alerts when thresholds are exceeded. Designed for cron/scheduler integration.
 */
class ParentBindingAlertCommand extends Command
{
    protected $signature = 'parent-binding:alert {--days=1} {--campus=} {--failure-threshold=0.4} {--orphan-threshold=10}';
    protected $description = 'Check binding health and emit alerts on anomalies (W31 P3-2).';

    public function __construct(private readonly BindingService $bindingService)
    {
        parent::__construct();
    }

    public function handle(): int
    {
        $days = max(1, min(90, (int) $this->option('days')));
        $campusId = null;
        if (($opt = $this->option('campus')) !== null && $opt !== '') {
            $campusId = is_numeric($opt) ? (int) $opt : null;
        }
        $failureThreshold = (float) $this->option('failure-threshold');
        $orphanThreshold = (int) $this->option('orphan-threshold');

        $metrics = $this->bindingService->metrics(campusId: $campusId, days: $days);
        $alerts = [];

        // Check failure rate
        $failureRate = $metrics['attempts']['success_rate'] !== null
            ? 1.0 - $metrics['attempts']['success_rate']
            : null;

        if ($failureRate !== null && $failureRate > $failureThreshold) {
            $alerts[] = sprintf(
                'HIGH_FAILURE_RATE: %.1f%% failure rate (threshold: %.1f%%) over %d day(s)',
                $failureRate * 100,
                $failureThreshold * 100,
                $days,
            );
        }

        // Check orphan count
        if ($metrics['orphan_bindings'] > $orphanThreshold) {
            $alerts[] = sprintf(
                'ORPHAN_THRESHOLD_EXCEEDED: %d orphans (threshold: %d)',
                $metrics['orphan_bindings'],
                $orphanThreshold,
            );
        }

        // Check cross-campus conflicts
        if ($metrics['cross_campus_conflicts'] > 0) {
            $alerts[] = sprintf(
                'CROSS_CAMPUS_CONFLICTS: %d line user(s) with multi-campus bindings',
                $metrics['cross_campus_conflicts'],
            );
        }

        // Output
        $this->line(json_encode($metrics, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));

        if (empty($alerts)) {
            $this->info('✅ No alerts triggered.');
            Log::info('parent_binding.alert.check', ['alerts' => 0, 'metrics' => $metrics]);

            return self::SUCCESS;
        }

        foreach ($alerts as $alert) {
            $this->warn("⚠️ {$alert}");
        }

        Log::warning('parent_binding.alert.triggered', [
            'alert_count' => count($alerts),
            'alerts' => $alerts,
            'metrics' => $metrics,
        ]);

        $this->info("Logged " . count($alerts) . " alert(s).");

        return self::SUCCESS;
    }
}
