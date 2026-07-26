<?php

namespace App\Console\Commands;

use App\Services\ParentBinding\ParentBindingReportService;
use Illuminate\Console\Command;

/** php artisan parent-binding:report --days=7|--missing-contact [--campus=] --format=json */
class ParentBindingReportCommand extends Command
{
    protected $signature = 'parent-binding:report {--days=7} {--campus=} {--format=json} {--missing-contact}';
    protected $description = 'Read-only parent binding attempt / missing-contact report (PB-00).';

    public function handle(ParentBindingReportService $reports): int
    {
        $format = strtolower((string) $this->option('format'));
        if (!in_array($format, ['json', 'table'], true)) {
            $this->error('Invalid --format');

            return self::FAILURE;
        }
        $campusId = null;
        if (($opt = $this->option('campus')) !== null && $opt !== '') {
            if (!is_numeric($opt) || (int) $opt < 1) {
                $this->error('Invalid --campus');

                return self::FAILURE;
            }
            $campusId = (int) $opt;
        }
        if ($this->option('missing-contact')) {
            $report = $reports->missingContactReport($campusId);
        } else {
            $days = $this->option('days');
            if (!is_numeric($days) || (int) $days < 1 || (int) $days > 90) {
                $this->error('Invalid --days (1-90)');

                return self::FAILURE;
            }
            $report = $reports->attemptReport((int) $days, $campusId);
        }
        $this->line(json_encode($report, ($format === 'json' ? JSON_PRETTY_PRINT : 0) | JSON_UNESCAPED_UNICODE));

        return self::SUCCESS;
    }
}
