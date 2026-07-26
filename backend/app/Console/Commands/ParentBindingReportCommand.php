<?php

namespace App\Console\Commands;

use App\Services\ParentBinding\ParentBindingReportService;
use Illuminate\Console\Command;

/**
 * Read-only: last-N-days parent binding attempt distribution (PB-00).
 *
 *   php artisan parent-binding:report --days=7 --format=json
 *   php artisan parent-binding:report --days=7 --campus=15 --format=json
 */
class ParentBindingReportCommand extends Command
{
    protected $signature = 'parent-binding:report
        {--days=7 : Lookback window in days (1-90)}
        {--campus= : Optional campus_id scope}
        {--format=table : Output format: table|json}';

    protected $description = 'Read-only parent binding attempt report (reason distribution, success rate).';

    public function handle(ParentBindingReportService $reports): int
    {
        $daysRaw = $this->option('days');
        if (!is_numeric($daysRaw) || (int) $daysRaw < 1 || (int) $daysRaw > 90) {
            $this->error('Invalid --days: must be an integer between 1 and 90.');
            return self::FAILURE;
        }
        $days = (int) $daysRaw;

        $campusOpt = $this->option('campus');
        $campusId = null;
        if ($campusOpt !== null && $campusOpt !== '') {
            if (!is_numeric($campusOpt) || (int) $campusOpt < 1) {
                $this->error('Invalid --campus: must be a positive integer campus_id.');
                return self::FAILURE;
            }
            $campusId = (int) $campusOpt;
        }

        $format = strtolower((string) $this->option('format'));
        if (!in_array($format, ['json', 'table'], true)) {
            $this->error('Invalid --format: use json or table.');
            return self::FAILURE;
        }

        $report = $reports->attemptReport($days, $campusId);

        if ($format === 'json') {
            $this->line(json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
            return self::SUCCESS;
        }

        $this->info("Parent binding report — last {$days} day(s) ({$report['timezone']})");
        $this->table(
            ['Metric', 'Value'],
            [
                ['status', $report['status']],
                ['observability_enabled', $report['observability_enabled'] ? 'true' : 'false'],
                ['table_ready', $report['table_ready'] ? 'true' : 'false'],
                ['total_attempts', $report['total_attempts']],
                ['success_count', $report['success_count']],
                ['failure_count', $report['failure_count']],
                ['noop_count', $report['noop_count']],
                ['success_rate', $report['success_rate'] === null ? 'n/a' : (string) $report['success_rate']],
            ]
        );

        if (!empty($report['by_reason_code'])) {
            $this->info('By reason_code:');
            $this->table(
                ['reason_code', 'count'],
                collect($report['by_reason_code'])->map(fn ($c, $k) => [$k, $c])->values()->all()
            );
        }

        return self::SUCCESS;
    }
}
