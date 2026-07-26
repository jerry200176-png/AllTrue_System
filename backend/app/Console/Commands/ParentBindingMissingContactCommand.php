<?php

namespace App\Console\Commands;

use App\Services\ParentBinding\ParentBindingReportService;
use Illuminate\Console\Command;

/**
 * Read-only: active students missing authoritative contact phone (PB-00).
 *
 *   php artisan parent-binding:missing-contact --format=json
 *   php artisan parent-binding:missing-contact --campus=15 --format=json
 */
class ParentBindingMissingContactCommand extends Command
{
    protected $signature = 'parent-binding:missing-contact
        {--campus= : Optional campus_id scope}
        {--format=table : Output format: table|json}';

    protected $description = 'Read-only aggregate of active students missing contact phone (parent_phone → Phone).';

    public function handle(ParentBindingReportService $reports): int
    {
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

        $report = $reports->missingContactReport($campusId);

        if ($format === 'json') {
            $this->line(json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
            return self::SUCCESS;
        }

        $this->info('Active students missing contact — ' . $report['authoritative_rule']);
        $this->table(
            ['campus_id', 'campus_name', 'active', 'missing', 'pct'],
            collect($report['campuses'])->map(fn ($r) => [
                $r['campus_id'],
                $r['campus_name'],
                $r['active_student_count'],
                $r['missing_contact_count'],
                $r['missing_contact_percentage'] === null ? 'n/a' : (string) $r['missing_contact_percentage'],
            ])->all()
        );

        return self::SUCCESS;
    }
}
