<?php

namespace App\Console\Commands;

use App\Services\AttendanceLearningRecordIntegrityService;
use Illuminate\Console\Command;

/**
 * PII-safe full scan for the attendance → LearningRecord boundary.
 */
class LearningRecordIntegrityScan extends Command
{
    protected $signature = 'learning-records:integrity-scan
                            {--campus_id= : Optional Student.CampusID filter}
                            {--limit=500 : Maximum rows returned per category}';

    protected $description = 'Scan attendance-linked LearningRecord integrity without writing data';

    public function handle(AttendanceLearningRecordIntegrityService $service): int
    {
        $campusId = $this->option('campus_id') !== null ? (int) $this->option('campus_id') : null;
        $limit = max(1, (int) $this->option('limit'));
        $this->line(json_encode($service->scan($campusId, $limit), JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));

        return self::SUCCESS;
    }
}
