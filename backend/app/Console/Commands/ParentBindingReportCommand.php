<?php

namespace App\Console\Commands;

use App\Services\ParentBinding\ParentBindingReportService;
use Illuminate\Console\Command;

/**
 * Read-only PB-00 reports.
 *   php artisan parent-binding:report --days=7 --format=json
 *   php artisan parent-binding:report --missing-contact --format=json
 *   php artisan parent-binding:report --missing-contact --campus=15 --format=json
 */
class ParentBindingReportCommand extends Command
{
    protected $signature = 'parent-binding:report
        {--days=7 : Lookback days (1-90); ignored with --missing-contact}
        {--campus= : Optional campus_id}
        {--format=json : json|table}
        {--missing-contact : Active students missing contact phone aggregates}';

    protected $description = 'Read-only parent binding attempt / missing-contact report (PB-00).';

    public function handle(ParentBindingReportService $reports): int
    {
        $format = strtolower((string) $this->option('format'));
        if (!in_array($format, ['json', 'table'], true)) {
            $this->error('Invalid --format: use json or table.');

            return self::FAILURE;
        }
        $campusId = null;
        $campusOpt = $this->option('campus');
        if ($campusOpt !== null && $campusOpt !== '') {
            if (!is_numeric($campusOpt) || (int) $campusOpt < 1) {
                $this->error('Invalid --campus: positive integer required.');

                return self::FAILURE;
            }
            $campusId = (int) $campusOpt;
        }

        if ($this->option('missing-contact')) {
            $report = $reports->missingContactReport($campusId);
        } else {
            $daysRaw = $this->option('days');
            if (!is_numeric($daysRaw) || (int) $daysRaw < 1 || (int) $daysRaw > 90) {
                $this->error('Invalid --days: integer 1-90 required.');

                return self::FAILURE;
            }
            $report = $reports->attemptReport((int) $daysRaw, $campusId);
        }

        if ($format === 'json') {
            $this->line(json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));

            return self::SUCCESS;
        }
        $this->line(json_encode($report, JSON_UNESCAPED_UNICODE));

        return self::SUCCESS;
    }
}
