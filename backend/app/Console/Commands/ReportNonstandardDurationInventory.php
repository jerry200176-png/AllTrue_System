<?php

namespace App\Console\Commands;

use App\Services\Scheduling\NonstandardDurationInventoryReporter;
use Illuminate\Console\Command;

/**
 * RFC_NONSTANDARD_SESSION_DURATION_BILLING Phase 0A — read-only inventory (no writes).
 * Safety contract lives in NonstandardDurationInventoryReporter. There are deliberately
 * no repair/apply/fix options on this command.
 */
class ReportNonstandardDurationInventory extends Command
{
    protected $signature = 'sessions:report-nonstandard-duration
                            {--campus= : Limit to one campus (Student.CampusID)}
                            {--student-class= : Limit to a single StudentClass.ID}
                            {--limit=2000 : Max courses to scan}
                            {--details : Include limited identifiers (StudentClassID/ClassSessionID/CampusID only, never student name/phone/RFID)}
                            {--json : Emit JSON (default is a human summary)}';

    protected $description = 'RFC non-standard-duration Phase 0A read-only inventory: '
        . 'per-course contract/schedule duration mismatch and minute-ledger adoption. Never writes.';

    public function handle(NonstandardDurationInventoryReporter $reporter): int
    {
        $campusOpt = $this->option('campus');
        $scOpt = $this->option('student-class');

        $report = $reporter->build(
            ($campusOpt !== null && $campusOpt !== '') ? (int) $campusOpt : null,
            ($scOpt !== null && $scOpt !== '') ? (int) $scOpt : null,
            max(1, (int) $this->option('limit')),
            (bool) $this->option('details')
        );

        if ($this->option('json')) {
            $this->line(json_encode($report, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));

            return self::SUCCESS;
        }

        $this->info('RFC non-standard-duration Phase 0A inventory (READ_ONLY=true)');
        $this->line('generated_at=' . $report['meta']['generated_at']
            . ' courses_scanned=' . $report['meta']['courses_scanned']);

        $b1 = $report['b1_contract_schedule_mismatch'];
        $this->line(sprintf(
            'B1 mismatch vs each course own contract: courses_with_SessionDuration=%d affected_courses=%d '
            . '| happened: sessions=%d courses=%d | planned: sessions=%d courses=%d',
            $b1['courses_with_session_duration_set'],
            $b1['affected_courses'],
            $b1['happened']['sessions'],
            $b1['happened']['courses'],
            $b1['planned']['sessions'],
            $b1['planned']['courses']
        ));

        $b2 = $report['b2_minute_ledger_adoption'];
        $this->line(sprintf(
            'B2 ledger adoption: rows_with_minutes=%d courses_with_minutes=%d courses_partial=%d '
            . 'reverse_net_minutes_nonzero=%d',
            $b2['ledger_rows_with_minutes_set'],
            $b2['courses_with_minutes_set'],
            $b2['courses_with_minutes_ne_per_session'],
            $b2['courses_with_reverse_net_minutes_nonzero']
        ));

        return self::SUCCESS;
    }
}
