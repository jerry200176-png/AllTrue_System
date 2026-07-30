<?php

namespace App\Console\Commands;

use App\Services\Scheduling\NonstandardDurationInventoryReporter;
use Illuminate\Console\Command;

/**
 * RFC_NONSTANDARD_SESSION_DURATION_BILLING Phase 0A — read-only inventory (no writes).
 * See NonstandardDurationInventoryReporter for the safety contract this command relies on:
 * SELECT-only, no save()/update()/insert()/delete(), no side-effecting service calls.
 */
class ReportNonstandardDurationInventory extends Command
{
    protected $signature = 'sessions:report-nonstandard-duration
                            {--campus= : Limit to one campus (Student.CampusID)}
                            {--student-class= : Limit to a single StudentClass.ID}
                            {--limit=2000 : Max courses to scan}
                            {--details : Include limited per-course identifiers (StudentClassID/ClassSessionID/CampusID only, never student name/phone/RFID)}
                            {--json : Emit JSON (default is a human summary)}';

    protected $description = 'RFC non-standard-duration Phase 0A read-only inventory: '
        . 'contract/schedule mismatch, minute-ledger adoption, overage, RemainingMinutes '
        . 'consistency, CoursePackage exposure, mutable-default risk. Never writes.';

    public function handle(NonstandardDurationInventoryReporter $reporter): int
    {
        $campusOpt = $this->option('campus');
        $campusId = ($campusOpt !== null && $campusOpt !== '') ? (int) $campusOpt : null;

        $scOpt = $this->option('student-class');
        $studentClassId = ($scOpt !== null && $scOpt !== '') ? (int) $scOpt : null;

        $limit = max(1, (int) $this->option('limit'));
        $details = (bool) $this->option('details');

        $report = $reporter->build($campusId, $studentClassId, $limit, $details);

        if ($this->option('json')) {
            $this->line(json_encode($report, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));

            return self::SUCCESS;
        }

        $this->info('RFC non-standard-duration Phase 0A inventory (READ_ONLY=true)');
        $this->line('generated_at=' . $report['meta']['generated_at'] . ' courses_scanned=' . $report['meta']['courses_scanned']);
        $b1 = $report['b1_contract_schedule_mismatch'];
        $this->line(sprintf(
            'B1 mismatch: courses_with_SessionDuration=%d mismatched_sessions=%d affected_courses=%d',
            $b1['courses_with_session_duration_set'],
            $b1['mismatched_normal_sessions'],
            $b1['affected_courses']
        ));
        $b2 = $report['b2_minute_ledger_adoption'];
        $this->line(sprintf(
            'B2 ledger adoption: rows_with_minutes=%d courses_with_minutes=%d courses_partial=%d',
            $b2['ledger_rows_with_minutes_set'],
            $b2['courses_with_minutes_set'],
            $b2['courses_with_minutes_ne_per_session']
        ));
        $b3 = $report['b3_existing_overage'];
        $this->line(sprintf(
            'B3 overage: affected_courses=%d total_uncovered_minutes=%d',
            $b3['affected_course_count'],
            $b3['total_uncovered_minutes']
        ));
        $b4 = $report['b4_remaining_minutes_consistency'];
        $this->line(sprintf(
            'B4 RemainingMinutes: checked=%d mismatch=%d fractional=%d capped_zero_hidden_overage=%d',
            $b4['courses_checked'],
            $b4['persisted_vs_ledger_derived_mismatch_count'],
            $b4['fractional_balance_count'],
            $b4['capped_at_zero_with_hidden_overage_count']
        ));
        $b5 = $report['b5_course_package_exposure'];
        $this->line(sprintf(
            'B5 CoursePackage exposure: courses_in_package=%d packages_with_drift_risk=%d',
            $b5['nonstandard_or_partial_courses_in_package'],
            $b5['packages_with_drift_risk']
        ));
        $b6 = $report['b6_mutable_default_risk'];
        $this->line('B6 mutable-default: authoritative_setting_found=' . ($b6['authoritative_branch_or_system_setting_found'] ? 'true' : 'false'));

        return self::SUCCESS;
    }
}
